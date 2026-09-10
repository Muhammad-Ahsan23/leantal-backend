<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\Job;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CandidateService
{
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
    public function addToJob(array $data, Job $job, User $actor, string $connection): array
    {
        $normalizedEmail = strtolower(trim($data['email']));

        return DB::connection($connection)->transaction(function () use ($data, $job, $actor, $connection, $normalizedEmail) {
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
                ]);
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

            return [$candidate, $application];
        });
    }
}
