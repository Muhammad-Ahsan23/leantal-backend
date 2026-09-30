<?php

namespace App\Services;

use App\Models\OAuthToken;
use Illuminate\Support\Facades\Http;

/**
 * PRD Section 55 — "Recruiting/Communication emails: Sent from the
 * user's connected personal inbox." Uses the Gmail API's messages.send
 * endpoint against the CALLER's own connected account — Gmail
 * automatically sends "From" the authenticated account regardless of
 * any From header in the raw MIME, so a spoofed sender is not possible
 * even if we got that header wrong.
 */
class GmailService
{
    public function __construct(protected GoogleCalendarOAuthService $oauth) {}

    /**
     * Same lazy-refresh pattern as GoogleCalendarService — token is
     * only refreshed when actually expired, keeping this scalable
     * (no polling job for idle connections).
     *
     * @throws \RuntimeException if expired with no refresh_token
     */
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
     * @throws \RuntimeException on failure
     */
    public function sendEmail(OAuthToken $token, string $to, string $subject, string $htmlBody): void
    {
        $accessToken = $this->ensureFreshToken($token);

        $raw = $this->buildRawMessage($to, $subject, $htmlBody);

        $response = Http::withToken($accessToken)
            ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                'raw' => $raw,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Gmail send failed: '.$response->body());
        }
    }

    /**
     * Builds a base64url-encoded RFC 2822 message, as the Gmail API
     * requires. Subject is MIME-encoded (=?utf-8?B?...?=) so non-ASCII
     * candidate names/job titles in the subject line don't break the
     * header. No "From" header is set — Gmail enforces the
     * authenticated account as sender regardless, so specifying one
     * ourselves would be redundant at best.
     */
    protected function buildRawMessage(string $to, string $subject, string $htmlBody): string
    {
        $encodedSubject = '=?utf-8?B?'.base64_encode($subject).'?=';

        $message = "To: {$to}\r\n";
        $message .= "Subject: {$encodedSubject}\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: text/html; charset=utf-8\r\n";
        $message .= "\r\n";
        $message .= $htmlBody;

        return rtrim(strtr(base64_encode($message), '+/', '-_'), '=');
    }
}
