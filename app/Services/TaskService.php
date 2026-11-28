<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Task;
use App\Models\User;
use App\Support\Assignees;
use Illuminate\Support\Collection;
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

    /**
     * One task for each of several candidates, all with the same title, assignees, due date and notes.
     *
     * Built for volume: a fixed handful of queries however many candidates there are (tasks, assignee rows and
     * activity rows are each ONE insert, notifications one per person), all inside a single transaction so either
     * every task is created or none is. Each assignee gets ONE notification for the whole batch, not one per task.
     * The caller has already checked that the assignees and candidates belong to the company.
     *
     * @return Collection<int, Task> in the order the candidates were given
     */
    public function createForCandidates(array $data, array $candidateIds, User $creator, string $companyId, string $connection): Collection
    {
        $assigneeIds = Assignees::idsFrom($data);
        $candidateIds = array_values(array_unique($candidateIds));
        $now = now();

        $taskRows = [];
        $pivotRows = [];
        $activityRows = [];
        $taskIds = [];

        foreach ($candidateIds as $candidateId) {
            $id = (string) Str::uuid();
            $taskIds[] = $id;

            $taskRows[] = [
                'id' => $id,
                'company_id' => $companyId,
                'type' => 'assign_candidate',
                'title' => $data['title'],
                'assigned_user_id' => $assigneeIds[0], // the primary assignee, as in create()
                'created_by' => $creator->id,
                'candidate_id' => $candidateId,
                'job_id' => $data['job_id'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'status' => 'to_do',
                'notes' => $data['notes'] ?? null,
                'auto_generated' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Bulk inserts skip the model hook that adds the primary assignee's row, so every assignee row is written here.
            foreach ($assigneeIds as $userId) {
                $pivotRows[] = ['task_id' => $id, 'user_id' => $userId, 'created_at' => $now];
            }

            $activityRows[] = [
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'actor_id' => $creator->id,
                'action' => 'task.created',
                'object_type' => 'task',
                'object_id' => $id,
                'metadata' => json_encode(['title' => $data['title'], 'assigned_to' => $assigneeIds[0], 'assigned_to_ids' => $assigneeIds, 'bulk' => true]),
                'created_at' => $now,
            ];
        }

        DB::connection($connection)->transaction(function () use ($connection, $taskRows, $pivotRows, $activityRows) {
            DB::connection($connection)->table('tasks')->insert($taskRows);
            DB::connection($connection)->table('task_assignees')->insert($pivotRows);
            DB::connection($connection)->table('activity')->insert($activityRows);
        });

        $this->notifyBatch($assigneeIds, $data['title'], $taskIds, $companyId, $connection, $now);

        $order = array_flip($taskIds);

        return Task::on($connection)
            ->whereIn('id', $taskIds)
            ->with(['assignedUser:id,name', 'assignees:id,name', 'candidate:id,name', 'job:id,title'])
            ->get()
            ->sortBy(fn (Task $task) => $order[$task->id])
            ->values();
    }

    /** One "task assigned" notification per person for a whole batch, skipping people who switched that type off. */
    protected function notifyBatch(array $userIds, string $title, array $taskIds, string $companyId, string $connection, $now): void
    {
        $muted = NotificationPreference::on($connection)
            ->whereIn('user_id', $userIds)
            ->where('notification_type', 'task_assigned')
            ->where('channel', 'in_app')
            ->where('enabled', false)
            ->pluck('user_id')
            ->all();

        $count = count($taskIds);
        $message = $count === 1 ? "You were assigned a task: {$title}" : "You were assigned {$count} tasks: {$title}";
        $link = $count === 1 ? "/tasks/{$taskIds[0]}" : '/tasks';

        $rows = [];
        foreach (array_diff($userIds, $muted) as $userId) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'user_id' => $userId,
                'type' => 'task_assigned',
                'message' => $message,
                'link' => $link,
                'created_at' => $now,
            ];
        }

        if ($rows) {
            Notification::on($connection)->insert($rows);
        }
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
