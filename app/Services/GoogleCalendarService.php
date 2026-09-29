<?php

namespace App\Services;

use App\Models\OAuthToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * PRD Section 60-61 — "The goal is strictly creating calendar events,
 * generating meeting links, and sending candidate invitations." Google
 * Calendar API does all three in ONE call: creating an event with
 * conferenceDataVersion=1 auto-generates a real Meet link, and adding
 * the candidate as an attendee (with sendUpdates=all) makes Google
 * itself email them a calendar invite — no separate email-sending code
 * needed on our side for this part.
 */
class GoogleCalendarService
{
    protected const BASE_URL = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    public function __construct(protected GoogleCalendarOAuthService $oauth) {}

    /**
     * Lazily refreshes the access token only when actually expired —
     * scalable by design: no background job polling every connected
     * account, the cost is paid only by connections that are actively
     * used. Updates the stored token in place so the NEXT call (for
     * this same organizer) reuses the fresh one without re-refreshing.
     *
     * @throws \RuntimeException if expired with no refresh_token (must reconnect)
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
     * @param array{summary: string, description: ?string, start_time: string, end_time: string, candidate_email: string} $data
     * @return array{event_id: string, meeting_url: ?string, html_link: ?string}
     * @throws \RuntimeException on failure — caller decides whether to
     *   treat this as fatal or degrade gracefully (see InterviewService)
     */
    public function createEvent(OAuthToken $token, array $data): array
    {
        $accessToken = $this->ensureFreshToken($token);

        $response = Http::withToken($accessToken)
            ->post(self::BASE_URL.'?conferenceDataVersion=1&sendUpdates=all', [
                'summary' => $data['summary'],
                'description' => $data['description'] ?? '',
                'start' => ['dateTime' => $data['start_time'], 'timeZone' => 'UTC'],
                'end' => ['dateTime' => $data['end_time'], 'timeZone' => 'UTC'],
                'attendees' => [['email' => $data['candidate_email']]],
                'conferenceData' => [
                    'createRequest' => [
                        'requestId' => (string) Str::uuid(),
                        'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                    ],
                ],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Google Calendar event creation failed: '.$response->body());
        }

        $json = $response->json();
        $meetingUrl = null;

        foreach ($json['conferenceData']['entryPoints'] ?? [] as $entryPoint) {
            if (($entryPoint['entryPointType'] ?? null) === 'video') {
                $meetingUrl = $entryPoint['uri'];
                break;
            }
        }

        return [
            'event_id' => $json['id'],
            'meeting_url' => $meetingUrl,
            'html_link' => $json['htmlLink'] ?? null,
        ];
    }

    /**
     * @throws \RuntimeException on failure
     */
    public function updateEvent(OAuthToken $token, string $eventId, array $data): void
    {
        $accessToken = $this->ensureFreshToken($token);

        $response = Http::withToken($accessToken)
            ->patch(self::BASE_URL."/{$eventId}?sendUpdates=all", [
                'start' => ['dateTime' => $data['start_time'], 'timeZone' => 'UTC'],
                'end' => ['dateTime' => $data['end_time'], 'timeZone' => 'UTC'],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Google Calendar event update failed: '.$response->body());
        }
    }

    /**
     * @throws \RuntimeException on failure
     */
    public function deleteEvent(OAuthToken $token, string $eventId): void
    {
        $accessToken = $this->ensureFreshToken($token);

        $response = Http::withToken($accessToken)
            ->delete(self::BASE_URL."/{$eventId}?sendUpdates=all");

        // Google returns 410 Gone if the event was already removed
        // (e.g. deleted manually on Google's side) — not a real failure.
        if (!$response->successful() && $response->status() !== 410) {
            throw new \RuntimeException('Google Calendar event deletion failed: '.$response->body());
        }
    }
}
