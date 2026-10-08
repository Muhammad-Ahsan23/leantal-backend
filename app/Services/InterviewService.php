<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\Interview;
use App\Models\Job;
use App\Models\OAuthToken;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InterviewService
{
    public function __construct(
        protected GoogleCalendarService $googleCalendar,
        protected MicrosoftGraphCalendarService $microsoftCalendar,
        protected NotificationService $notifications,
        protected SystemEventLogger $systemEvents,
    ) {}

    /**
     * Refuses to schedule when no meeting link can be created, instead of quietly making an interview
     * with no link and no invitation for the candidate.
     *
     * @throws \RuntimeException with a message that is safe to show the person
     */
    public function assertCanCreateMeetingLink(string $provider, User $organizer, User $actor, string $connection): void
    {
        if ($provider === 'zoom') {
            throw new \RuntimeException("Zoom links can't be created automatically yet. Please choose Google Meet.");
        }

        ['oauth_provider' => $oauthProvider] = $this->resolveProvider($provider);
        if (!$oauthProvider) {
            return;
        }

        $connected = OAuthToken::on($connection)
            ->where('user_id', $organizer->id)
            ->where('provider', $oauthProvider)
            ->whereNull('disconnected_at')
            ->exists();

        if ($connected) {
            return;
        }

        $service = $provider === 'microsoft_teams' ? 'Microsoft' : 'Google';
        $where = $provider === 'microsoft_teams' ? 'Teams' : 'Meet';

        throw new \RuntimeException($organizer->id === $actor->id
            ? "Connect your {$service} account first (Settings → Integrations). The {$where} link and the candidate's invitation are created from your calendar."
            : "{$organizer->name} hasn't connected {$service} yet. Ask them to connect it in Settings → Integrations, or choose an organizer who has.");
    }

    /**
     * PRD Section 60-61 — schedules an interview AND, when the
     * organizer has connected the matching provider (Google Calendar
     * for 'google_meet', Outlook/Microsoft 365 for 'microsoft_teams'),
     * creates a real calendar event with an auto-generated meeting
     * link and emails the candidate a calendar invite (both providers
     * handle that invite email themselves).
     *
     * The meeting link is NEVER typed in by a user (PRD Section 61): it comes from the calendar API.
     * So the organizer must have the provider connected — assertCanCreateMeetingLink() checks that
     * BEFORE anything is created, and the controller turns its message into a clear error.
     *
     * DELIBERATE DESIGN — graceful degradation: if the API call itself fails after that check (Google
     * having a bad moment), the interview is still created and the failure is logged for a safe retry
     * (Section 82) — core scheduling never fails because an external API had a bad moment. The response
     * then has no meeting_url, which is how the app knows to warn the person.
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

        // PRD Section 20 — "Interview scheduled/changed."
        $organizer = User::on($connection)->find($data['organizer_id']);
        if ($organizer) {
            $this->notifications->notify($organizer, 'interview_scheduled', 'An interview was scheduled on your calendar.', $connection, "/calendar?interview={$interview->id}");
        }

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

        // PRD Section 20 — "Interview scheduled/changed."
        $organizer = User::on($connection)->find($interview->organizer_id);
        if ($organizer) {
            $this->notifications->notify($organizer, 'interview_scheduled', 'One of your interviews was rescheduled.', $connection, "/calendar?interview={$interview->id}");
        }

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
            return; // already refused earlier by assertCanCreateMeetingLink(); only a retry can get here
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
            Log::warning("{$provider} calendar sync failed on interview create — interview kept without a link", [
                'interview_id' => $interview->id,
                'message' => $e->getMessage(),
            ]);

            // PRD Section 82 — "calendar sync errors" with "safe retry
            // triggers." context.interview_id is what a retry needs to
            // re-attempt the exact same createEvent() call.
            $this->systemEvents->log('calendar_sync', $e->getMessage(), $interview->company_id, str_replace('pgsql_', '', $connection), [
                'interview_id' => $interview->id,
                'operation' => 'create',
                'provider' => $provider,
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

            $this->systemEvents->log('calendar_sync', $e->getMessage(), $interview->company_id, str_replace('pgsql_', '', $connection), [
                'interview_id' => $interview->id,
                'operation' => 'update',
                'provider' => $interview->provider,
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

            $this->systemEvents->log('calendar_sync', $e->getMessage(), $interview->company_id, str_replace('pgsql_', '', $connection), [
                'interview_id' => $interview->id,
                'operation' => 'delete',
                'provider' => $interview->provider,
            ]);
        }
    }

    /**
     * PRD Section 82 — "safe retry triggers" for calendar sync errors.
     * Re-runs the SAME sync operation that originally failed, using
     * the interview's own current data — never arbitrary/raw
     * operations, just re-attempting a known, specific action.
     *
     * @throws \RuntimeException if the interview no longer exists
     */
    public function retrySync(string $interviewId, string $operation, string $connection): void
    {
        $interview = Interview::on($connection)->find($interviewId);
        if (!$interview) {
            throw new \RuntimeException('Interview not found — it may have been cancelled since this failure was logged.');
        }

        match ($operation) {
            'create' => $this->trySyncCreate($interview, $interview->toArray(), $interview->provider, $connection),
            'update' => $this->trySyncUpdate($interview, $connection),
            'delete' => $this->trySyncDelete($interview, $connection),
            default => throw new \RuntimeException("Unknown retry operation '{$operation}'."),
        };
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
