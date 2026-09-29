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
     */
    public function buildAuthUrl(string $state): string
    {
        $params = http_build_query([
            'client_id' => config('services.google_calendar.client_id'),
            'redirect_uri' => config('services.google_calendar.redirect_uri'),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar.events',
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
}
