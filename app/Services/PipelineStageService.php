<?php

namespace App\Services;

use App\Models\Job;
use App\Models\PipelineStage;
use App\Support\CacheVersion;
use Illuminate\Support\Facades\DB;

class PipelineStageService
{
    /**
     * PRD Section 30 — adds a new custom stage. Always inserted just
     * before Hired/Rejected (i.e. at the end of the custom-stage run) by
     * default; use reorder() afterward to reposition it.
     */
    public function add(Job $job, string $name, string $connection): PipelineStage
    {
        $stage = DB::connection($connection)->transaction(function () use ($job, $name, $connection) {
            $stage = PipelineStage::on($connection)->create([
                'job_id' => $job->id,
                'name' => $name,
                'order' => 0, // temporary — resequence() fixes this immediately
                'protected' => false,
            ]);

            $this->resequence($job, $connection);

            return $stage;
        });

        $this->forgetJobCache($job);

        return $stage->fresh();
    }

    /**
     * @throws \RuntimeException if the stage is protected
     */
    public function rename(PipelineStage $stage, string $newName, string $connection): PipelineStage
    {
        if ($stage->protected) {
            throw new \RuntimeException('Applied, Hired, and Rejected are protected stages and cannot be renamed.');
        }

        $stage->update(['name' => $newName]);
        $this->forgetJobCacheById($stage->job_id, $connection);

        return $stage->fresh();
    }

    /**
     * Reorders CUSTOM stages only — Applied/Hired/Rejected's positions
     * are recalculated automatically (first, and last-two) regardless of
     * what's submitted.
     *
     * @param array $orderedCustomStageIds Custom stage IDs in the new desired order
     * @throws \RuntimeException if any submitted ID doesn't belong to this job or is protected
     */
    public function reorder(Job $job, array $orderedCustomStageIds, string $connection): void
    {
        $customStages = PipelineStage::on($connection)
            ->where('job_id', $job->id)
            ->where('protected', false)
            ->get()
            ->keyBy('id');

        foreach ($orderedCustomStageIds as $id) {
            if (!$customStages->has($id)) {
                throw new \RuntimeException("Stage {$id} does not belong to this job or is a protected stage.");
            }
        }

        if (count($orderedCustomStageIds) !== $customStages->count()) {
            throw new \RuntimeException('The reorder list must include every custom stage on this job exactly once.');
        }

        $this->resequence($job, $connection, $orderedCustomStageIds);
        $this->forgetJobCache($job);
    }

    /**
     * @throws \RuntimeException if the stage is protected, or has active applications in it
     */
    public function delete(PipelineStage $stage, string $connection): void
    {
        if ($stage->protected) {
            throw new \RuntimeException('Applied, Hired, and Rejected are protected stages and cannot be deleted.');
        }

        // Safety check — never delete a stage out from under candidates
        // currently sitting in it (the `applications` table already
        // exists in the schema even though the Candidate Pipeline feature
        // itself isn't built yet — this check is forward-safe regardless).
        $hasApplications = DB::connection($connection)->table('applications')
            ->where('stage_id', $stage->id)
            ->exists();

        if ($hasApplications) {
            throw new \RuntimeException('This stage has candidates in it. Move them to another stage before deleting it.');
        }

        $jobId = $stage->job_id;
        $stage->delete();

        $job = \App\Models\Job::on($connection)->find($jobId);
        if ($job) {
            $this->resequence($job, $connection);
            $this->forgetJobCache($job);
        }
    }

    /**
     * Single source of truth for stage ordering: Applied is always first,
     * Hired + Rejected always occupy the last two positions, custom
     * stages fill everything in between (in either their existing DB
     * order, or the order explicitly provided via reorder()).
     */
    protected function resequence(Job $job, string $connection, ?array $customOrderIds = null): void
    {
        $stages = PipelineStage::on($connection)->where('job_id', $job->id)->get();

        $applied = $stages->firstWhere('name', 'Applied');
        $hired = $stages->firstWhere('name', 'Hired');
        $rejected = $stages->firstWhere('name', 'Rejected');
        $custom = $stages->where('protected', false);

        if ($customOrderIds !== null) {
            $custom = collect($customOrderIds)->map(fn ($id) => $custom->firstWhere('id', $id))->filter();
        } else {
            $custom = $custom->sortBy('order');
        }

        $order = 1;

        if ($applied) {
            PipelineStage::on($connection)->where('id', $applied->id)->update(['order' => $order++]);
        }

        foreach ($custom as $stage) {
            PipelineStage::on($connection)->where('id', $stage->id)->update(['order' => $order++]);
        }

        if ($hired) {
            PipelineStage::on($connection)->where('id', $hired->id)->update(['order' => $order++]);
        }

        if ($rejected) {
            PipelineStage::on($connection)->where('id', $rejected->id)->update(['order' => $order++]);
        }
    }

    protected function forgetJobCache(Job $job): void
    {
        CacheVersion::bump("company:{$job->company_id}:jobs");
    }

    protected function forgetJobCacheById(string $jobId, string $connection): void
    {
        $job = Job::on($connection)->find($jobId);
        if ($job) {
            $this->forgetJobCache($job);
        }
    }
}
