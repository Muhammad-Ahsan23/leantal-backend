<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Job;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class JobService
{
    /**
     * PRD Section 22/92 — only these transitions are allowed. Prevents
     * nonsensical jumps like Draft -> Closed or Archived -> Published
     * (Archived is terminal; re-activating an archived job means creating
     * a new one, per the simplicity principle running through the PRD).
     */
    protected const TRANSITIONS = [
        'draft' => ['published', 'archived'],
        'published' => ['paused', 'closed'],
        'paused' => ['published', 'closed'],
        'closed' => ['archived'],
        'archived' => [],
    ];

    /**
     * PRD Section 30 — every job gets these 3 protected stages
     * automatically. Custom stages (Screening, Interview, etc.) are added
     * separately by the company — never auto-created.
     */
    protected const PROTECTED_STAGES = ['Applied', 'Hired', 'Rejected'];

    public function create(array $data, User $creator, string $connection): Job
    {
        $company = Company::on($connection)->find($creator->company_id);
        $this->assertJobLimitNotReached($company, $connection);

        $job = DB::connection($connection)->transaction(function () use ($data, $creator, $connection) {
            $job = Job::on($connection)->create([
                ...$data,
                'company_id' => $creator->company_id,
                'created_by' => $creator->id,
                'status' => $data['publish'] ?? false ? 'published' : 'draft',
                'published_at' => ($data['publish'] ?? false) ? now() : null,
            ]);

            foreach (self::PROTECTED_STAGES as $i => $name) {
                PipelineStage::on($connection)->create([
                    'job_id' => $job->id,
                    'name' => $name,
                    'order' => $i + 1,
                    'protected' => true,
                ]);
            }

            return $job;
        });

        $this->forgetJobsCache($creator->company_id);

        return $job;
    }

    public function update(Job $job, array $data, string $connection): Job
    {
        $job->update($data);
        $this->forgetJobsCache($job->company_id);

        return $job->fresh();
    }

    /**
     * @throws \InvalidArgumentException if the transition isn't allowed
     */
    public function transitionStatus(Job $job, string $newStatus, string $connection): Job
    {
        $allowed = self::TRANSITIONS[$job->status] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Cannot move a job from '{$job->status}' to '{$newStatus}'."
            );
        }

        if ($newStatus === 'published') {
            $company = Company::on($connection)->find($job->company_id);
            // Re-publishing (e.g. from Paused) doesn't create a NEW job,
            // so only re-check the limit when coming from Draft.
            if ($job->status === 'draft') {
                $this->assertJobLimitNotReached($company, $connection);
            }
        }

        $updates = ['status' => $newStatus];

        if ($newStatus === 'published' && !$job->published_at) {
            $updates['published_at'] = now();
        }

        if ($newStatus === 'closed') {
            $updates['closed_at'] = now();
        }

        $job->update($updates);
        $this->forgetJobsCache($job->company_id);

        return $job->fresh();
    }

    public function assign(Job $job, string $assignedUserId): Job
    {
        $job->update(['assigned_user_id' => $assignedUserId]);
        $this->forgetJobsCache($job->company_id);

        return $job->fresh();
    }

    protected function assertJobLimitNotReached(Company $company, string $connection): void
    {
        $limit = $company->jobLimit();
        if ($limit === null) {
            return;
        }

        // "Active" per Section 91 = anything except archived/closed
        $activeCount = Job::on($connection)
            ->where('company_id', $company->id)
            ->whereIn('status', ['draft', 'published', 'paused'])
            ->count();

        if ($activeCount >= $limit) {
            throw new \RuntimeException(
                "You've reached your plan's active job limit ({$limit}). Upgrade or archive an existing job first."
            );
        }
    }

    /**
     * Redis-backed cache (tags require the 'redis' cache store — see
     * config/cache.php / .env CACHE_STORE=redis). Every mutation flushes
     * the whole company's jobs cache rather than trying to patch
     * individual keys — simpler and safe, at the cost of a slightly wider
     * cache invalidation than strictly necessary.
     */
    protected function forgetJobsCache(string $companyId): void
    {
        Cache::tags(["company:{$companyId}:jobs"])->flush();
    }
}
