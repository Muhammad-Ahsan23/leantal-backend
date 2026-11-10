<?php

namespace App\Services;

use App\Exceptions\OptimisticLockConflictException;
use App\Models\Application;
use App\Models\PipelineStage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ApplicationService
{
    public const ALREADY_ACTIVE = 'This candidate already has an active application for this job.';

    protected function assertNoOtherActive(Application $application, string $connection): void
    {
        $other = Application::on($connection)
            ->where('candidate_id', $application->candidate_id)
            ->where('job_id', $application->job_id)
            ->where('status', 'active')
            ->where('id', '!=', $application->id)
            ->exists();

        if ($other) {
            throw new \RuntimeException(self::ALREADY_ACTIVE . ' Remove or move that one instead.');
        }
    }

    /**
     * The one application a candidate has for a job that "applying again" should work with: the ACTIVE one if there is
     * one, otherwise the most recent (Hired / Rejected). Locks the row, so two simultaneous re-applies cannot both
     * act on it. Run inside a transaction.
     */
    public function findForReapply(string $candidateId, string $jobId, string $connection): ?Application
    {
        return Application::on($connection)
            ->where('candidate_id', $candidateId)
            ->where('job_id', $jobId)
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->orderByDesc('created_at')
            ->lockForUpdate()
            ->first();
    }

    /**
     * A candidate applies (or is added) to a job they already have a FINISHED application for (Hired / Rejected):
     * that same application comes back instead of a second card appearing - one person, one card per job.
     * It restarts in `$stageId` (Applied, or the column the recruiter added from), counts as applied now, and its
     * old hired / rejected dates and internal rejection reason are cleared (this is a fresh attempt; what happened
     * before stays in the activity log). Stage rules are the same as moveStage(): landing in Rejected / Hired sets the
     * status and date accordingly (a knockout answer can reject the new attempt straight away).
     * The caller writes the activity entry and runs this inside its own transaction.
     *
     * @param array $extra  more columns to set, e.g. ['application_data' => ..., 'rejection_reason_internal' => ...]
     * @throws \RuntimeException when the stage does not belong to this application's job
     */
    public function reopen(Application $application, string $stageId, string $connection, array $extra = []): Application
    {
        $stage = PipelineStage::on($connection)->where('job_id', $application->job_id)->find($stageId);

        if (!$stage) {
            throw new \RuntimeException('That stage does not belong to this job.');
        }

        $status = match ($stage->name) {
            'Hired' => 'hired',
            'Rejected' => 'rejected',
            default => 'active',
        };

        $application->update([
            'stage_id' => $stage->id,
            'status' => $status,
            'applied_at' => now(),
            'hired_at' => $status === 'hired' ? now() : null,
            'rejected_at' => $status === 'rejected' ? now() : null,
            'rejection_reason_internal' => null,
            'lock_version' => $application->lock_version + 1,
            ...$extra,
        ]);

        return $application->fresh();
    }

    /**
     * PRD Section 30-33 + Section 98 — moves a candidate's application to
     * a different pipeline stage. This is a Kanban board (any-to-any
     * movement), unlike Job's strict linear status lifecycle — the PRD
     * never restricts which stage a candidate can move to/from, so no
     * transition map is enforced here (deliberate difference from
     * JobService::transitionStatus()).
     *
     * @throws OptimisticLockConflictException if lock_version doesn't match (Section 98)
     * @throws \RuntimeException if the target stage doesn't belong to this application's job
     */
    public function moveStage(
        Application $application,
        string $newStageId,
        int $submittedLockVersion,
        ?string $rejectionReason,
        string $actorId,
        string $connection
    ): Application {
        if ($application->lock_version !== $submittedLockVersion) {
            throw new OptimisticLockConflictException(
                'This candidate was already moved by someone else. Please refresh and try again.'
            );
        }

        $newStage = PipelineStage::on($connection)
            ->where('job_id', $application->job_id)
            ->find($newStageId);

        if (!$newStage) {
            throw new \RuntimeException('That stage does not belong to this job.');
        }

        $oldStage = PipelineStage::on($connection)->find($application->stage_id);

        $updates = [
            'stage_id' => $newStage->id,
            'lock_version' => $application->lock_version + 1,
        ];

        // Protected stages drive the application's overall status —
        // matched by name since Applied/Hired/Rejected can never be
        // renamed (PipelineStageService::rename() blocks it).
        if ($newStage->name === 'Hired') {
            $updates['status'] = 'hired';
            $updates['hired_at'] = now();
        } elseif ($newStage->name === 'Rejected') {
            $updates['status'] = 'rejected';
            $updates['rejected_at'] = now();
            // The reason belongs to THIS rejection: a new rejection without one clears an older reason.
            $updates['rejection_reason_internal'] = $rejectionReason ?: null;
        } else {
            // Moving into (or back into) a non-terminal stage — e.g.
            // correcting an accidental Reject. hired_at/rejected_at are
            // deliberately NOT cleared — they stay as a historical record
            // even if the decision is later reversed.
            $updates['status'] = 'active';
        }

        // A card coming back to a live stage (e.g. a Hired/Rejected one dragged back to Applied) becomes ACTIVE again.
        // The database allows only ONE active application per candidate and job, so say it in plain words instead of
        // letting the unique index answer with a 500.
        if ($updates['status'] === 'active' && $application->status !== 'active') {
            $this->assertNoOtherActive($application, $connection);
        }

        DB::connection($connection)->transaction(function () use ($application, $updates, $oldStage, $newStage, $actorId, $connection) {
            try {
                $application->update($updates);
            } catch (UniqueConstraintViolationException $e) {
                // Someone else made another application active between the check above and this write.
                throw new \RuntimeException(self::ALREADY_ACTIVE);
            }

            // PRD Section 38 — Application Activity Timeline
            DB::connection($connection)->table('activity')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'company_id' => $application->company_id,
                'actor_id' => $actorId,
                'action' => 'candidate.moved_stage',
                'object_type' => 'application',
                'object_id' => $application->id,
                'metadata' => json_encode([
                    'from_stage' => $oldStage?->name,
                    'to_stage' => $newStage->name,
                    // PRD Section 65 — "Rahul moved John Doe from Screening to Interview" needs the names
                    'candidate_id' => $application->candidate_id,
                    'candidate_name' => DB::connection($connection)->table('candidates')->where('id', $application->candidate_id)->value('name'),
                    'job_id' => $application->job_id,
                    'job_title' => DB::connection($connection)->table('jobs')->where('id', $application->job_id)->value('title'),
                ]),
                'created_at' => now(),
            ]);
        });

        return $application->fresh();
    }
}
