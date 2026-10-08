<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\Job;
use App\Models\Note;
use App\Models\PipelineStage;
use App\Models\User;
use App\Support\CacheVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CandidateService
{
    public function __construct(
        protected ResumeStorageService $resumeStorage,
        protected NotificationService $notifications,
    ) {}

    /**
     * PRD Section 41 — manually add a candidate to a job. Section 133 —
     * candidates are de-duplicated by normalized email WITHIN a company:
     * if this email already exists here, reuse that Candidate row instead
     * of creating a duplicate person, and just add a new Application for
     * the new job. Section 135 — a candidate cannot have two ACTIVE
     * applications to the same job (the same rule the DB's partial unique
     * index enforces — this check gives a clean error before hitting it).
     *
     * @throws \RuntimeException if the candidate already has an active application to this job
     */
    /**
     * Adds a candidate WITHOUT putting them in any job (PRD Section 32: candidate and application are
     * separate things; Section 41 manual add needs only basic information). Same fields and defaults as a
     * new candidate in addToJob(), minus the application.
     *
     * An email that already belongs to a candidate of this company is refused (Sections 43/133: detect
     * duplicate candidates, identity = normalized email). Unlike addToJob() there is nothing to attach
     * to an existing person here, so quietly returning them would hide that the add did nothing.
     *
     * @throws \RuntimeException with a user-safe message when the email is already taken
     */
    public function createWithoutJob(array $data, User $actor, string $connection, ?UploadedFile $resume = null): Candidate
    {
        $normalizedEmail = strtolower(trim($data['email']));
        $duplicate = 'A candidate with this email already exists.';

        // Checked BEFORE storing the resume, so a refused add never leaves an orphan file behind.
        $exists = Candidate::on($connection)
            ->where('company_id', $actor->company_id)
            ->where('normalized_email', $normalizedEmail)
            ->exists();

        if ($exists) {
            throw new \RuntimeException($duplicate);
        }

        $resumeMeta = $this->resumeStorage->store($resume, RegionResolver::regionForConnection($connection));

        try {
            $candidate = DB::connection($connection)->transaction(function () use ($data, $actor, $connection, $normalizedEmail, $resumeMeta) {
                $candidate = Candidate::on($connection)->create([
                    'company_id' => $actor->company_id,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'normalized_email' => $normalizedEmail,
                    'phone' => $data['phone'] ?? null,
                    'location' => $data['location'] ?? null,
                    'current_title' => $data['current_title'] ?? null,
                    'current_company' => $data['current_company'] ?? null,
                    'linkedin_url' => $data['linkedin_url'] ?? null,
                    // Same default as addToJob(): whoever adds the candidate owns them until reassigned.
                    'assigned_user_id' => $actor->id,
                    'status' => 'active',
                    ...($resumeMeta ?? []),
                ]);

                $this->logActivity($connection, $actor->company_id, $actor->id, 'candidate.created', 'candidate', $candidate->id, [
                    'candidate_name' => $candidate->name,
                ]);

                return $candidate;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Two people adding the same email at the same moment: the database's unique index wins.
            throw new \RuntimeException($duplicate);
        }

        $this->forgetCandidatesCache($actor->company_id);

        return $candidate;
    }

    public function addToJob(array $data, Job $job, User $actor, string $connection, ?UploadedFile $resume = null): array
    {
        $normalizedEmail = strtolower(trim($data['email']));
        $isNewCandidate = false;
        $resumeMeta = $this->resumeStorage->store($resume, RegionResolver::regionForConnection($connection));

        $result = DB::connection($connection)->transaction(function () use ($data, $job, $actor, $connection, $normalizedEmail, &$isNewCandidate, $resumeMeta) {
            $candidate = Candidate::on($connection)
                ->where('company_id', $job->company_id)
                ->where('normalized_email', $normalizedEmail)
                ->first();

            if (!$candidate) {
                $candidate = Candidate::on($connection)->create([
                    'company_id' => $job->company_id,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'normalized_email' => $normalizedEmail,
                    'phone' => $data['phone'] ?? null,
                    'location' => $data['location'] ?? null,
                    'current_title' => $data['current_title'] ?? null,
                    'current_company' => $data['current_company'] ?? null,
                    'linkedin_url' => $data['linkedin_url'] ?? null,
                    // Manually-added candidates default to whoever added
                    // them — matches "assigned to me" expectations for
                    // Recruiters, who can only add/see their own anyway.
                    'assigned_user_id' => $actor->id,
                    'status' => 'active',
                    ...($resumeMeta ?? []),
                ]);
                $isNewCandidate = true;
            } elseif ($resumeMeta) {
                // Existing candidate, but a NEW resume was attached this
                // time (e.g. re-added for a different job) — refresh it
                // rather than silently keeping the old one.
                $candidate->update($resumeMeta);
            }

            $existingActiveApplication = Application::on($connection)
                ->where('candidate_id', $candidate->id)
                ->where('job_id', $job->id)
                ->where('status', 'active')
                ->exists();

            if ($existingActiveApplication) {
                throw new \RuntimeException('This candidate already has an active application for this job.');
            }

            $appliedStage = PipelineStage::on($connection)
                ->where('job_id', $job->id)
                ->where('name', 'Applied')
                ->first();

            $application = Application::on($connection)->create([
                'company_id' => $job->company_id,
                'candidate_id' => $candidate->id,
                'job_id' => $job->id,
                'stage_id' => $appliedStage?->id,
                'status' => 'active',
                'applied_at' => now(),
                'lock_version' => 1, // matches schema default (Section 98)
            ]);

            $this->logActivity($connection, $job->company_id, $actor->id, 'candidate.added_to_job', 'application', $application->id, [
                'candidate_name' => $candidate->name,
                'job_title' => $job->title,
                'new_candidate' => $isNewCandidate,
            ]);

            return [$candidate, $application];
        });

        $this->forgetCandidatesCache($job->company_id);

        return $result;
    }

    /**
     * @throws \RuntimeException if the candidate is not found (defensive — controller already checks)
     */
    public function addNote(Candidate $candidate, string $body, User $actor, string $connection): Note
    {
        $note = Note::on($connection)->create([
            'candidate_id' => $candidate->id,
            'author_id' => $actor->id,
            'body' => $body,
        ]);

        $this->logActivity($connection, $candidate->company_id, $actor->id, 'candidate.note_added', 'candidate', $candidate->id, [
            'candidate_name' => $candidate->name,
        ]);

        return $note;
    }

    /**
     * PRD Section 37 — archive is reversible (status flag), unlike
     * delete() below which is a soft-delete via deleted_at.
     */
    /**
     * PRD Section 37 — a candidate is either Active or Archived, so an archived one must be able to go
     * back to Active. Exactly the reverse of archive(): only the status changes (archiving changed
     * nothing else). Idempotent — restoring a candidate that is already active changes nothing and
     * logs nothing, so a double click or a stale screen is harmless.
     */
    public function restore(Candidate $candidate, User $actor, string $connection): Candidate
    {
        if ($candidate->status !== 'archived') {
            return $candidate;
        }

        $candidate->update(['status' => 'active', 'archived_at' => null]);

        $this->logActivity($connection, $candidate->company_id, $actor->id, 'candidate.restored', 'candidate', $candidate->id, [
            'candidate_name' => $candidate->name,
        ]);

        $this->forgetCandidatesCache($candidate->company_id);

        return $candidate->fresh();
    }

    /**
     * PRD Section 7 / 72 — edit the profile fields of Section 35. The activity entry lists WHICH fields
     * changed (not their old/new values, so personal data is not copied into the log — Section 72).
     * Section 36: an empty string means "cleared", stored as NULL.
     */
    public function updateProfile(Candidate $candidate, array $data, User $actor, string $connection): Candidate
    {
        $clean = [];
        foreach ($data as $field => $value) {
            $value = is_string($value) ? trim($value) : $value;
            $clean[$field] = ($value === '' && $field !== 'name') ? null : $value;
        }

        $candidate->fill($clean);
        $changed = array_keys($candidate->getDirty());

        if ($changed === []) {
            return $candidate; // nothing really changed — no write, no activity noise
        }

        $candidate->save();

        $this->logActivity($connection, $candidate->company_id, $actor->id, 'candidate.updated', 'candidate', $candidate->id, [
            'candidate_name' => $candidate->name,
            'fields' => $changed,
        ]);

        $this->forgetCandidatesCache($candidate->company_id);

        return $candidate->fresh();
    }

    public function archive(Candidate $candidate, User $actor, string $connection): Candidate
    {
        $candidate->update(['status' => 'archived', 'archived_at' => now()]);

        $this->logActivity($connection, $candidate->company_id, $actor->id, 'candidate.archived', 'candidate', $candidate->id, [
            'candidate_name' => $candidate->name,
        ]);

        $this->forgetCandidatesCache($candidate->company_id);

        return $candidate->fresh();
    }

    /**
     * PRD Section 37 — soft-delete only (deleted_at), never a hard DELETE
     * — historical application/activity data must be preserved regardless.
     */
    public function softDelete(Candidate $candidate, User $actor, string $connection): void
    {
        $this->logActivity($connection, $candidate->company_id, $actor->id, 'candidate.deleted', 'candidate', $candidate->id, [
            'candidate_name' => $candidate->name,
        ]);

        $candidate->delete(); // SoftDeletes trait — sets deleted_at, doesn't hard-remove the row

        $this->forgetCandidatesCache($candidate->company_id);
    }

    /**
     * PRD Section 20 — "Candidate assigned to you" notification trigger.
     */
    public function assign(Candidate $candidate, string $newAssignedUserId, User $actor, string $connection): Candidate
    {
        $candidate->update(['assigned_user_id' => $newAssignedUserId]);

        $this->logActivity($connection, $candidate->company_id, $actor->id, 'candidate.reassigned', 'candidate', $candidate->id, [
            'candidate_name' => $candidate->name,
            'new_assigned_user_id' => $newAssignedUserId,
        ]);

        $this->forgetCandidatesCache($candidate->company_id);

        $assignedUser = User::on($connection)->find($newAssignedUserId);
        if ($assignedUser) {
            $this->notifications->notify($assignedUser, 'candidate_assigned', "You were assigned candidate: {$candidate->name}", $connection, "/candidates/{$candidate->id}");
        }

        return $candidate->fresh();
    }

    protected function logActivity(string $connection, string $companyId, string $actorId, string $action, string $objectType, string $objectId, array $metadata = []): void
    {
        DB::connection($connection)->table('activity')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'metadata' => json_encode($metadata),
            'created_at' => now(),
        ]);
    }

    protected function forgetCandidatesCache(string $companyId): void
    {
        CacheVersion::bump("company:{$companyId}:candidates");
    }
}
