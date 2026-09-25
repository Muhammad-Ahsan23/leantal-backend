<?php

namespace App\Services;

use App\Models\Interview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InterviewService
{
    /**
     * PRD Section 60 — schedules an interview. calendar_event_id stays
     * null for now — that field is populated only once real Calendar
     * API sync is wired (see CreateInterviewRequest docblock).
     */
    public function schedule(array $data, string $companyId, User $actor, string $connection): Interview
    {
        $interview = Interview::on($connection)->create([
            ...$data,
            'company_id' => $companyId,
        ]);

        $this->logActivity($connection, $companyId, $actor->id, 'interview.scheduled', $interview, [
            'start_time' => $interview->start_time->toIso8601String(),
        ]);

        return $interview;
    }

    public function reschedule(Interview $interview, array $data, User $actor, string $connection): Interview
    {
        $interview->update($data);

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
        $this->logActivity($connection, $interview->company_id, $actor->id, 'interview.cancelled', $interview, [
            'candidate_name' => $interview->candidate?->name,
        ]);

        $interview->delete();
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
