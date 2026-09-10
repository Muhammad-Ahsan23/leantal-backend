<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Candidates\CreateCandidateRequest;
use App\Models\Candidate;
use App\Models\Job;
use App\Services\CandidateService;
use Illuminate\Http\Request;

class CandidateController extends Controller
{
    public function __construct(protected CandidateService $candidates) {}

    /**
     * PRD Section 142 — visibility scoped via Candidate::scopeVisibleTo()
     * (Owner/HM see all, Recruiter sees only assigned), same pattern as Jobs.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        $query = Candidate::on($connection)->visibleTo($user)->with('assignedUser:id,name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $candidates = $query->orderByDesc('created_at')->get();

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
            [$candidate, $application] = $this->candidates->addToJob($data, $job, $user, $connection);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'candidate' => $candidate,
            'application' => $application,
        ], 201);
    }
}
