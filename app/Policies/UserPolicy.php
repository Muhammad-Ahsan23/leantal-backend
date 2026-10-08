<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Roles;

/**
 * PRD Section 5-7 — team member management.
 *
 * Invitations: "Owner invites users" (Section 88); Hiring Manager's
 * "cannot" list explicitly blocks inviting another HM, and separately
 * blocks "arbitrary users" — the PRD does not clearly state whether HM
 * can invite Recruiters specifically (flagged ambiguity, see project
 * notes). Defaulting to Owner-only for ALL invites until confirmed —
 * the safer, more restrictive default; easy to loosen later, harder to
 * tighten after the fact if we guessed wrong the other way.
 *
 * Removal: Owner can remove HM or Recruiter. Hiring Manager can remove
 * Recruiters only (Section 6: "Remove Recruiters" is in HM's "can" list;
 * HM cannot remove other HMs or the Owner). Recruiters cannot remove anyone.
 */
class UserPolicy
{
    public function invite(User $actor): bool
    {
        return $actor->role === Roles::OWNER;
    }

    public function remove(User $actor, User $target): bool
    {
        if ($actor->company_id !== $target->company_id) {
            return false;
        }

        if ($target->role === Roles::OWNER) {
            return false; // the sole Owner can never be removed this way — only via ownership transfer
        }

        if ($actor->id === $target->id) {
            return false; // no self-removal via this endpoint
        }

        if ($actor->role === Roles::OWNER) {
            return true; // Owner can remove HM or Recruiter
        }

        if ($actor->role === Roles::HIRING_MANAGER) {
            return $target->role === Roles::RECRUITER; // HM can only remove Recruiters
        }

        return false; // Recruiters can never remove anyone
    }

    /**
     * PRD Section 68 — "Users: Owner: Invite, remove, change roles." Only the Owner, only for another
     * member of the same company, and never for the Owner seat itself (that moves only through
     * ownership transfer — "exactly one Owner exists at all times", Section 69). Hiring Managers and
     * Recruiters cannot change anyone's role (Section 6 "cannot": change the Owner / invite arbitrary users).
     */
    public function changeRole(User $actor, User $target): bool
    {
        return $actor->role === Roles::OWNER
            && $actor->company_id === $target->company_id
            && $actor->id !== $target->id
            && $target->role !== Roles::OWNER;
    }

    /**
     * Editing account details. Every user may edit their own profile;
     * beyond that, the PRD doesn't detail cross-user editing, so this
     * stays conservative — Owner can manage others' role, HM/Recruiter
     * can only touch their own account.
     */
    public function update(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return true;
        }

        return $actor->company_id === $target->company_id
            && $actor->role === Roles::OWNER;
    }
}
