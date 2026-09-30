<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * PRD Section 54 — "Individual OAuth connections for Gmail and
 * Outlook/Microsoft 365 (via Microsoft Graph). Do not ask users for
 * email passwords. Request minimal required scopes."
 *
 * Scopes: Calendars.ReadWrite (Section 60 — Outlook Calendar & Teams),
 * Mail.Send (Section 55 — send from personal inbox), Mail.Read
 * (Section 57 — receive/display replies), User.Read (own email
 * address, to match incoming webhook notifications), offline_access
 * (refresh token).
 */
class MicrosoftOAuthService
{
    public function buildAuthUrl(string $state): string
    {
        $params = http_build_query([
            'client_id' => config('services.microsoft.client_id'),
            'redirect_uri' => config('services.microsoft.redirect_uri'),
            'response_type' => 'code',
            'response_mode' => 'query',
            'scope' => 'openid email offline_access Calendars.ReadWrite Mail.Send Mail.Read User.Read',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?'.$params;
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_in: int}
     * @throws \RuntimeException on failure
     */
    public function exchangeCodeForTokens(string $code): array
    {
        $response = Http::asForm()->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
            'client_id' => config('services.microsoft.client_id'),
            'client_secret' => config('services.microsoft.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.microsoft.redirect_uri'),
            'scope' => 'openid email offline_access Calendars.ReadWrite Mail.Send Mail.Read User.Read',
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Microsoft token exchange failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * @throws \RuntimeException on failure
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $response = Http::asForm()->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
            'client_id' => config('services.microsoft.client_id'),
            'client_secret' => config('services.microsoft.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
            'scope' => 'openid email offline_access Calendars.ReadWrite Mail.Send Mail.Read User.Read',
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Microsoft token refresh failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * @throws \RuntimeException on failure
     */
    public function getUserEmail(string $accessToken): string
    {
        $response = Http::withToken($accessToken)->get('https://graph.microsoft.com/v1.0/me');

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to fetch connected Microsoft account email: '.$response->body());
        }

        // Microsoft accounts can have mail under either 'mail' or
        // 'userPrincipalName' depending on tenant configuration —
        // 'mail' is the real mailbox address when present, but some
        // accounts (especially personal Microsoft accounts) leave it
        // null and only populate userPrincipalName.
        $json = $response->json();

        return $json['mail'] ?? $json['userPrincipalName'];
    }
}
