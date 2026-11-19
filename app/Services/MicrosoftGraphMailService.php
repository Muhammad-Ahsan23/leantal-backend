<?php

namespace App\Services;

use App\Models\OAuthToken;
use Illuminate\Support\Facades\Http;

/**
 * PRD Section 55 — "Recruiting/Communication emails: Sent from the
 * user's connected personal inbox." Microsoft Graph's sendMail takes
 * structured JSON directly — no raw MIME/base64url encoding needed
 * (unlike Gmail's API), Graph handles that internally.
 */
class MicrosoftGraphMailService
{
    public function __construct(protected MicrosoftOAuthService $oauth) {}

    protected function ensureFreshToken(OAuthToken $token): string
    {
        if ($token->expires_at && $token->expires_at->isFuture()) {
            return $token->access_token;
        }

        if (!$token->refresh_token) {
            throw new \RuntimeException('Microsoft connection has expired and cannot be auto-refreshed — please reconnect.');
        }

        $result = $this->oauth->refreshAccessToken($token->refresh_token);

        $token->update([
            'access_token' => $result['access_token'],
            'expires_at' => now()->addSeconds($result['expires_in'] ?? 3600),
        ]);

        return $result['access_token'];
    }

    /**
     * @param string|string[] $to one address or several
     * @param array<int, array{name: string, mime: string, data: string}> $attachments raw file contents; sent inline, so the
     *   caller keeps the total small (Graph's sendMail request limit is about 4 MB, base64 included)
     * @throws \RuntimeException on failure
     */
    public function sendEmail(OAuthToken $token, string|array $to, string $subject, string $htmlBody, array $attachments = []): void
    {
        $accessToken = $this->ensureFreshToken($token);

        $message = [
            'subject' => $subject,
            'body' => ['contentType' => 'HTML', 'content' => $htmlBody],
            'toRecipients' => array_map(fn ($a) => ['emailAddress' => ['address' => $a]], array_values((array) $to)),
        ];
        if ($attachments) {
            $message['attachments'] = array_map(fn ($f) => [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $f['name'],
                'contentType' => $f['mime'] ?: 'application/octet-stream',
                'contentBytes' => base64_encode($f['data']),
            ], $attachments);
        }

        $response = Http::withToken($accessToken)->post('https://graph.microsoft.com/v1.0/me/sendMail', [
            'message' => $message,
            'saveToSentItems' => true,
        ]);

        // sendMail returns 202 Accepted with an empty body on success.
        if (!$response->successful()) {
            throw new \RuntimeException('Microsoft Graph mail send failed: '.$response->body());
        }
    }
}
