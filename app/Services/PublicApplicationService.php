<?php

namespace App\Services;

use App\Exceptions\DuplicateApplicationException;
use App\Models\Application;
use App\Models\ApplicationAnswer;
use App\Models\ApplicationQuestion;
use App\Models\Candidate;
use App\Models\Job;
use App\Models\PipelineStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicApplicationService
{
    /**
     * Checks every REQUIRED question has a submitted answer. Returns a
     * Laravel-style errors array (empty if valid) — the PRD's per-job
     * custom questions can't be validated with a static FormRequest
     * rules() array since which fields are required varies per job.
     */
    public function validateAnswers(Job $job, array $answers, string $connection): array
    {
        $errors = [];
        $questions = ApplicationQuestion::on($connection)->where('job_id', $job->id)->get();

        foreach ($questions as $question) {
            if ($question->required && blank($answers[$question->id] ?? null)) {
                $errors["answers.{$question->id}"] = ["This field is required."];
            }
        }

        return $errors;
    }

    /**
     * PRD Section 41/93 (candidate applies) + Section 133 (dedup) +
     * Section 135 (duplicate application) + Section 29 (knockout,
     * silent to the candidate either way).
     *
     * @throws DuplicateApplicationException
     */
    public function submit(Job $job, array $data, string $connection): array
    {
        $normalizedEmail = strtolower(trim($data['email']));

        return DB::connection($connection)->transaction(function () use ($job, $data, $connection, $normalizedEmail) {
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
                    'linkedin_url' => $data['linkedin_url'] ?? null,
                    // Unassigned — nobody "owns" a self-applied candidate
                    // until a staff member picks them up. Unlike manually
                    // -added candidates (CandidateService::addToJob()),
                    // there's no actor here to default to.
                    'assigned_user_id' => null,
                    'status' => 'active',
                ]);
            }

            $alreadyApplied = Application::on($connection)
                ->where('candidate_id', $candidate->id)
                ->where('job_id', $job->id)
                ->where('status', 'active')
                ->exists();

            if ($alreadyApplied) {
                throw new DuplicateApplicationException('You have already applied for this job.');
            }

            [$knockoutTriggered, $knockoutReason] = $this->evaluateKnockouts($job, $data['answers'] ?? [], $connection);

            $stageName = $knockoutTriggered ? 'Rejected' : 'Applied';
            $stage = PipelineStage::on($connection)->where('job_id', $job->id)->where('name', $stageName)->first();

            $application = Application::on($connection)->create([
                'company_id' => $job->company_id,
                'candidate_id' => $candidate->id,
                'job_id' => $job->id,
                'stage_id' => $stage?->id,
                'status' => $knockoutTriggered ? 'rejected' : 'active',
                'applied_at' => now(),
                'rejected_at' => $knockoutTriggered ? now() : null,
                'rejection_reason_internal' => $knockoutReason,
                'lock_version' => 1,
            ]);

            $this->storeAnswers($application, $data['answers'] ?? [], $connection);

            DB::connection($connection)->table('activity')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $job->company_id,
                'actor_id' => null, // candidate-initiated, no staff actor — see migration comment
                'action' => 'candidate.applied',
                'object_type' => 'application',
                'object_id' => $application->id,
                'metadata' => json_encode(['job_title' => $job->title, 'candidate_name' => $candidate->name]),
                'created_at' => now(),
            ]);

            return ['candidate' => $candidate, 'application' => $application];
        });
    }

    /**
     * PRD Section 29 — evaluates every knockout question against its
     * configured expected (disqualifying) answer. Case-insensitive
     * comparison since candidates might type "yes"/"Yes"/"YES".
     *
     * @return array{0: bool, 1: ?string} [triggered, internal reason]
     */
    protected function evaluateKnockouts(Job $job, array $answers, string $connection): array
    {
        $knockoutQuestions = ApplicationQuestion::on($connection)
            ->where('job_id', $job->id)
            ->where('knockout', true)
            ->where('knockout_action', 'reject')
            ->get();

        foreach ($knockoutQuestions as $question) {
            $answer = $answers[$question->id] ?? null;

            if ($answer !== null && strcasecmp(trim($answer), trim($question->knockout_expected_answer ?? '')) === 0) {
                return [true, "Auto-rejected — knockout question \"{$question->question}\" answered \"{$answer}\"."];
            }
        }

        return [false, null];
    }

    protected function storeAnswers(Application $application, array $answers, string $connection): void
    {
        foreach ($answers as $questionId => $answerText) {
            if (blank($answerText)) {
                continue;
            }

            ApplicationAnswer::on($connection)->create([
                'application_id' => $application->id,
                'question_id' => $questionId,
                'answer_text' => $answerText,
            ]);
        }
    }
}
