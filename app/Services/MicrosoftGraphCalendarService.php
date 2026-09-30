<?php

namespace App\Services;

use App\Models\OAuthToken;
use Illuminate\Support\Facades\Http;

/**
 * PRD Section 60-61 — "Outlook Calendar & Microsoft Teams... creating
 * calendar events, generating meeting links, and sending candidate
 * invitations." Microsoft Graph creates the event AND generates a
 * Teams link in ONE call via isOnlineMeeting/onlineMeetingProvider —
 * same one-call pattern as Google Calendar's conferenceData.
 */
class MicrosoftGraphCalendarService
{
    protected const BASE_URL = 'https://graph.microsoft.com/v1.0/me/events';

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
     * @param array{summary: string, description: ?string, start_time: string, end_time: string, candidate_email: string} $data
     * @return array{event_id: string, meeting_url: ?string}
     * @throws \RuntimeException on failure
     */
    public function createEvent(OAuthToken $token, array $data): array
    {
        $accessToken = $this->ensureFreshToken($token);

        $response = Http::withToken($accessToken)->post(self::BASE_URL, [
            'subject' => $data['summary'],
            'body' => ['contentType' => 'HTML', 'content' => $data['description'] ?? ''],
            'start' => ['dateTime' => $data['start_time'], 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $data['end_time'], 'timeZone' => 'UTC'],
            'attendees' => [[
                'emailAddress' => ['address' => $data['candidate_email']],
                'type' => 'required',
            ]],
            'isOnlineMeeting' => true,
            'onlineMeetingProvider' => 'teamsForBusiness',
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Microsoft Calendar event creation failed: '.$response->body());
        }

        $json = $response->json();

        return [
            'event_id' => $json['id'],
            'meeting_url' => $json['onlineMeeting']['joinUrl'] ?? null,
        ];
    }

    /**
     * @throws \RuntimeException on failure
     */
    public function updateEvent(OAuthToken $token, string $eventId, array $data): void
    {
        $accessToken = $this->ensureFreshToken($token);

        $response = Http::withToken($accessToken)->patch(self::BASE_URL."/{$eventId}", [
            'start' => ['dateTime' => $data['start_time'], 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $data['end_time'], 'timeZone' => 'UTC'],
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Microsoft Calendar event update failed: '.$response->body());
        }
    }

    /**
     * @throws \RuntimeException on failure
     */
    public function deleteEvent(OAuthToken $token, string $eventId): void
    {
        $accessToken = $this->ensureFreshToken($token);

        $response = Http::withToken($accessToken)->delete(self::BASE_URL."/{$eventId}");

        // 404 means already deleted on Microsoft's side — not a real failure.
        if (!$response->successful() && $response->status() !== 404) {
            throw new \RuntimeException('Microsoft Calendar event deletion failed: '.$response->body());
        }
    }
}
