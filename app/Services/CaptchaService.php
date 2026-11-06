<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CaptchaService
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * PRD Section 14 requires CAPTCHA on Signup and Login. Provider: Cloudflare Turnstile
     * (replaces Google reCAPTCHA). Verifies the token the frontend widget produces
     * against Cloudflare's siteverify endpoint. The request field is still called
     * `captcha_token`, so controllers and requests are unchanged.
     */
    public function verify(?string $token): bool
    {
        if (blank($token)) {
            return false;
        }

        $secret = config('services.turnstile.secret');

        // If no secret is configured yet, fail closed everywhere except local dev.
        if (blank($secret)) {
            Log::warning('TURNSTILE_SECRET_KEY not configured — CAPTCHA check skipped.');
            return app()->environment('local');
        }

        try {
            $response = Http::asForm()->timeout(10)->post(self::VERIFY_URL, array_filter([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => request()?->ip(),
            ]));
        } catch (\Throwable $e) {
            Log::error('Turnstile verification request failed', ['error' => $e->getMessage()]);
            return false;
        }

        if (!$response->successful()) {
            Log::error('Turnstile verification request failed', ['status' => $response->status()]);
            return false;
        }

        $result = $response->json();

        if (!($result['success'] ?? false)) {
            Log::info('Turnstile verification failed', ['errors' => $result['error-codes'] ?? []]);
        }

        return (bool) ($result['success'] ?? false);
    }
}
