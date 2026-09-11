<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\CreateTaskRequest;
use App\Http\Requests\Tasks\UpdateTaskStatusRequest;
use App\Models\Candidate;
use App\Models\Job;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(protected TaskService $tasks) {}

    /**
     * PRD Section 64 — Kanban board data. Deliberately NOT cached, same
     * reasoning as ApplicationController::index() — a task board is
     * collaborative/live, staleness would be confusing (Owner assigns a
     * task, Recruiter's board should show it immediately).
     * RBAC via Task::scopeVisibleTo() — Recruiters see only their own.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        $tasks = Task::on($connection)
            ->visibleTo($user)
            ->with(['assignedUser:id,name', 'candidate:id,name', 'job:id,title'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['tasks' => $tasks]);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $task = Task::on($connection)->with(['assignedUser:id,name', 'candidate:id,name', 'job:id,title'])->find($id);

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

        $task->delete();

        return response()->json(['message' => 'Task deleted.']);
    }
}
