<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\Interview;
use App\Models\Job;
use App\Models\OAuthToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InterviewService
{
    public function __construct(
        protected GoogleCalendarService $googleCalendar,
        protected MicrosoftGraphCalendarService $microsoftCalendar,
    ) {}

    /**
     * PRD Section 60-61 — schedules an interview AND, when the
     * organizer has connected the matching provider (Google Calendar
     * for 'google_meet', Outlook/Microsoft 365 for 'microsoft_teams'),
     * creates a real calendar event with an auto-generated meeting
     * link and emails the candidate a calendar invite (both providers
     * handle that invite email themselves).
     *
     * DELIBERATE DESIGN — graceful degradation: if the organizer hasn't
     * connected the relevant provider, or the API call fails for any
     * reason, the interview is still created successfully with
     * whatever manual meeting_url was submitted — core scheduling
     * never fails because an external API had a bad moment.
     */
    public function schedule(array $data, string $companyId, User $actor, string $connection): Interview
    {
        $interview = Interview::on($connection)->create([
            ...$data,
            'company_id' => $companyId,
        ]);

        $provider = $data['provider'] ?? null;
        if (in_array($provider, ['google_meet', 'microsoft_teams'], true)) {
            $this->trySyncCreate($interview, $data, $provider, $connection);
        }

        $this->logActivity($connection, $companyId, $actor->id, 'interview.scheduled', $interview, [
            'start_time' => $interview->start_time->toIso8601String(),
        ]);

        return $interview->fresh();
    }

    public function reschedule(Interview $interview, array $data, User $actor, string $connection): Interview
    {
        $interview->update($data);
        $interview = $interview->fresh();

        if (in_array($interview->provider, ['google_meet', 'microsoft_teams'], true) && $interview->calendar_event_id) {
            $this->trySyncUpdate($interview, $connection);
        }

        $this->logActivity($connection, $interview->company_id, $actor->id, 'interview.rescheduled', $interview, [
            'start_time' => $interview->start_time->toIso8601String(),
        ]);

        return $interview->fresh();
    }

    /**
     * Hard delete — 'interviews' table has no status/deleted_at column
     * (confirmed against the actual migration), same reasoning as
     * Task::destroy(): operational data, not a record the PRD asks us
     * to preserve indefinitely.
     */
    public function cancel(Interview $interview, User $actor, string $connection): void
    {
        if (in_array($interview->provider, ['google_meet', 'microsoft_teams'], true) && $interview->calendar_event_id) {
            $this->trySyncDelete($interview, $connection);
        }

        $this->logActivity($connection, $interview->company_id, $actor->id, 'interview.cancelled', $interview, [
            'candidate_name' => $interview->candidate?->name,
        ]);

        $interview->delete();
    }

    /**
     * Maps an interview provider to its OAuth provider name + calendar
     * service — one lookup point instead of if/else scattered across
     * every sync method.
     */
    protected function resolveProvider(string $interviewProvider): array
    {
        return match ($interviewProvider) {
            'google_meet' => ['oauth_provider' => 'google', 'service' => $this->googleCalendar],
            'microsoft_teams' => ['oauth_provider' => 'microsoft', 'service' => $this->microsoftCalendar],
            default => ['oauth_provider' => null, 'service' => null],
        };
    }

    protected function trySyncCreate(Interview $interview, array $data, string $provider, string $connection): void
    {
        ['oauth_provider' => $oauthProvider, 'service' => $service] = $this->resolveProvider($provider);
        if (!$oauthProvider) {
            return;
        }

        $token = OAuthToken::on($connection)
            ->where('user_id', $data['organizer_id'])
            ->where('provider', $oauthProvider)
            ->whereNull('disconnected_at')
            ->first();

        if (!$token) {
            return; // organizer hasn't connected this provider — manual meeting_url (if any) stands
        }

        $candidate = Candidate::on($connection)->find($data['candidate_id']);
        $job = Job::on($connection)->find($data['job_id']);

        try {
            $result = $service->createEvent($token, [
                'summary' => "Interview: {$candidate?->name} — {$job?->title}",
                'description' => $data['interview_type'] ?? null,
                'start_time' => $interview->start_time->toIso8601String(),
                'end_time' => $interview->end_time->toIso8601String(),
                'candidate_email' => $candidate?->email,
            ]);

            $interview->update([
                'calendar_event_id' => $result['event_id'],
                'meeting_url' => $result['meeting_url'] ?? $interview->meeting_url,
            ]);
        } catch (\RuntimeException $e) {
            Log::warning("{$provider} calendar sync failed on interview create — falling back to manual meeting_url", [
                'interview_id' => $interview->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function trySyncUpdate(Interview $interview, string $connection): void
    {
        ['oauth_provider' => $oauthProvider, 'service' => $service] = $this->resolveProvider($interview->provider);
        if (!$oauthProvider) {
            return;
        }

        $token = OAuthToken::on($connection)
            ->where('user_id', $interview->organizer_id)
            ->where('provider', $oauthProvider)
            ->whereNull('disconnected_at')
            ->first();

        if (!$token) {
            return;
        }

        try {
            $service->updateEvent($token, $interview->calendar_event_id, [
                'start_time' => $interview->start_time->toIso8601String(),
                'end_time' => $interview->end_time->toIso8601String(),
            ]);
        } catch (\RuntimeException $e) {
            Log::warning("{$interview->provider} calendar sync failed on interview reschedule", [
                'interview_id' => $interview->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function trySyncDelete(Interview $interview, string $connection): void
    {
        ['oauth_provider' => $oauthProvider, 'service' => $service] = $this->resolveProvider($interview->provider);
        if (!$oauthProvider) {
            return;
        }

        $token = OAuthToken::on($connection)
            ->where('user_id', $interview->organizer_id)
            ->where('provider', $oauthProvider)
            ->whereNull('disconnected_at')
            ->first();

        if (!$token) {
            return;
        }

        try {
            $service->deleteEvent($token, $interview->calendar_event_id);
        } catch (\RuntimeException $e) {
            Log::warning("{$interview->provider} calendar sync failed on interview cancel", [
                'interview_id' => $interview->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function logActivity(string $connection, string $companyId, string $actorId, string $action, Interview $interview, array $metadata = []): void
    {
        DB::connection($connection)->table('activity')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'action' => $action,
            'object_type' => 'interview',
            'object_id' => $interview->id,
            'metadata' => json_encode($metadata),
            'created_at' => now(),
        ]);
    }
}
