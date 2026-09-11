<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TaskService
{
    /**
     * PRD Section 62-63 — Owner/HM create tasks (enforced via
     * TaskPolicy::create() in the controller before this runs).
     */
    public function create(array $data, User $creator, string $companyId, string $connection): Task
    {
        $task = Task::on($connection)->create([
            ...$data,
            'company_id' => $companyId,
            'created_by' => $creator->id,
            'status' => 'to_do',
            'auto_generated' => false,
        ]);

        $this->logActivity($connection, $companyId, $creator->id, 'task.created', $task->id, [
            'title' => $task->title,
            'assigned_to' => $task->assigned_user_id,
        ]);

        return $task;
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
