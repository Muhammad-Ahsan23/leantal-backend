<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Jobs\CreateStageRequest;
use App\Http\Requests\Jobs\ReorderStagesRequest;
use App\Http\Requests\Jobs\UpdateStageRequest;
use App\Models\Job;
use App\Models\PipelineStage;
use App\Services\PipelineStageService;
use Illuminate\Http\Request;

class PipelineStageController extends Controller
{
    public function __construct(protected PipelineStageService $stages) {}

    public function index(Request $request, string $jobId)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $job = Job::on($connection)->find($jobId);

        if (!$job) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        if (!$user->can('view', $job)) {
            return response()->json(['message' => 'You do not have permission to view this job.'], 403);
        }

        return response()->json(['stages' => $job->pipelineStages]);
    }

    /**
     * PRD Section 30 — stage management follows the same permission rule
     * as editing the job itself (Owner/HM only) — the PRD doesn't define
     * a separate ability for pipeline stages.
     */
    public function store(CreateStageRequest $request, string $jobId)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $job = Job::on($connection)->find($jobId);

        if (!$job) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        if (!$user->can('update', $job)) {
            return response()->json(['message' => 'You do not have permission to manage this job\'s pipeline.'], 403);
        }

        $stage = $this->stages->add($job, $request->validated()['name'], $connection);

        return response()->json(['stage' => $stage], 201);
    }

    public function update(UpdateStageRequest $request, string $jobId, string $stageId)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $job = Job::on($connection)->find($jobId);

        if (!$job) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        if (!$user->can('update', $job)) {
            return response()->json(['message' => 'You do not have permission to manage this job\'s pipeline.'], 403);
        }

        $stage = PipelineStage::on($connection)->where('job_id', $jobId)->find($stageId);

        if (!$stage) {
            return response()->json(['message' => 'Stage not found.'], 404);
        }

        try {
            $stage = $this->stages->rename($stage, $request->validated()['name'], $connection);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['stage' => $stage]);
    }

    public function reorder(ReorderStagesRequest $request, string $jobId)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $job = Job::on($connection)->find($jobId);

        if (!$job) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        if (!$user->can('update', $job)) {
            return response()->json(['message' => 'You do not have permission to manage this job\'s pipeline.'], 403);
        }

        try {
            $this->stages->reorder($job, $request->validated()['stage_order'], $connection);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['stages' => $job->pipelineStages()->get()]);
    }

    public function destroy(Request $request, string $jobId, string $stageId)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $job = Job::on($connection)->find($jobId);

        if (!$job) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        if (!$user->can('update', $job)) {
            return response()->json(['message' => 'You do not have permission to manage this job\'s pipeline.'], 403);
        }

        $stage = PipelineStage::on($connection)->where('job_id', $jobId)->find($stageId);

        if (!$stage) {
            return response()->json(['message' => 'Stage not found.'], 404);
        }

        try {
            $this->stages->delete($stage, $connection);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Stage deleted.']);
    }
}
