<?php

namespace App\Policies;

use App\Models\Application;
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

        return $this->recruiterHasAccess($user, $candidate);
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

        return $this->recruiterHasAccess($user, $candidate);
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

    /**
     * PRD Sections 7, 96 — a Recruiter works on candidates assigned to them AND on candidates of jobs
     * assigned to them. (Same rule as Candidate::scopeVisibleTo(), for a single record.)
     */
    protected function recruiterHasAccess(User $user, Candidate $candidate): bool
    {
        if ($candidate->assigned_user_id === $user->id) {
            return true;
        }

        return Application::on($candidate->getConnectionName())
            ->where('candidate_id', $candidate->id)
            ->whereHas('job', fn ($j) => $j->where('assigned_user_id', $user->id))
            ->exists();
    }
}
