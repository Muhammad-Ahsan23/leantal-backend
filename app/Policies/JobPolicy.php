<?php

namespace App\Policies;

use App\Models\Job;
use App\Models\User;
use App\Support\Roles;

/**
 * PRD Section 8 (Permission Matrix) — Jobs rows:
 * Create/Publish/Assign/Reassign job: Owner Yes, HM Yes, Recruiter No.
 * View job: Owner Yes, HM Yes, Recruiter "Only if assigned".
 * There is no "delete job" in the PRD — jobs only move through
 * Draft/Published/Paused/Closed/Archived via status updates (Section 21-27),
 * so status changes are covered by update(), not a separate ability.
 */
class JobPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // list is filtered by Job::scopeVisibleTo(), not blocked here
    }

    public function view(User $user, Job $job): bool
    {
        if ($job->company_id !== $user->company_id) {
            return false; // tenant isolation — never cross company boundaries
        }

        if (in_array($user->role, Roles::MANAGEMENT, true)) {
            return true;
        }

        return $job->assigned_user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, Roles::MANAGEMENT, true);
    }

    public function update(User $user, Job $job): bool
    {
        return $job->company_id === $user->company_id
            && in_array($user->role, Roles::MANAGEMENT, true);
    }

    public function assign(User $user, Job $job): bool
    {
        return $this->update($user, $job); // same rule — Owner/HM only
    }
}
