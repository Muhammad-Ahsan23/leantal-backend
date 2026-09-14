<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Candidates\AssignCandidateRequest;
use App\Http\Requests\Candidates\CreateCandidateRequest;
use App\Http\Requests\Candidates\CreateNoteRequest;
use App\Models\Candidate;
use App\Models\Job;
use App\Models\Note;
use App\Models\User;
use App\Services\CandidateService;
use App\Support\CacheVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CandidateController extends Controller
{
    public function __construct(protected CandidateService $candidates) {}

    /**
     * Same Redis caching pattern as JobController::index() — 60s TTL,
     * tag-based per-company invalidation. Kept intentionally OUT of the
     * Kanban board endpoint (ApplicationController::index()) though —
     * that data needs to be live for multi-recruiter collaboration
     * (Section 30), while this plain candidate list tolerates a
     * 60-second staleness window fine.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $search = $request->query('search');
        $version = CacheVersion::get("company:{$user->company_id}:candidates");
        $cacheKey = "candidates:v{$version}:".$user->id.':'.md5($search ?? '');

        $candidates = Cache::remember($cacheKey, 60, function () use ($user, $connection, $search) {
            $query = Candidate::on($connection)->visibleTo($user)->with('assignedUser:id,name');

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', "%{$search}%")
                      ->orWhere('email', 'ilike', "%{$search}%");
                });
            }

            return $query->orderByDesc('created_at')->get();
        });

        return response()->json(['candidates' => $candidates]);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $candidate = Candidate::on($connection)->with(['assignedUser:id,name', 'applications.job:id,title', 'applications.stage'])->find($id);

        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found.'], 404);
        }

        if (!$user->can('view', $candidate)) {
            return response()->json(['message' => 'You do not have permission to view this candidate.'], 403);
        }

        return response()->json(['candidate' => $candidate]);
    }

    /**
     * PRD Section 41 — all three roles can add candidates (unlike Jobs,
     * which is Owner/HM only). Section 133/135 dedup + duplicate-
     * application logic lives in CandidateService.
     */
    public function store(CreateCandidateRequest $request)
    {
        $user = $request->user();

        if (!$user->can('create', Candidate::class)) {
            return response()->json(['message' => 'You do not have permission to add candidates.'], 403);
        }

        $connection = $user->getConnectionName();
        $data = $request->validated();

        $job = Job::on($connection)->where('company_id', $user->company_id)->find($data['job_id']);

        if (!$job) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        try {
            [$candidate, $application] = $this->candidates->addToJob($data, $job, $user, $connection, $request->file('resume'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'candidate' => $candidate,
            'application' => $application,
        ], 201);
    }

    /**
     * PRD Section 37 — reversible archive. Owner/HM only (CandidatePolicy).
     */
    public function archive(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $candidate = Candidate::on($connection)->find($id);

        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found.'], 404);
        }

        if (!$user->can('archive', $candidate)) {
            return response()->json(['message' => 'You do not have permission to archive this candidate.'], 403);
        }

        $candidate = $this->candidates->archive($candidate, $user, $connection);

        return response()->json(['candidate' => $candidate]);
    }

    /**
     * PRD Section 37 — soft-delete only, never a hard DELETE from the DB.
     * Owner/HM only (CandidatePolicy).
     */
    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $candidate = Candidate::on($connection)->find($id);

        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found.'], 404);
        }

        if (!$user->can('delete', $candidate)) {
            return response()->json(['message' => 'You do not have permission to delete this candidate.'], 403);
        }

        $this->candidates->softDelete($candidate, $user, $connection);

        return response()->json(['message' => 'Candidate deleted.']);
    }

    /**
     * PRD Section 8 — reassigning a candidate to a different team member.
     * Owner/HM only (CandidatePolicy) — matches "Assign candidate" in the
     * permission matrix.
     */
    public function assign(AssignCandidateRequest $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $candidate = Candidate::on($connection)->find($id);

        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found.'], 404);
        }

        if (!$user->can('assign', $candidate)) {
            return response()->json(['message' => 'You do not have permission to reassign this candidate.'], 403);
        }

        $targetUserId = $request->validated()['assigned_user_id'];
        $targetUser = User::on($connection)->where('company_id', $user->company_id)->find($targetUserId);

        if (!$targetUser) {
            return response()->json(['message' => 'That user was not found in your company.'], 422);
        }

        $candidate = $this->candidates->assign($candidate, $targetUserId, $user, $connection);

        return response()->json(['candidate' => $candidate]);
    }

    /**
     * PRD Section 36 — internal notes, never visible to the candidate.
     * Same visibility rule as viewing the candidate itself — if a
     * Recruiter can see this candidate (i.e. it's assigned to them),
     * they can add/view notes on it.
     */
    public function listNotes(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $candidate = Candidate::on($connection)->find($id);

        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found.'], 404);
        }

        if (!$user->can('view', $candidate)) {
            return response()->json(['message' => 'You do not have permission to view this candidate.'], 403);
        }

        $notes = Note::on($connection)->where('candidate_id', $id)
            ->with('author:id,name')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['notes' => $notes]);
    }

    /**
     * R2 disks are private (never publicly browsable), so staff need a
     * short-lived signed URL to actually download a resume — this is
     * the standard pattern for private S3-compatible buckets.
     */
    public function resumeUrl(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $candidate = Candidate::on($connection)->find($id);

        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found.'], 404);
        }

        if (!$user->can('view', $candidate)) {
            return response()->json(['message' => 'You do not have permission to view this candidate.'], 403);
        }

        if (!$candidate->resume_path) {
            return response()->json(['message' => 'This candidate has no resume on file.'], 404);
        }

        $url = \Illuminate\Support\Facades\Storage::disk($candidate->resume_disk)
            ->temporaryUrl($candidate->resume_path, now()->addMinutes(10));

        return response()->json(['url' => $url, 'filename' => $candidate->resume_original_name]);
    }

    public function addNote(CreateNoteRequest $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $candidate = Candidate::on($connection)->find($id);

        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found.'], 404);
        }

        if (!$user->can('update', $candidate)) {
            return response()->json(['message' => 'You do not have permission to add notes to this candidate.'], 403);
        }

        $note = $this->candidates->addNote($candidate, $request->validated()['body'], $user, $connection);

        return response()->json(['note' => $note], 201);
    }

    /**
     * PRD Section 38 — Application Activity Timeline. Pulls every
     * activity row tied either directly to this candidate (e.g. notes,
     * archive, reassignment) or to any of their applications (e.g. stage
     * moves) — both were logged under different object_type values by
     * CandidateService / ApplicationService.
     */
    public function activity(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $candidate = Candidate::on($connection)->find($id);

        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found.'], 404);
        }

        if (!$user->can('view', $candidate)) {
            return response()->json(['message' => 'You do not have permission to view this candidate.'], 403);
        }

        $applicationIds = $candidate->applications()->pluck('id');

        $activity = DB::connection($connection)->table('activity')
            ->where(function ($q) use ($id, $applicationIds) {
                $q->where(['object_type' => 'candidate', 'object_id' => $id])
                  ->orWhere(function ($q2) use ($applicationIds) {
                      $q2->where('object_type', 'application')->whereIn('object_id', $applicationIds);
                  });
            })
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['activity' => $activity]);
    }
}
