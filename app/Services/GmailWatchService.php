<?php

namespace App\Services;

use App\Models\GmailWatchState;
use App\Models\OAuthToken;
use Illuminate\Support\Facades\Http;

/**
 * PRD Section 57 — "Receive and display replies." Uses Gmail API's
 * push-notification model (users.watch), Google's own recommended
 * approach for this — NOT polling. Registering a watch tells Gmail:
 * "publish a Pub/Sub message to this topic whenever this mailbox
 * changes." Our GmailPushWebhookController receives those messages.
 *
 * Watches expire after 7 days (Gmail's own hard limit, not ours) and
 * must be renewed — see RenewGmailWatches command.
 */
class GmailWatchService
{
    public function __construct(protected GoogleCalendarOAuthService $oauth) {}

    protected function ensureFreshToken(OAuthToken $token): string
    {
        if ($token->expires_at && $token->expires_at->isFuture()) {
            return $token->access_token;
        }

        if (!$token->refresh_token) {
            throw new \RuntimeException('Google connection has expired and cannot be auto-refreshed — please reconnect.');
        }

        $result = $this->oauth->refreshAccessToken($token->refresh_token);

        $token->update([
            'access_token' => $result['access_token'],
            'expires_at' => now()->addSeconds($result['expires_in'] ?? 3600),
        ]);

        return $result['access_token'];
    }

    /**
     * @throws \RuntimeException on failure or if the Pub/Sub topic isn't configured
     */
    public function registerWatch(OAuthToken $token, string $connection): GmailWatchState
    {
        $topic = config('services.google_calendar.pubsub_topic');
        if (!$topic) {
            throw new \RuntimeException('GOOGLE_PUBSUB_TOPIC is not configured — cannot register Gmail watch.');
        }

        $accessToken = $this->ensureFreshToken($token);

        $response = Http::withToken($accessToken)
            ->post('https://gmail.googleapis.com/gmail/v1/users/me/watch', [
                'topicName' => $topic,
                'labelIds' => ['INBOX'],
                'labelFilterAction' => 'include',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Gmail watch registration failed: '.$response->body());
        }

        $json = $response->json();

        return GmailWatchState::on($connection)->updateOrCreate(
            ['user_id' => $token->user_id],
            [
                'history_id' => $json['historyId'],
                // Gmail returns expiration as a millisecond epoch timestamp (string)
                'watch_expires_at' => now()->createFromTimestampMs((int) $json['expiration']),
            ]
        );
    }

    /**
     * Called by the scheduled command for every connection whose watch
     * is expiring soon — re-registering is the same call as the
     * original registration, Gmail simply extends it another 7 days.
     */
    public function renewWatch(OAuthToken $token, string $connection): void
    {
        $this->registerWatch($token, $connection);
    }
}
