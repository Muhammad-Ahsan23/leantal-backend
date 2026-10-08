<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Candidates\AssignCandidateRequest;
use App\Http\Requests\Candidates\CreateCandidateRequest;
use App\Http\Requests\Candidates\UpdateCandidateRequest;
use App\Http\Requests\Candidates\CreateNoteRequest;
use App\Models\Candidate;
use App\Models\Job;
use App\Models\Note;
use App\Models\User;
use App\Services\CandidateService;
use App\Support\Roles;
use App\Support\CacheVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CandidateController extends Controller
{
    public function __construct(protected CandidateService $candidates) {}

    /**
     * PRD Sections 21/37/99/143/155 — the Candidates list.
     *
     * Each candidate comes with ALL their applications (job + pipeline stage), because one person can
     * apply to several jobs (Section 32). For screens that want a single line, the most relevant
     * application is also flattened onto the candidate as job_id / job / stage / applied_at: the one
     * matching the Job/Stage filter when one is chosen, otherwise the most recent.
     *
     * Query parameters:
     *   search           name, email or phone (Section 99)
     *   status           active (default) | archived | all   — the ONLY two candidate statuses (Section 37)
     *   stage            pipeline stage name (hiring progress lives on the application, not the candidate)
     *   job_id           (Owner/HM only) candidates with an application for this job
     *   assigned_user_id (Owner/HM only) a user's id, or "unassigned"
     *
     * Job and Person filters are Owner/HM-only (Sections 7, 8, 155: "Recruiters do not get company-wide
     * people/job dropdown filters") — for a Recruiter they are ignored here, not just hidden in the UI
     * (Section 144). What a Recruiter can see at all is decided by Candidate::scopeVisibleTo().
     *
     * Deliberately NOT cached: a candidate's stage changes on every Kanban drag, and a 60-second-old
     * stage in this list would look like a bug (Section 30: several people work at the same time).
     */
    public function index(Request $request)
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,archived,all'],
            'stage' => ['nullable', 'string', 'max:100'],
            'job_id' => ['nullable', 'uuid'],
            'assigned_user_id' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $request->user();
        $connection = $user->getConnectionName();
        $isManager = in_array($user->role, Roles::MANAGEMENT, true);

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status', 'active');
        $stage = trim((string) $request->query('stage', ''));
        $jobId = $isManager ? $request->query('job_id') : null;
        $assignedTo = $isManager ? $request->query('assigned_user_id') : null;

        if ($assignedTo && $assignedTo !== 'unassigned' && !Str::isUuid($assignedTo)) {
            return response()->json(['message' => 'The selected person is not valid.'], 422);
        }

        $query = Candidate::on($connection)
            ->visibleTo($user)
            ->with([
                'assignedUser:id,name',
                'applications' => fn ($q) => $q->orderByDesc('applied_at'),
                'applications.job:id,title',
                'applications.stage:id,name',
            ]);

        if ($status !== 'all') {
            $query->where(function ($q) use ($status) {
                $q->where('status', $status);
                if ($status === 'active') {
                    $q->orWhereNull('status'); // a record with no status yet is an active one
                }
            });
        }

        if ($search !== '') {
            $like = '%'.$this->escapeLike($search).'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'ilike', $like)
                  ->orWhere('email', 'ilike', $like)
                  ->orWhere('phone', 'ilike', $like);
            });
        }

        if ($jobId || $stage !== '') {
            // ONE application must satisfy both (job AND stage), not one each.
            $query->whereHas('applications', function ($q) use ($jobId, $stage) {
                if ($jobId) {
                    $q->where('job_id', $jobId);
                }
                if ($stage !== '') {
                    $q->whereHas('stage', fn ($s) => $s->where('name', 'ilike', $this->escapeLike($stage)));
                }
            });
        }

        if ($assignedTo === 'unassigned') {
            $query->whereNull('assigned_user_id');
        } elseif ($assignedTo) {
            $query->where('assigned_user_id', $assignedTo);
        }

        $candidates = $query->orderByDesc('created_at')->get()->map(function (Candidate $c) use ($jobId, $stage) {
            $primary = $c->applications->first(function ($a) use ($jobId, $stage) {
                return (!$jobId || $a->job_id === $jobId)
                    && ($stage === '' || strcasecmp((string) $a->stage?->name, $stage) === 0);
            }) ?? $c->applications->first();

            $c->setAttribute('job_id', $primary?->job_id);
            $c->setAttribute('job', $primary?->job);
            $c->setAttribute('stage', $primary?->stage);
            $c->setAttribute('applied_at', $primary?->applied_at);
            $c->setAttribute('application_count', $c->applications->count());

            return $c;
        })->values();

        return response()->json(['candidates' => $candidates]);
    }

    /** Makes %, _ and \ in what the user typed match literally inside LIKE/ILIKE. */
    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
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

        // No job chosen: only add the candidate (see CreateCandidateRequest). They have no application yet.
        if (empty($data['job_id'])) {
            try {
                $candidate = $this->candidates->createWithoutJob($data, $user, $connection, $request->file('resume'));
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json(['candidate' => $candidate, 'application' => null], 201);
        }

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
    /**
     * PRD Section 7 ("Edit permitted candidate information") and Section 72 (GDPR correction).
     * Owner / Hiring Manager: any candidate. Recruiter: only candidates they can access
     * (CandidatePolicy::update). An archived candidate can still be corrected.
     */
    public function update(UpdateCandidateRequest $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $candidate = Candidate::on($connection)->find($id);

        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found.'], 404);
        }

        if (!$user->can('update', $candidate)) {
            return response()->json(['message' => 'You do not have permission to edit this candidate.'], 403);
        }

        $candidate = $this->candidates->updateProfile($candidate, $request->validated(), $user, $connection);

        return response()->json(['candidate' => $candidate]);
    }

    /**
     * Reverse of archive(). Same rule as archiving (PRD permissions table: Owner Yes, Hiring Manager
     * Yes, Recruiter No) — whoever may archive a candidate may bring them back.
     */
    public function restore(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $candidate = Candidate::on($connection)->find($id);

        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found.'], 404);
        }

        if (!$user->can('archive', $candidate)) {
            return response()->json(['message' => 'You do not have permission to restore this candidate.'], 403);
        }

        $candidate = $this->candidates->restore($candidate, $user, $connection);

        return response()->json(['candidate' => $candidate]);
    }

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
