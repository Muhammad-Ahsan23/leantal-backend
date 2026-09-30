<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\Email;
use App\Models\GmailWatchState;
use App\Models\OAuthToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PRD Section 57 — "Receive and display replies... maintain
 * conversation context linked to the candidate record."
 *
 * Deliberately narrow scope: only messages from a sender whose email
 * matches an EXISTING Candidate (in the connected user's own company)
 * are stored. This is not a general inbox sync — we have no business
 * reading or storing someone's personal, non-candidate email, and PRD
 * never asks us to. Everything else in the mailbox is left untouched.
 */
class GmailSyncService
{
    public function __construct(protected GmailWatchService $watch) {}

    protected function ensureFreshToken(OAuthToken $token): string
    {
        if ($token->expires_at && $token->expires_at->isFuture()) {
            return $token->access_token;
        }

        if (!$token->refresh_token) {
            throw new \RuntimeException('Google connection expired — cannot sync.');
        }

        $oauth = app(GoogleCalendarOAuthService::class);
        $result = $oauth->refreshAccessToken($token->refresh_token);

        $token->update([
            'access_token' => $result['access_token'],
            'expires_at' => now()->addSeconds($result['expires_in'] ?? 3600),
        ]);

        return $result['access_token'];
    }

    /**
     * Entry point — called by GmailPushWebhookController when a Pub/Sub
     * notification arrives for this user's connected mailbox.
     */
    public function syncNewMessages(OAuthToken $token, string $connection): void
    {
        $watchState = GmailWatchState::on($connection)->where('user_id', $token->user_id)->first();

        if (!$watchState || !$watchState->history_id) {
            Log::warning('Gmail sync: no watch state / history_id for user', ['user_id' => $token->user_id]);
            return;
        }

        $accessToken = $this->ensureFreshToken($token);

        $response = Http::withToken($accessToken)
            ->get('https://gmail.googleapis.com/gmail/v1/users/me/history', [
                'startHistoryId' => $watchState->history_id,
                'historyTypes' => 'messageAdded',
            ]);

        if (!$response->successful()) {
            // historyId can go stale (e.g. too old, or mailbox history
            // truncated) — Gmail returns 404 in that case. Nothing we
            // can safely recover automatically; log for manual
            // re-registration via GmailWatchService::registerWatch().
            Log::warning('Gmail history.list failed', ['user_id' => $token->user_id, 'status' => $response->status()]);
            return;
        }

        $json = $response->json();
        $messageIds = [];

        foreach ($json['history'] ?? [] as $entry) {
            foreach ($entry['messagesAdded'] ?? [] as $added) {
                $labelIds = $added['message']['labelIds'] ?? [];
                // Only genuinely-incoming mail — skip anything in SENT
                // (the user's own outgoing messages also appear in
                // history, we don't want to re-import those as if they
                // were replies).
                if (in_array('INBOX', $labelIds, true) && !in_array('SENT', $labelIds, true)) {
                    $messageIds[] = $added['message']['id'];
                }
            }
        }

        foreach (array_unique($messageIds) as $messageId) {
            $this->processMessage($accessToken, $messageId, $token, $connection);
        }

        if (isset($json['historyId'])) {
            $watchState->update(['history_id' => $json['historyId']]);
        }
    }

    protected function processMessage(string $accessToken, string $messageId, OAuthToken $token, string $connection): void
    {
        $response = Http::withToken($accessToken)
            ->get("https://gmail.googleapis.com/gmail/v1/users/me/messages/{$messageId}", ['format' => 'full']);

        if (!$response->successful()) {
            Log::warning('Gmail message fetch failed', ['message_id' => $messageId]);
            return;
        }

        $message = $response->json();
        $headers = collect($message['payload']['headers'] ?? [])->keyBy(fn ($h) => strtolower($h['name']));

        $fromHeader = $headers->get('from')['value'] ?? '';
        $senderEmail = $this->extractEmailAddress($fromHeader);
        $subject = $headers->get('subject')['value'] ?? '(no subject)';

        if (!$senderEmail) {
            return;
        }

        // Company-scoped match — candidates aren't globally unique by
        // email, only within a company.
        $ownerUser = \App\Models\User::on($connection)->find($token->user_id);
        $candidate = Candidate::on($connection)
            ->where('company_id', $ownerUser->company_id)
            ->whereRaw('LOWER(email) = ?', [strtolower($senderEmail)])
            ->first();

        if (!$candidate) {
            return; // not from a known candidate — out of scope, skip entirely
        }

        // Avoid duplicate storage if this exact Gmail message was
        // somehow already processed (e.g. a retried Pub/Sub delivery).
        $alreadyStored = Email::on($connection)
            ->where('candidate_id', $candidate->id)
            ->where('thread_id', $message['threadId'] ?? null)
            ->where('subject', $subject)
            ->where('sent_at', now()->createFromTimestampMs((int) $message['internalDate']))
            ->exists();

        if ($alreadyStored) {
            return;
        }

        $body = $this->extractBody($message['payload'] ?? []);

        Email::on($connection)->create([
            'company_id' => $ownerUser->company_id,
            'user_id' => $token->user_id,
            'candidate_id' => $candidate->id,
            'direction' => 'inbound',
            'provider' => 'gmail',
            'thread_id' => $message['threadId'] ?? null,
            'subject' => $subject,
            'body' => $body,
            'sent_at' => now()->createFromTimestampMs((int) $message['internalDate']),
        ]);
    }

    /**
     * "John Doe <john@example.com>" -> "john@example.com"; also handles
     * a bare "john@example.com" with no display name.
     */
    protected function extractEmailAddress(string $fromHeader): ?string
    {
        if (preg_match('/<([^>]+)>/', $fromHeader, $matches)) {
            return trim($matches[1]);
        }

        $trimmed = trim($fromHeader);

        return filter_var($trimmed, FILTER_VALIDATE_EMAIL) ? $trimmed : null;
    }

    /**
     * Gmail messages are MIME multipart — recursively finds the
     * text/html part (preferred, since our own sent emails are HTML —
     * consistent rendering both directions) falling back to text/plain.
     * Gmail's body.data is base64url (RFC 4648 §5), not standard base64.
     */
    protected function extractBody(array $payload): string
    {
        if (isset($payload['body']['data']) && ($payload['mimeType'] ?? '') === 'text/html') {
            return $this->decodeBase64Url($payload['body']['data']);
        }

        $plainFallback = null;

        foreach ($payload['parts'] ?? [] as $part) {
            if (($part['mimeType'] ?? '') === 'text/html' && isset($part['body']['data'])) {
                return $this->decodeBase64Url($part['body']['data']);
            }
            if (($part['mimeType'] ?? '') === 'text/plain' && isset($part['body']['data'])) {
                $plainFallback = $this->decodeBase64Url($part['body']['data']);
            }
            // Nested multipart (e.g. multipart/alternative inside multipart/mixed)
            if (str_starts_with($part['mimeType'] ?? '', 'multipart/')) {
                $nested = $this->extractBody($part);
                if ($nested) {
                    return $nested;
                }
            }
        }

        if ($plainFallback) {
            return nl2br(e($plainFallback));
        }

        if (isset($payload['body']['data'])) {
            return $this->decodeBase64Url($payload['body']['data']);
        }

        return '';
    }

    protected function decodeBase64Url(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
