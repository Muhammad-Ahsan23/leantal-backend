<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GoogleCalendarOAuthService
{
    /**
     * PRD Section 113 — Integrations are PER-USER, not company-wide.
     * This one OAuth app registration (Google Cloud project) is shared
     * across the whole platform — every company's staff authorize
     * through the SAME app, but each person connects their OWN Google
     * account. state links the callback back to which user/connection
     * initiated it (see OAuthConnectController).
     *
     * PRD Section 54 — "Request minimal required scopes." One "Connect
     * Google" action requests all 4 scopes together (Calendar, Gmail
     * send, Gmail read, and the connected account's own email address)
     * in a single consent screen — simpler than separate "Connect
     * Calendar" / "Connect Gmail" buttons. gmail.readonly is required
     * (not just gmail.send) because BOTH the reply-sync watch
     * registration (Gmail API's users.watch requires readonly/modify/
     * metadata/mail.google.com — send-only is rejected, confirmed via
     * a real 403 ACCESS_TOKEN_SCOPE_INSUFFICIENT error) AND reading
     * full message bodies (users.messages.get) need read access —
     * gmail.metadata alone would cover watch() but not body content.
     */
    public function buildAuthUrl(string $state): string
    {
        $params = http_build_query([
            'client_id' => config('services.google_calendar.client_id'),
            'redirect_uri' => config('services.google_calendar.redirect_uri'),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/userinfo.email',
            'access_type' => 'offline', // required to receive a refresh_token
            'prompt' => 'consent',      // forces refresh_token on every connect, not just the first
            'state' => $state,
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.$params;
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_in: int}
     * @throws \RuntimeException on failure
     */
    public function exchangeCodeForTokens(string $code): array
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.google_calendar.redirect_uri'),
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Google token exchange failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * @throws \RuntimeException on failure
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Google token refresh failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * The connected Gmail address itself — needed to match incoming
     * Pub/Sub push notifications (which identify the mailbox by email
     * address, see GmailSyncService) back to our internal user_id.
     *
     * @throws \RuntimeException on failure
     */
    public function getUserEmail(string $accessToken): string
    {
        $response = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v2/userinfo');

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to fetch connected Google account email: '.$response->body());
        }

        return $response->json('email');
    }

    /**
     * PRD Section 131 — "Disconnecting Gmail/Outlook immediately
     * terminates sync jobs, deletes stored access/refresh tokens."
     * Revokes the token AT GOOGLE too (not just deleting our own copy)
     * — Google's revoke endpoint accepts either an access_token or a
     * refresh_token and invalidates the whole grant, so a single call
     * covers both. Best-effort: if the token was already expired/
     * invalid, Google still returns 200, so failures here are genuinely
     * abnormal — caller decides whether to treat as fatal.
     *
     * @throws \RuntimeException on failure
     */
    public function revokeToken(string $token): void
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/revoke', [
            'token' => $token,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Google token revocation failed: '.$response->body());
        }
    }
}
