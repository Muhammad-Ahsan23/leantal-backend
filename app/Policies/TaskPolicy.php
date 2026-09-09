<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Support\Roles;

/**
 * PRD Section 8 + Section 62 — Tasks:
 * Create task / Assign task: Owner Yes, HM Yes, Recruiter No
 * ("Recruiters cannot create or assign tasks").
 * Recruiters CAN complete tasks assigned to them (Section 62-63).
 *
 * ASSUMPTION: the PRD does not explicitly state who may DELETE a task.
 * Defaulting to Owner/HM only, consistent with every other destructive
 * action in the matrix — flag for client confirmation if task deletion
 * becomes a real feature.
 */
class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // list is filtered by Task::scopeVisibleTo()
    }

    public function view(User $user, Task $task): bool
    {
        if ($task->company_id !== $user->company_id) {
            return false;
        }

        if (in_array($user->role, Roles::MANAGEMENT, true)) {
            return true;
        }

        return $task->assigned_user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, Roles::MANAGEMENT, true);
    }

    /**
     * Covers changing a task's status (To Do / In Progress / Done /
     * Cancelled) — Recruiters may update ONLY tasks assigned to them
     * (i.e. "complete" their own tasks); Owner/HM can update any task.
     */
    public function update(User $user, Task $task): bool
    {
        if ($task->company_id !== $user->company_id) {
            return false;
        }

        if (in_array($user->role, Roles::MANAGEMENT, true)) {
            return true;
        }

        return $task->assigned_user_id === $user->id;
    }

    public function delete(User $user, Task $task): bool
    {
        return $task->company_id === $user->company_id
            && in_array($user->role, Roles::MANAGEMENT, true);
    }

    public function assign(User $user, Task $task): bool
    {
        return $this->create($user); // same rule — Owner/HM only
    }
}
