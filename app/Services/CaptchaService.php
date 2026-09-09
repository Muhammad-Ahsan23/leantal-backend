<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CaptchaService
{
    /**
     * PRD Section 14 requires CAPTCHA on Signup and Login. Provider
     * confirmed as Google reCAPTCHA v2 ("I'm not a robot" checkbox).
     * Verifies the token the frontend widget produces against Google's
     * siteverify endpoint.
     */
    public function verify(string $token): bool
    {
        if (blank($token)) {
            return false;
        }

        $secret = config('services.recaptcha.secret');

        // If no secret is configured yet (e.g. still setting up keys),
        // fail closed in production but allow local dev to proceed —
        // remove this escape hatch once real keys are in .env everywhere.
        if (blank($secret)) {
            Log::warning('RECAPTCHA_SECRET_KEY not configured — CAPTCHA check skipped.');
            return app()->environment('local');
        }

        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $secret,
            'response' => $token,
        ]);

        if (!$response->successful()) {
            Log::error('reCAPTCHA verification request failed', ['status' => $response->status()]);
            return false;
        }

        $result = $response->json();

        if (!($result['success'] ?? false)) {
            Log::info('reCAPTCHA verification failed', ['errors' => $result['error-codes'] ?? []]);
        }

        return $result['success'] ?? false;
    }
}
