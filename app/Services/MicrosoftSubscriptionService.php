<?php

namespace App\Services;

use App\Models\MicrosoftSubscriptionState;
use App\Models\OAuthToken;
use Illuminate\Support\Facades\Http;

/**
 * PRD Section 57 — "Receive and display replies." Microsoft Graph's
 * subscription model (no separate Cloud-console setup needed, unlike
 * Gmail's Pub/Sub) — but expires much sooner: max ~4230 minutes
 * (~2.94 days), vs Gmail's 7 days, so renewal must run more often
 * (see RenewMicrosoftSubscriptions command).
 */
class MicrosoftSubscriptionService
{
    public function __construct(protected MicrosoftOAuthService $oauth) {}

    protected function ensureFreshToken(OAuthToken $token): string
    {
        if ($token->expires_at && $token->expires_at->isFuture()) {
            return $token->access_token;
        }

        if (!$token->refresh_token) {
            throw new \RuntimeException('Microsoft connection has expired — please reconnect.');
        }

        $result = $this->oauth->refreshAccessToken($token->refresh_token);

        $token->update([
            'access_token' => $result['access_token'],
            'expires_at' => now()->addSeconds($result['expires_in'] ?? 3600),
        ]);

        return $result['access_token'];
    }

    /**
     * @throws \RuntimeException on failure
     */
    public function registerSubscription(OAuthToken $token, string $connection): MicrosoftSubscriptionState
    {
        $accessToken = $this->ensureFreshToken($token);
        $webhookUrl = config('services.microsoft.webhook_url');
        $clientState = config('services.microsoft.webhook_secret');

        // Microsoft's hard cap for mail-resource subscriptions is 4230
        // minutes (~2.94 days) — using the max allowed reduces how
        // often we need to re-create vs merely renew.
        $expiresAt = now()->addMinutes(4230);

        $response = Http::withToken($accessToken)->post('https://graph.microsoft.com/v1.0/subscriptions', [
            'changeType' => 'created',
            'notificationUrl' => $webhookUrl,
            'resource' => "me/mailFolders('Inbox')/messages",
            'expirationDateTime' => $expiresAt->toIso8601String(),
            'clientState' => $clientState,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Microsoft subscription registration failed: '.$response->body());
        }

        $json = $response->json();

        return MicrosoftSubscriptionState::on($connection)->updateOrCreate(
            ['user_id' => $token->user_id],
            ['subscription_id' => $json['id'], 'expires_at' => $expiresAt]
        );
    }

    /**
     * Renewal PATCHes the existing subscription's expirationDateTime
     * rather than creating a new one — the subscription_id stays the
     * same, avoiding an unnecessary delete+recreate cycle.
     *
     * @throws \RuntimeException on failure
     */
    public function renewSubscription(OAuthToken $token, MicrosoftSubscriptionState $state): void
    {
        $accessToken = $this->ensureFreshToken($token);
        $expiresAt = now()->addMinutes(4230);

        $response = Http::withToken($accessToken)
            ->patch("https://graph.microsoft.com/v1.0/subscriptions/{$state->subscription_id}", [
                'expirationDateTime' => $expiresAt->toIso8601String(),
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Microsoft subscription renewal failed: '.$response->body());
        }

        $state->update(['expires_at' => $expiresAt]);
    }

    /**
     * PRD Section 131 — "immediately terminates sync jobs." Actively
     * removes the Graph subscription (rather than letting it sit until
     * natural ~3-day expiry) — the moment a user disconnects, Microsoft
     * stops sending us notifications for their mailbox.
     *
     * @throws \RuntimeException on failure
     */
    public function deleteSubscription(OAuthToken $token, string $subscriptionId): void
    {
        $accessToken = $this->ensureFreshToken($token);

        $response = Http::withToken($accessToken)
            ->delete("https://graph.microsoft.com/v1.0/subscriptions/{$subscriptionId}");

        // 404 means Graph already expired/removed it — not a real failure.
        if (!$response->successful() && $response->status() !== 404) {
            throw new \RuntimeException('Microsoft subscription deletion failed: '.$response->body());
        }
    }
}
