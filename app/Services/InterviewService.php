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
    public function __construct(protected GoogleCalendarService $googleCalendar) {}

    /**
     * PRD Section 60-61 — schedules an interview AND, when the
     * organizer has connected Google Calendar and chose 'google_meet'
     * as the provider, creates a real Google Calendar event with an
     * auto-generated Meet link and emails the candidate a calendar
     * invite (Google handles that email itself — see
     * GoogleCalendarService::createEvent()).
     *
     * DELIBERATE DESIGN — graceful degradation: if the organizer hasn't
     * connected Google, or the Google API call fails for any reason
     * (expired connection, network issue, revoked permission), the
     * interview is still created successfully with whatever manual
     * meeting_url was submitted — core scheduling never fails because
     * an external API had a bad moment. The failure is logged, not
     * silently swallowed, so it's visible for follow-up.
     */
    public function schedule(array $data, string $companyId, User $actor, string $connection): Interview
    {
        $interview = Interview::on($connection)->create([
            ...$data,
            'company_id' => $companyId,
        ]);

        if (($data['provider'] ?? null) === 'google_meet') {
            $this->trySyncCreate($interview, $data, $connection);
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

        if ($interview->provider === 'google_meet' && $interview->calendar_event_id) {
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
        if ($interview->provider === 'google_meet' && $interview->calendar_event_id) {
            $this->trySyncDelete($interview, $connection);
        }

        $this->logActivity($connection, $interview->company_id, $actor->id, 'interview.cancelled', $interview, [
            'candidate_name' => $interview->candidate?->name,
        ]);

        $interview->delete();
    }

    protected function trySyncCreate(Interview $interview, array $data, string $connection): void
    {
        $token = OAuthToken::on($connection)
            ->where('user_id', $data['organizer_id'])
            ->where('provider', 'google')
            ->whereNull('disconnected_at')
            ->first();

        if (!$token) {
            return; // organizer hasn't connected Google — manual meeting_url (if any) stands as-is
        }

        $candidate = Candidate::on($connection)->find($data['candidate_id']);
        $job = Job::on($connection)->find($data['job_id']);

        try {
            $result = $this->googleCalendar->createEvent($token, [
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
            Log::warning('Google Calendar sync failed on interview create — falling back to manual meeting_url', [
                'interview_id' => $interview->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function trySyncUpdate(Interview $interview, string $connection): void
    {
        $token = OAuthToken::on($connection)
            ->where('user_id', $interview->organizer_id)
            ->where('provider', 'google')
            ->whereNull('disconnected_at')
            ->first();

        if (!$token) {
            return;
        }

        try {
            $this->googleCalendar->updateEvent($token, $interview->calendar_event_id, [
                'start_time' => $interview->start_time->toIso8601String(),
                'end_time' => $interview->end_time->toIso8601String(),
            ]);
        } catch (\RuntimeException $e) {
            Log::warning('Google Calendar sync failed on interview reschedule', [
                'interview_id' => $interview->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function trySyncDelete(Interview $interview, string $connection): void
    {
        $token = OAuthToken::on($connection)
            ->where('user_id', $interview->organizer_id)
            ->where('provider', 'google')
            ->whereNull('disconnected_at')
            ->first();

        if (!$token) {
            return;
        }

        try {
            $this->googleCalendar->deleteEvent($token, $interview->calendar_event_id);
        } catch (\RuntimeException $e) {
            Log::warning('Google Calendar sync failed on interview cancel', [
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
