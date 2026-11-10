<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OptimisticLockConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\BulkMoveStageRequest;
use App\Http\Requests\Applications\MoveStageRequest;
use App\Models\Application;
use App\Models\ApplicationAnswer;
use App\Models\ApplicationQuestion;
use App\Models\Candidate;
use App\Models\Job;
use App\Models\PipelineStage;
use App\Services\ApplicationService;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        $withRelations = ['candidate:id,name,email,current_title,current_company,assigned_user_id', 'stage'];
        $isManagement = in_array($user->role, Roles::MANAGEMENT, true);

        // Without any of these parameters the answer is the same as it always was: every application of the job.
        $paged = $request->hasAny(['per_page', 'stage_id', 'search', 'stage_limits', 'offset']);

        if (!$paged) {
            $query = Application::on($connection)->where('job_id', $jobId)->with($withRelations);

            if (!$isManagement) {
                $query->whereHas('candidate', fn ($q) => $q->where('assigned_user_id', $user->id));
            }

            return response()->json(['applications' => $query->get()]);
        }

        // ---- Board mode: each stage shows its first page and "Load more" fetches the next one (PRD: big pipelines
        // must stay fast). Search runs here, on the server, so it also finds candidates that are not loaded yet.
        $params = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'after_applied_at' => ['nullable', 'date'],
            'after_id' => ['nullable', 'uuid'],
            'stage_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            'stage_limits' => ['nullable', 'array'],
            'stage_limits.*' => ['integer', 'min:1', 'max:500'],
        ]);

        $perPage = (int) ($params['per_page'] ?? 25);
        $search = trim((string) ($params['search'] ?? ''));
        $like = '%' . addcslashes($search, '\\%_') . '%';

        // The same filter for the page, the counts and every stage: what the user may see, minus deleted candidates.
        $base = function () use ($connection, $jobId, $isManagement, $user, $search, $like, $withRelations) {
            return Application::on($connection)
                ->where('job_id', $jobId)
                ->whereHas('candidate', function ($q) use ($isManagement, $user, $search, $like) {
                    if (!$isManagement) {
                        $q->where('assigned_user_id', $user->id);
                    }
                    if ($search !== '') {
                        $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like));
                    }
                })
                ->with($withRelations)
                // A fixed order is what makes "the next 25" mean the same thing on every request: newest first.
                ->orderByDesc('applied_at')
                ->orderByDesc('id');
        };

        $totalsFor = fn () => $base()->reorder()->setEagerLoads([])->selectRaw('stage_id, count(*) as total')
            ->groupBy('stage_id')->get()->pluck('total', 'stage_id');

        // One stage, further down: GET ...?stage_id=X&per_page=25&after_applied_at=<last card's applied_at>&after_id=<its id>
        // "After this card" (not "skip N cards"): cards move in and out of a stage while the board is open, and a count
        // would then skip or repeat cards. The pair (applied_at, id) is exactly the order used above, so the next page
        // starts right behind the last card the user has, whatever happened to the cards in front of it.
        if (!empty($params['stage_id'])) {
            $stageId = $params['stage_id'];
            $query = $base()->where('stage_id', $stageId);

            if (!empty($params['after_applied_at']) && !empty($params['after_id'])) {
                // A string, not a Carbon object: a Carbon binding would be cut to whole seconds and break the comparison.
                $at = Carbon::parse($params['after_applied_at'])->utc()->format('Y-m-d H:i:s.uP');
                $afterId = $params['after_id'];
                $query->where(fn ($w) => $w->where('applied_at', '<', $at)
                    ->orWhere(fn ($x) => $x->where('applied_at', $at)->where('id', '<', $afterId)));
            } else {
                $query->offset((int) ($params['offset'] ?? 0));
            }

            $rows = $query->limit($perPage)->get();

            return response()->json([
                'applications' => $rows,
                'stage_totals' => [$stageId => (int) ($totalsFor()[$stageId] ?? 0)],
            ]);
        }

        // Every stage at once: its first `per_page` (or the `stage_limits[stageId]` already shown, so a refresh does not
        // collapse a column the user had opened up) plus the real total of each stage.
        $stageIds = PipelineStage::on($connection)->where('job_id', $jobId)->pluck('id');
        $totals = $totalsFor();
        $limits = $params['stage_limits'] ?? [];

        $applications = collect();
        $stageTotals = [];
        foreach ($stageIds as $stageId) {
            $total = (int) ($totals[$stageId] ?? 0);
            $stageTotals[$stageId] = $total;
            if ($total === 0) {
                continue;
            }
            $applications = $applications->concat(
                $base()->where('stage_id', $stageId)->limit((int) ($limits[$stageId] ?? $perPage))->get()
            );
        }

        return response()->json([
            'applications' => $applications->values(),
            'stage_totals' => $stageTotals,
        ]);
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

    /**
     * Bulk "Move to stage" from the Jobs Kanban: up to 100 applications to ONE stage in one request.
     * Every application goes through the SAME permission check and the SAME ApplicationService::moveStage() as the
     * single move (Recruiters only on candidates assigned to them, protected stages set the status, one activity
     * entry per candidate). One that cannot be moved is reported and does not stop the rest.
     * The lock_version used is the current one: the user is saying "put these in that stage", not editing a stale card.
     */
    public function bulkMoveStage(BulkMoveStageRequest $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $data = $request->validated();

        $applications = Application::on($connection)->with('candidate')
            ->whereIn('id', $data['application_ids'])->get()->keyBy('id');

        $succeeded = 0;
        $unchanged = 0;
        $failed = [];

        foreach ($data['application_ids'] as $id) {
            $application = $applications->get($id);
            if (!$application) {
                $failed[] = ['id' => $id, 'name' => null, 'message' => 'Application not found.'];
                continue;
            }

            $name = $application->candidate?->name;

            if (!$application->candidate || !$user->can('update', $application->candidate)) {
                $failed[] = ['id' => $id, 'name' => $name, 'message' => 'You do not have permission to move this candidate.'];
                continue;
            }

            if ($application->stage_id === $data['stage_id']) {
                $unchanged++;
                continue;
            }

            try {
                $this->applications->moveStage(
                    $application,
                    $data['stage_id'],
                    $application->lock_version,
                    $data['rejection_reason_internal'] ?? null,
                    $user->id,
                    $connection
                );
                $succeeded++;
            } catch (OptimisticLockConflictException $e) {
                $failed[] = ['id' => $id, 'name' => $name, 'message' => $e->getMessage()];
            } catch (\RuntimeException $e) {
                $failed[] = ['id' => $id, 'name' => $name, 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                report($e);
                $failed[] = ['id' => $id, 'name' => $name, 'message' => 'Could not move this candidate.'];
            }
        }

        return response()->json(['succeeded' => $succeeded, 'unchanged' => $unchanged, 'failed' => $failed]);
    }
}
