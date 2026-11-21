<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use App\Support\Assignees;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TaskService
{
    public function __construct(protected NotificationService $notifications) {}

    /**
     * PRD Section 62-63 — Owner/HM create tasks (enforced via
     * TaskPolicy::create() in the controller before this runs).
     * PRD Section 20 — "Task assigned to you" notification trigger.
     */
    public function create(array $data, User $creator, string $companyId, string $connection): Task
    {
        // One person (assigned_user_id) or several (assigned_user_ids); the first one is the task's primary assignee.
        $assigneeIds = Assignees::idsFrom($data);
        unset($data['assigned_user_ids']);

        $task = DB::connection($connection)->transaction(function () use ($data, $creator, $companyId, $connection, $assigneeIds) {
            $task = Task::on($connection)->create([
                ...$data,
                'assigned_user_id' => $assigneeIds[0] ?? null,
                'company_id' => $companyId,
                'created_by' => $creator->id,
                'status' => 'to_do',
                'auto_generated' => false,
            ]);

            if (count($assigneeIds) > 1) {
                $task->setAssignees($assigneeIds); // the first one is already stored; this adds the others
            }

            return $task;
        });

        $this->logActivity($connection, $companyId, $creator->id, 'task.created', $task->id, [
            'title' => $task->title,
            'assigned_to' => $task->assigned_user_id,
            'assigned_to_ids' => $assigneeIds,
        ]);

        foreach (User::on($connection)->whereIn('id', $assigneeIds)->get() as $assignedUser) {
            $this->notifications->notify($assignedUser, 'task_assigned', "You were assigned a task: {$task->title}", $connection, "/tasks/{$task->id}");
        }

        return $task;
    }

    /** Edit details. Notifies the new assignee when the task is reassigned. */
    public function update(Task $task, array $data, User $actor, string $connection): Task
    {
        // The assignee list is only touched when the request carries one.
        $assigneeIds = (array_key_exists('assigned_user_ids', $data) || array_key_exists('assigned_user_id', $data))
            ? Assignees::idsFrom($data)
            : null;
        unset($data['assigned_user_ids'], $data['assigned_user_id']);

        $added = [];
        DB::connection($connection)->transaction(function () use ($task, $data, $assigneeIds, &$added) {
            $task->update($data);
            if ($assigneeIds !== null) {
                $added = $task->setAssignees($assigneeIds);
            }
        });

        $fields = array_keys($data);
        if ($assigneeIds !== null) {
            $fields[] = 'assigned_user_id';
        }

        $this->logActivity($connection, $task->company_id, $actor->id, 'task.updated', $task->id, [
            'title' => $task->title,
            'fields' => $fields,
        ]);

        // Only people who were not on the task before are told.
        if ($added) {
            foreach (User::on($connection)->whereIn('id', $added)->get() as $assignee) {
                $this->notifications->notify($assignee, 'task_assigned', "You were assigned a task: {$task->title}", $connection, "/tasks/{$task->id}");
            }
        }

        return $task->fresh();
    }

    /**
     * PRD Section 64 — Kanban-style status tracking. No transition
     * restrictions (like Candidate stages, unlike Job status) — any
     * column to any column, since the PRD doesn't define a required order
     * for To Do / In Progress / Done / Cancelled.
     */
    public function updateStatus(Task $task, string $newStatus, User $actor, string $connection): Task
    {
        $task->update(['status' => $newStatus]);

        $this->logActivity($connection, $task->company_id, $actor->id, 'task.status_changed', $task->id, [
            'title' => $task->title,
            'new_status' => $newStatus,
        ]);

        return $task->fresh();
    }

    protected function logActivity(string $connection, string $companyId, string $actorId, string $action, string $taskId, array $metadata = []): void
    {
        DB::connection($connection)->table('activity')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'action' => $action,
            'object_type' => 'task',
            'object_id' => $taskId,
            'metadata' => json_encode($metadata),
            'created_at' => now(),
        ]);
    }
}
