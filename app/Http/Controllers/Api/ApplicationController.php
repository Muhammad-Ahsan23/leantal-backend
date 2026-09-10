<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OptimisticLockConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\MoveStageRequest;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\Job;
use App\Services\ApplicationService;
use App\Support\Roles;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    public function __construct(protected ApplicationService $applications) {}

    /**
     * The actual Kanban board data for a job — every application, with
     * its candidate and stage. PRD Section 142 — Recruiters only see
     * applications for candidates ASSIGNED TO THEM, even within a job
     * they can otherwise see cards on.
     */
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

        $query = Application::on($connection)
            ->where('job_id', $jobId)
            ->with(['candidate:id,name,email,current_title,current_company,assigned_user_id', 'stage']);

        if (!in_array($user->role, Roles::MANAGEMENT, true)) {
            $query->whereHas('candidate', fn ($q) => $q->where('assigned_user_id', $user->id));
        }

        return response()->json(['applications' => $query->get()]);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $application = Application::on($connection)->with(['candidate', 'job:id,title', 'stage'])->find($id);

        if (!$application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        if (!$user->can('view', $application->candidate)) {
            return response()->json(['message' => 'You do not have permission to view this application.'], 403);
        }

        return response()->json(['application' => $application]);
    }

    /**
     * PRD Section 8 — "Move candidate" permission is CANDIDATE-scoped
     * (Owner/HM always, Recruiter only if the candidate is assigned to
     * them) — not job-scoped, so this reuses CandidatePolicy, not JobPolicy.
     */
    public function moveStage(MoveStageRequest $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $application = Application::on($connection)->with('candidate')->find($id);

        if (!$application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        if (!$user->can('update', $application->candidate)) {
            return response()->json(['message' => 'You do not have permission to move this candidate.'], 403);
        }

        $data = $request->validated();

        try {
            $application = $this->applications->moveStage(
                $application,
                $data['stage_id'],
                $data['lock_version'],
                $data['rejection_reason_internal'] ?? null,
                $user->id,
                $connection
            );
        } catch (OptimisticLockConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['application' => $application]);
    }
}
