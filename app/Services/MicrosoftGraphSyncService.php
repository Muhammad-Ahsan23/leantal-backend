<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\Email;
use App\Models\OAuthToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PRD Section 57 — "Receive and display replies... maintain
 * conversation context linked to the candidate record."
 *
 * Simpler than Gmail's equivalent: Microsoft's notification payload
 * carries the CHANGED message's own ID directly (resourceData.id) —
 * no separate history.list() round-trip needed to discover what
 * changed, we can fetch the message immediately.
 *
 * Same deliberate scope limit as Gmail sync — only messages from a
 * sender matching an EXISTING Candidate are stored.
 */
class MicrosoftGraphSyncService
{
    public function __construct(protected MicrosoftOAuthService $oauth) {}

    protected function ensureFreshToken(OAuthToken $token): string
    {
        if ($token->expires_at && $token->expires_at->isFuture()) {
            return $token->access_token;
        }

        if (!$token->refresh_token) {
            throw new \RuntimeException('Microsoft connection expired — cannot sync.');
        }

        $result = $this->oauth->refreshAccessToken($token->refresh_token);

        $token->update([
            'access_token' => $result['access_token'],
            'expires_at' => now()->addSeconds($result['expires_in'] ?? 3600),
        ]);

        return $result['access_token'];
    }

    public function processMessage(OAuthToken $token, string $messageId, string $connection): void
    {
        $accessToken = $this->ensureFreshToken($token);

        $response = Http::withToken($accessToken)
            ->get("https://graph.microsoft.com/v1.0/me/messages/{$messageId}");

        if (!$response->successful()) {
            Log::warning('Microsoft Graph message fetch failed', ['message_id' => $messageId, 'status' => $response->status()]);
            return;
        }

        $message = $response->json();
        $senderEmail = $message['from']['emailAddress']['address'] ?? null;
        $subject = $message['subject'] ?? '(no subject)';

        if (!$senderEmail) {
            return;
        }

        $ownerUser = \App\Models\User::on($connection)->find($token->user_id);
        $candidate = Candidate::on($connection)
            ->where('company_id', $ownerUser->company_id)
            ->whereRaw('LOWER(email) = ?', [strtolower($senderEmail)])
            ->first();

        if (!$candidate) {
            return; // not from a known candidate — out of scope
        }

        // conversationId is Graph's equivalent of Gmail's threadId.
        $threadId = $message['conversationId'] ?? null;
        $sentAt = isset($message['receivedDateTime']) ? \Carbon\Carbon::parse($message['receivedDateTime']) : now();

        $alreadyStored = Email::on($connection)
            ->where('candidate_id', $candidate->id)
            ->where('thread_id', $threadId)
            ->where('subject', $subject)
            ->where('sent_at', $sentAt)
            ->exists();

        if ($alreadyStored) {
            return;
        }

        $body = $message['body']['content'] ?? ($message['bodyPreview'] ?? '');

        Email::on($connection)->create([
            'company_id' => $ownerUser->company_id,
            'user_id' => $token->user_id,
            'candidate_id' => $candidate->id,
            'direction' => 'inbound',
            'provider' => 'outlook',
            'thread_id' => $threadId,
            'subject' => $subject,
            'body' => $body,
            'sent_at' => $sentAt,
        ]);
    }
}
