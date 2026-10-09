<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OptimisticLockConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\MoveStageRequest;
use App\Models\Application;
use App\Models\ApplicationAnswer;
use App\Models\ApplicationQuestion;
use App\Models\Candidate;
use App\Models\Job;
use App\Services\ApplicationService;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
     * PRD Sec 28/94 — what the candidate answered on the application form. Same visibility rule as the
     * application itself (a Recruiter only sees candidates assigned to them). Returns EVERY question of the
     * job, in order, so an unanswered optional question shows as unanswered instead of silently vanishing.
     */
    public function answers(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $application = Application::on($connection)->with('candidate')->find($id);

        if (!$application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        if (!$user->can('view', $application->candidate)) {
            return response()->json(['message' => 'You do not have permission to view this application.'], 403);
        }

        $questions = ApplicationQuestion::on($connection)->where('job_id', $application->job_id)->orderBy('order')->get();
        $answers = ApplicationAnswer::on($connection)->where('application_id', $application->id)->get()->keyBy('question_id');

        $rows = $questions->map(function ($question) use ($answers) {
            $answer = $answers->get($question->id);
            $hasFile = $answer && $answer->answer_file_path;

            $value = null;
            if ($answer && !$hasFile) {
                $value = $answer->answer_text;
                if ($question->type === 'multiple_choice') {
                    $decoded = json_decode((string) $value, true);
                    $value = is_array($decoded) ? $decoded : [$value];
                }
            }

            return [
                'question_id' => $question->id,
                'question' => $question->question,
                'type' => $question->type,
                'answer_id' => $answer?->id,
                'answer' => $value,
                'file_name' => $hasFile ? $answer->answer_text : null,
            ];
        })->values();

        return response()->json(['answers' => $rows]);
    }

    /** A short-lived download link for an uploaded answer file — same pattern as the candidate's resume. */
    public function answerFileUrl(Request $request, string $id, string $answerId)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $application = Application::on($connection)->with('candidate')->find($id);

        if (!$application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        if (!$user->can('view', $application->candidate)) {
            return response()->json(['message' => 'You do not have permission to view this application.'], 403);
        }

        $answer = ApplicationAnswer::on($connection)
            ->where('id', $answerId)
            ->where('application_id', $application->id)
            ->first();

        if (!$answer || !$answer->answer_file_path) {
            return response()->json(['message' => 'No file on this answer.'], 404);
        }

        $url = Storage::disk($answer->answer_file_disk)->temporaryUrl($answer->answer_file_path, now()->addMinutes(10));

        return response()->json(['url' => $url, 'filename' => $answer->answer_text]);
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
