<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Interviews\CreateInterviewRequest;
use App\Http\Requests\Interviews\UpdateInterviewRequest;
use App\Models\Candidate;
use App\Models\Interview;
use App\Models\Job;
use App\Models\User;
use App\Services\InterviewService;
use App\Support\Roles;
use Illuminate\Http\Request;

class InterviewController extends Controller
{
    public function __construct(protected InterviewService $interviews) {}

    /**
     * PRD Section 59 — the calendar view's data source. Owner/HM see
     * every interview in the company; Recruiters see only interviews
     * THEY are organizing ("my calendar"), matching how Tasks scope by
     * assigned_user_id. Date-range filterable for month/week/day views.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        $query = Interview::on($connection)
            ->where('company_id', $user->company_id)
            ->with(['candidate:id,name', 'job:id,title', 'organizer:id,name']);

        if (!in_array($user->role, Roles::MANAGEMENT, true)) {
            $query->where('organizer_id', $user->id);
        }

        if ($from = $request->query('from')) {
            $query->where('start_time', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('start_time', '<=', $to);
        }

        return response()->json(['interviews' => $query->orderBy('start_time')->get()]);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $interview = Interview::on($connection)->with(['candidate', 'job:id,title', 'organizer:id,name'])->find($id);

        if (!$interview) {
            return response()->json(['message' => 'Interview not found.'], 404);
        }

        if (!$user->can('view', $interview->candidate)) {
            return response()->json(['message' => 'You do not have permission to view this interview.'], 403);
        }

        return response()->json(['interview' => $interview]);
    }

    /**
     * PRD Section 60 — same access rule as adding a note: if you can
     * work with this candidate (CandidatePolicy::update), you can
     * schedule an interview for them. No separate InterviewPolicy —
     * would just duplicate the same rule.
     */
    public function store(CreateInterviewRequest $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $data = $request->validated();

        $candidate = Candidate::on($connection)->where('company_id', $user->company_id)->find($data['candidate_id']);
        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found.'], 404);
        }

        if (!$user->can('update', $candidate)) {
            return response()->json(['message' => 'You do not have permission to schedule interviews for this candidate.'], 403);
        }

        $job = Job::on($connection)->where('company_id', $user->company_id)->find($data['job_id']);
        if (!$job) {
            return response()->json(['message' => 'Job not found.'], 422);
        }

        $organizer = User::on($connection)->where('company_id', $user->company_id)->find($data['organizer_id']);
        if (!$organizer) {
            return response()->json(['message' => 'That organizer was not found in your company.'], 422);
        }

        // The link is created from the organizer's calendar, so refuse up front when that is impossible.
        try {
            $this->interviews->assertCanCreateMeetingLink($data['provider'], $organizer, $user, $connection);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $interview = $this->interviews->schedule($data, $user->company_id, $user, $connection);

        return response()->json(['interview' => $interview->load(['candidate:id,name', 'job:id,title', 'organizer:id,name'])], 201);
    }

    public function update(UpdateInterviewRequest $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $interview = Interview::on($connection)->with('candidate')->find($id);

        if (!$interview) {
            return response()->json(['message' => 'Interview not found.'], 404);
        }

        if (!$user->can('update', $interview->candidate)) {
            return response()->json(['message' => 'You do not have permission to reschedule this interview.'], 403);
        }

        $interview = $this->interviews->reschedule($interview, $request->validated(), $user, $connection);

        return response()->json(['interview' => $interview]);
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $interview = Interview::on($connection)->with('candidate')->find($id);

        if (!$interview) {
            return response()->json(['message' => 'Interview not found.'], 404);
        }

        if (!$user->can('update', $interview->candidate)) {
            return response()->json(['message' => 'You do not have permission to cancel this interview.'], 403);
        }

        $this->interviews->cancel($interview, $user, $connection);

        return response()->json(['message' => 'Interview cancelled.']);
    }
}
