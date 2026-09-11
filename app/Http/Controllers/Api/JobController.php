<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Jobs\AssignJobRequest;
use App\Http\Requests\Jobs\CreateJobRequest;
use App\Http\Requests\Jobs\UpdateJobRequest;
use App\Http\Requests\Jobs\UpdateJobStatusRequest;
use App\Models\Job;
use App\Models\User;
use App\Services\JobService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class JobController extends Controller
{
    public function __construct(protected JobService $jobs) {}

    /**
     * PRD Section 21 — list with search + filters. Uses
     * Job::scopeVisibleTo() for the RBAC-driven visibility rule
     * (Section 142: Recruiters see only their own assigned jobs).
     *
     * Cached per-user, per-filter-combination for 60s via Redis (tags
     * let JobService flush the whole company's cache on any mutation,
     * so this never serves stale data for longer than a write-to-read
     * race would already risk).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        $filters = $request->only(['status', 'department', 'search', 'assigned_user_id']);
        $cacheKey = 'jobs:'.$user->id.':'.md5(json_encode($filters));

        $jobs = Cache::tags(["company:{$user->company_id}:jobs"])->remember($cacheKey, 60, function () use ($user, $connection, $filters) {
            $query = Job::on($connection)->visibleTo($user)->with('assignedUser:id,name');

            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (!empty($filters['department'])) {
                $query->where('department', $filters['department']);
            }
            if (!empty($filters['assigned_user_id'])) {
                $query->where('assigned_user_id', $filters['assigned_user_id']);
            }
            if (!empty($filters['search'])) {
                $query->where('title', 'ilike', '%'.$filters['search'].'%');
            }

            $jobs = $query->orderByDesc('created_at')->get();

            // PRD Section 21 — "Applicant count" column. Single grouped
            // query (not one-per-job) to avoid N+1 as the jobs list grows.
            $counts = DB::connection($connection)->table('applications')
                ->select('job_id', DB::raw('count(*) as cnt'))
                ->whereIn('job_id', $jobs->pluck('id'))
                ->groupBy('job_id')
                ->pluck('cnt', 'job_id');

            $jobs->each(fn ($job) => $job->applicants_count = $counts[$job->id] ?? 0);

            return $jobs;
        });

        return response()->json(['jobs' => $jobs]);
    }

    public function store(CreateJobRequest $request)
    {
        $user = $request->user();

        if (!$user->can('create', Job::class)) {
            return response()->json(['message' => 'You do not have permission to create jobs.'], 403);
        }

        $connection = $user->getConnectionName();

        try {
            $job = $this->jobs->create($request->validated(), $user, $connection);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['job' => $job->load('pipelineStages')], 201);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $job = Job::on($connection)->with(['pipelineStages', 'assignedUser:id,name', 'creator:id,name'])->find($id);

        if (!$job) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        if (!$user->can('view', $job)) {
            return response()->json(['message' => 'You do not have permission to view this job.'], 403);
        }

        $job->applicants_count = DB::connection($connection)->table('applications')->where('job_id', $job->id)->count();

        return response()->json(['job' => $job]);
    }

    public function update(UpdateJobRequest $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $job = Job::on($connection)->find($id);

        if (!$job) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        if (!$user->can('update', $job)) {
            return response()->json(['message' => 'You do not have permission to edit this job.'], 403);
        }

        $job = $this->jobs->update($job, $request->validated(), $connection);

        return response()->json(['job' => $job]);
    }

    /**
     * PRD Section 22/92 — status lifecycle (Draft/Published/Paused/
     * Closed/Archived), enforced via JobService::TRANSITIONS so an
     * invalid jump (e.g. Draft -> Closed) is rejected with a clear error.
     */
    public function updateStatus(UpdateJobStatusRequest $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $job = Job::on($connection)->find($id);

        if (!$job) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        if (!$user->can('update', $job)) {
            return response()->json(['message' => 'You do not have permission to change this job\'s status.'], 403);
        }

        try {
            $job = $this->jobs->transitionStatus($job, $request->validated()['status'], $connection);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['job' => $job]);
    }

    public function assign(AssignJobRequest $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $job = Job::on($connection)->find($id);

        if (!$job) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        if (!$user->can('assign', $job)) {
            return response()->json(['message' => 'You do not have permission to assign this job.'], 403);
        }

        $targetUserId = $request->validated()['assigned_user_id'];
        $targetUser = User::on($connection)->where('company_id', $user->company_id)->find($targetUserId);

        if (!$targetUser) {
            return response()->json(['message' => 'That user was not found in your company.'], 422);
        }

        $job = $this->jobs->assign($job, $targetUserId, $connection);

        return response()->json(['job' => $job]);
    }
}
