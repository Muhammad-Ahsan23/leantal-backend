<?php

namespace App\Services;

use App\Models\OAuthToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

/**
 * Creates / moves / deletes the Zoom meeting for an interview, on the ORGANIZER's own Zoom account. Same method
 * shapes as GoogleCalendarService / MicrosoftGraphCalendarService so InterviewService treats all providers alike.
 * Unlike a calendar, Zoom does NOT email the candidate — InterviewInviteService does that.
 */
class ZoomMeetingService
{
    protected const API = 'https://api.zoom.us/v2';

    public function __construct(protected ZoomOAuthService $oauth) {}

    /**
     * Refreshes lazily, only when expired. Zoom ROTATES refresh tokens, so two simultaneous refreshes with the same
     * old token would break the connection — a short lock makes the second caller wait and reuse the new token.
     *
     * @throws \RuntimeException if it cannot be refreshed (the person must reconnect)
     */
    protected function ensureFreshToken(OAuthToken $token): string
    {
        if ($token->expires_at && $token->expires_at->gt(now()->addSeconds(30))) {
            return $token->access_token;
        }

        if (!$token->refresh_token) {
            throw new \RuntimeException('Zoom connection has expired and cannot be auto-refreshed — please reconnect.');
        }

        try {
            return $this->refreshLocked($token);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            throw new \RuntimeException('Zoom connection is busy refreshing — please try again.');
        }
    }

    protected function refreshLocked(OAuthToken $token): string
    {
        return Cache::lock("zoom-refresh:{$token->id}", 10)->block(10, function () use ($token) {
            $token->refresh(); // another request may have just rotated it
            if ($token->expires_at && $token->expires_at->gt(now()->addSeconds(30))) {
                return $token->access_token;
            }

            $result = $this->oauth->refreshAccessToken($token->refresh_token);

            $token->update([
                'access_token' => $result['access_token'],
                'refresh_token' => $result['refresh_token'] ?? $token->refresh_token,
                'expires_at' => now()->addSeconds($result['expires_in'] ?? 3600),
            ]);

            return $result['access_token'];
        });
    }

    /**
     * @param  array{summary: string, description: ?string, start_time: string, end_time: string}  $data
     * @return array{event_id: string, meeting_url: ?string}
     *
     * @throws \RuntimeException on failure
     */
    public function createEvent(OAuthToken $token, array $data): array
    {
        $response = Http::withToken($this->ensureFreshToken($token))->post(self::API.'/users/me/meetings', [
            'topic' => mb_substr($data['summary'], 0, 200),
            'type' => 2, // scheduled meeting
            'start_time' => $this->utc($data['start_time']),
            'duration' => $this->minutes($data['start_time'], $data['end_time']),
            'timezone' => 'UTC',
            'agenda' => mb_substr((string) ($data['description'] ?? ''), 0, 2000),
            'settings' => ['join_before_host' => true, 'waiting_room' => false],
        ]);

        if (!$response->successful() || !$response->json('id')) {
            throw new \RuntimeException('Zoom meeting creation failed: '.$response->body());
        }

        return ['event_id' => (string) $response->json('id'), 'meeting_url' => $response->json('join_url')];
    }

    /** @throws \RuntimeException on failure */
    public function updateEvent(OAuthToken $token, string $eventId, array $data): void
    {
        $response = Http::withToken($this->ensureFreshToken($token))->patch(self::API."/meetings/{$eventId}", [
            'start_time' => $this->utc($data['start_time']),
            'duration' => $this->minutes($data['start_time'], $data['end_time']),
            'timezone' => 'UTC',
        ]);

        if (!$response->successful()) { // Zoom answers 204 on success
            throw new \RuntimeException('Zoom meeting update failed: '.$response->body());
        }
    }

    /** @throws \RuntimeException on failure */
    public function deleteEvent(OAuthToken $token, string $eventId): void
    {
        $response = Http::withToken($this->ensureFreshToken($token))->delete(self::API."/meetings/{$eventId}");

        // 404 = already deleted on Zoom's side: not a real failure.
        if (!$response->successful() && $response->status() !== 404) {
            throw new \RuntimeException('Zoom meeting deletion failed: '.$response->body());
        }
    }

    protected function utc(string $iso): string
    {
        return \Carbon\Carbon::parse($iso)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    protected function minutes(string $start, string $end): int
    {
        return max(1, (int) \Carbon\Carbon::parse($start)->diffInMinutes(\Carbon\Carbon::parse($end)));
    }
}
