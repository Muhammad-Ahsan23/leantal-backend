<?php

namespace App\Services;

use App\Exceptions\OptimisticLockConflictException;
use App\Models\Application;
use App\Models\PipelineStage;
use Illuminate\Support\Facades\DB;

class ApplicationService
{
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
            if ($rejectionReason) {
                $updates['rejection_reason_internal'] = $rejectionReason;
            }
        } else {
            // Moving into (or back into) a non-terminal stage — e.g.
            // correcting an accidental Reject. hired_at/rejected_at are
            // deliberately NOT cleared — they stay as a historical record
            // even if the decision is later reversed.
            $updates['status'] = 'active';
        }

        DB::connection($connection)->transaction(function () use ($application, $updates, $oldStage, $newStage, $actorId, $connection) {
            $application->update($updates);

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
                ]),
                'created_at' => now(),
            ]);
        });

        return $application->fresh();
    }
}
