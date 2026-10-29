<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Zoom "User-managed" OAuth app. Like Google/Microsoft, one app registration is shared by the whole platform and every
 * interviewer connects their OWN Zoom account (PRD Section 113). Zoom authenticates the token calls with HTTP Basic
 * (client_id:client_secret). NOTE: Zoom rotates the refresh token — every refresh returns a NEW one that must be stored.
 */
class ZoomOAuthService
{
    protected const AUTH_URL = 'https://zoom.us/oauth/authorize';

    protected const TOKEN_URL = 'https://zoom.us/oauth/token';

    protected const REVOKE_URL = 'https://zoom.us/oauth/revoke';

    protected const API = 'https://api.zoom.us/v2';

    public function isConfigured(): bool
    {
        return filled(config('services.zoom.client_id')) && filled(config('services.zoom.client_secret')) && filled(config('services.zoom.redirect_uri'));
    }

    public function buildAuthUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.zoom.client_id'),
            'redirect_uri' => config('services.zoom.redirect_uri'),
            'state' => $state,
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_in: int, scope?: string}
     *
     * @throws \RuntimeException on failure
     */
    public function exchangeCodeForTokens(string $code): array
    {
        $response = $this->basic()->asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.zoom.redirect_uri'),
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Zoom token exchange failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_in: int}
     *
     * @throws \RuntimeException on failure
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $response = $this->basic()->asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Zoom token refresh failed: '.$response->body());
        }

        return $response->json();
    }

    /** @throws \RuntimeException on failure */
    public function getUserEmail(string $accessToken): string
    {
        $response = Http::withToken($accessToken)->get(self::API.'/users/me');

        if (!$response->successful() || !$response->json('email')) {
            throw new \RuntimeException('Failed to fetch connected Zoom account email: '.$response->body());
        }

        return $response->json('email');
    }

    /** @throws \RuntimeException on failure */
    public function revokeToken(string $token): void
    {
        $response = $this->basic()->asForm()->post(self::REVOKE_URL, ['token' => $token]);

        if (!$response->successful()) {
            throw new \RuntimeException('Zoom token revocation failed: '.$response->body());
        }
    }

    protected function basic()
    {
        return Http::withBasicAuth((string) config('services.zoom.client_id'), (string) config('services.zoom.client_secret'));
    }
}
