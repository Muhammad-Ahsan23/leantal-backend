<?php

namespace App\Policies;

use App\Models\Candidate;
use App\Models\User;
use App\Support\Roles;

/**
 * PRD Section 8 — Candidates rows:
 * Create candidate: Owner Yes, HM Yes, Recruiter Yes (all three).
 * View/Move candidate: Owner Yes, HM Yes, Recruiter "Assigned only".
 * Delete/Archive candidate: Owner Yes, HM Yes, Recruiter No.
 * Assign candidate (to a Recruiter): Owner Yes, HM Yes, Recruiter No.
 */
class CandidatePolicy
{
    public function viewAny(User $user): bool
    {
        return true; // list is filtered by Candidate::scopeVisibleTo()
    }

    public function view(User $user, Candidate $candidate): bool
    {
        if ($candidate->company_id !== $user->company_id) {
            return false;
        }

        if (in_array($user->role, Roles::MANAGEMENT, true)) {
            return true;
        }

        return $candidate->assigned_user_id === $user->id;
    }

    public function create(User $user): bool
    {
        // All three roles can add candidates (Section 41, Section 8 matrix)
        return true;
    }

    /**
     * Covers editing candidate info, moving pipeline stage, adding notes.
     * Recruiters may only do this on candidates assigned to them.
     */
    public function update(User $user, Candidate $candidate): bool
    {
        if ($candidate->company_id !== $user->company_id) {
            return false;
        }

        if (in_array($user->role, Roles::MANAGEMENT, true)) {
            return true;
        }

        return $candidate->assigned_user_id === $user->id;
    }

    public function delete(User $user, Candidate $candidate): bool
    {
        return $candidate->company_id === $user->company_id
            && in_array($user->role, Roles::MANAGEMENT, true);
    }

    public function archive(User $user, Candidate $candidate): bool
    {
        return $this->delete($user, $candidate); // same rule — Owner/HM only
    }

    public function assign(User $user, Candidate $candidate): bool
    {
        return $this->delete($user, $candidate); // same rule — Owner/HM only
    }
}
