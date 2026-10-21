<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\CreateTaskRequest;
use App\Http\Requests\Tasks\UpdateTaskRequest;
use App\Http\Requests\Tasks\UpdateTaskStatusRequest;
use App\Models\Candidate;
use App\Models\Job;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskAttachmentService;
use App\Services\TaskService;
use App\Support\Roles;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(protected TaskService $tasks, protected TaskAttachmentService $attachments) {}

    /** Most rows a call WITHOUT ?page= can return - a safety net so one request can never pull a whole table. */
    protected const UNPAGED_LIMIT = 500;

    /**
     * Tasks list. Deliberately NOT cached - a task list is collaborative/live (Owner assigns a task,
     * the Recruiter's list should show it immediately). RBAC via Task::scopeVisibleTo(): Recruiters see
     * only their own.
     *
     * Pagination is opt-in, like Jobs and Candidates: ?page= / ?per_page= (max 100) returns one page plus
     *   meta: { current_page, last_page, per_page, total, counts: { all, open, completed } }
     * where `counts` ignores the status filter (so the tabs can show every count) but respects the search.
     * Filters: status = open (anything not done) | completed (done) | all, search (title, person, candidate,
     * job), assigned_user_id (Owner/HM only). Without ?page= at most UNPAGED_LIMIT rows come back.
     */
    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', 'in:open,completed,all'],
            'search' => ['nullable', 'string', 'max:100'],
            'assigned_user_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $connection = $user->getConnectionName();
        $isManager = in_array($user->role, Roles::MANAGEMENT, true);

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status', 'all');
        $assignedTo = $isManager ? $request->query('assigned_user_id') : null;

        $base = Task::on($connection)->visibleTo($user);

        if ($assignedTo) {
            $base->where('assigned_user_id', $assignedTo);
        }

        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $base->where(function ($q) use ($like) {
                $q->where('title', 'ilike', $like)
                    ->orWhereHas('assignedUser', fn ($u) => $u->where('name', 'ilike', $like))
                    ->orWhereHas('candidate', fn ($c) => $c->where('name', 'ilike', $like))
                    ->orWhereHas('job', fn ($j) => $j->where('title', 'ilike', $like));
            });
        }

        $query = (clone $base)
            ->with(['assignedUser:id,name', 'candidate:id,name', 'job:id,title'])
            ->withCount('attachments')
            ->orderByDesc('created_at')
            ->orderBy('id');

        if ($status === 'open') {
            $query->where('status', '!=', 'done');
        } elseif ($status === 'completed') {
            $query->where('status', 'done');
        }

        if (!$request->has('page') && !$request->has('per_page')) {
            return response()->json(['tasks' => $query->limit(self::UNPAGED_LIMIT)->get()]);
        }

        $paginator = $query->paginate(
            min(100, max(1, (int) $request->query('per_page', 20))),
            ['*'],
            'page',
            max(1, (int) $request->query('page', 1))
        );

        $row = (clone $base)->toBase()
            ->selectRaw("count(*) as all_count, coalesce(sum(case when status = 'done' then 1 else 0 end), 0) as done_count")
            ->first();
        $all = (int) ($row->all_count ?? 0);
        $done = (int) ($row->done_count ?? 0);

        return response()->json([
            'tasks' => $paginator->getCollection()->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'counts' => ['all' => $all, 'open' => $all - $done, 'completed' => $done],
            ],
        ]);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $task = Task::on($connection)->with(['assignedUser:id,name', 'candidate:id,name', 'job:id,title', 'attachments.uploader:id,name'])->withCount('attachments')->find($id);

        if (!$task) {
            return response()->json(['message' => 'Task not found.'], 404);
        }

        if (!$user->can('view', $task)) {
            return response()->json(['message' => 'You do not have permission to view this task.'], 403);
        }

        return response()->json(['task' => $task]);
    }

    /**
     * PRD Section 62-63 — Owner/HM only (TaskPolicy). Validates that
     * assigned_user_id / candidate_id / job_id (whichever apply) all
     * belong to the SAME company — never trust client-submitted IDs
     * blindly across tenant boundaries.
     */
    public function store(CreateTaskRequest $request)
    {
        $user = $request->user();

        if (!$user->can('create', Task::class)) {
            return response()->json(['message' => 'You do not have permission to create tasks.'], 403);
        }

        $connection = $user->getConnectionName();
        $data = $request->validated();

        $assignedUser = User::on($connection)->where('company_id', $user->company_id)->find($data['assigned_user_id']);
        if (!$assignedUser) {
            return response()->json(['message' => 'That user was not found in your company.'], 422);
        }

        if (!empty($data['candidate_id'])) {
            $candidate = Candidate::on($connection)->where('company_id', $user->company_id)->find($data['candidate_id']);
            if (!$candidate) {
                return response()->json(['message' => 'That candidate was not found in your company.'], 422);
            }
        }

        if (!empty($data['job_id'])) {
            $job = Job::on($connection)->where('company_id', $user->company_id)->find($data['job_id']);
            if (!$job) {
                return response()->json(['message' => 'That job was not found in your company.'], 422);
            }
        }

        $task = $this->tasks->create($data, $user, $user->company_id, $connection);

        return response()->json(['task' => $task->load(['assignedUser:id,name', 'candidate:id,name', 'job:id,title'])], 201);
    }

    /**
     * Edit title / assignee / due date / notes. Owner/HM only (same rule as
     * creating or assigning a task); recruiters can still change status.
     */
    public function update(UpdateTaskRequest $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $task = Task::on($connection)->find($id);

        if (!$task) {
            return response()->json(['message' => 'Task not found.'], 404);
        }

        if (!$user->can('assign', $task) || $task->company_id !== $user->company_id) {
            return response()->json(['message' => 'You do not have permission to edit this task.'], 403);
        }

        $data = $request->validated();

        if (isset($data['assigned_user_id'])) {
            $assignee = User::on($connection)->where('company_id', $user->company_id)->find($data['assigned_user_id']);
            if (!$assignee) {
                return response()->json(['message' => 'That user was not found in your company.'], 422);
            }
        }

        $task = $this->tasks->update($task, $data, $user, $connection);

        return response()->json(['task' => $task->load(['assignedUser:id,name', 'candidate:id,name', 'job:id,title'])]);
    }

    /**
     * PRD Section 64 — Owner/HM can update any task; Recruiters can only
     * update tasks assigned to them (TaskPolicy::update()).
     */
    public function updateStatus(UpdateTaskStatusRequest $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $task = Task::on($connection)->find($id);

        if (!$task) {
            return response()->json(['message' => 'Task not found.'], 404);
        }

        if (!$user->can('update', $task)) {
            return response()->json(['message' => 'You do not have permission to update this task.'], 403);
        }

        $task = $this->tasks->updateStatus($task, $request->validated()['status'], $user, $connection);

        return response()->json(['task' => $task]);
    }

    /**
     * Unlike Candidate::softDelete(), this IS a hard delete — the tasks
     * table has no deleted_at column (confirmed against the actual
     * migration). Tasks are operational/transient, not the kind of
     * historical record the PRD asks us to preserve indefinitely.
     */
    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $task = Task::on($connection)->find($id);

        if (!$task) {
            return response()->json(['message' => 'Task not found.'], 404);
        }

        if (!$user->can('delete', $task)) {
            return response()->json(['message' => 'You do not have permission to delete this task.'], 403);
        }

        $this->attachments->removeAllFor($task);
        $task->delete();

        return response()->json(['message' => 'Task deleted.']);
    }
}
