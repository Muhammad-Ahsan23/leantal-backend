<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleIndexingService
{
    /**
     * PRD Section 51 — pings Google's Indexing API so a newly-published
     * (or newly-unpublished) job gets crawled faster than waiting for
     * Google's normal crawl schedule. Implemented with raw OpenSSL +
     * Laravel's HTTP client (no new Composer package — avoids another
     * heavy dependency install after everything we went through with
     * league/flysystem-aws-s3-v3).
     *
     * OPTIONAL / SILENTLY SKIPPED when not configured — this needs a
     * Google Cloud service account (JSON key file) added as an "Owner"
     * in Search Console for the domain, which the client hasn't set up
     * yet. Never blocks or breaks job publishing if it's missing.
     */
    public function notify(string $url, string $type = 'URL_UPDATED'): void
    {
        $credentialsPath = config('services.google_indexing.credentials_path');

        if (!$credentialsPath || !file_exists($credentialsPath)) {
            return; // not configured — no-op, never breaks the calling flow
        }

        try {
            $accessToken = $this->getAccessToken($credentialsPath);

            if (!$accessToken) {
                return;
            }

            Http::withToken($accessToken)
                ->post('https://indexing.googleapis.com/v3/urlNotifications:publish', [
                    'url' => $url,
                    'type' => $type, // URL_UPDATED | URL_DELETED
                ]);
        } catch (\Throwable $e) {
            // Never let a Google API hiccup break job publishing/closing.
            Log::warning('Google Indexing API notify failed', ['error' => $e->getMessage(), 'url' => $url]);
        }
    }

    protected function getAccessToken(string $credentialsPath): ?string
    {
        $credentials = json_decode(file_get_contents($credentialsPath), true);

        if (!isset($credentials['client_email'], $credentials['private_key'])) {
            return null;
        }

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/indexing',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $segments = [
            $this->base64UrlEncode(json_encode($header)),
            $this->base64UrlEncode(json_encode($claims)),
        ];

        $signingInput = implode('.', $segments);
        openssl_sign($signingInput, $signature, $credentials['private_key'], 'SHA256');
        $segments[] = $this->base64UrlEncode($signature);

        $jwt = implode('.', $segments);

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        return $response->json('access_token');
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
