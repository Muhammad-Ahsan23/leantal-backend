<?php

namespace App\Services;

use App\Exceptions\DuplicateApplicationException;
use App\Models\Application;
use App\Models\ApplicationAnswer;
use App\Models\ApplicationQuestion;
use App\Models\Candidate;
use App\Models\Job;
use App\Models\PipelineStage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PublicApplicationService
{
    public function __construct(protected ResumeStorageService $resumeStorage) {}

    /**
     * Validates the submitted answers against the job's own questions (which ones are required, and what
     * the allowed values are, vary per job — a static FormRequest cannot express that). Returns a
     * Laravel-style errors array (empty if valid).
     *
     * @param array<string, string|array> $answers  question id => text, or list of options (multiple_choice)
     * @param array<string, \Illuminate\Http\UploadedFile> $files  question id => file (file_upload questions)
     */
    public function validateAnswers(Job $job, array $answers, string $connection, array $files = []): array
    {
        $questions = ApplicationQuestion::on($connection)->where('job_id', $job->id)->get();

        return $this->checkAnswers($questions, $answers, $files);
    }

    /** The rules themselves, kept free of database access so they can be unit-tested with plain objects. */
    public function checkAnswers(iterable $questions, array $answers, array $files = []): array
    {
        $errors = [];

        foreach ($questions as $question) {
            $key = "answers.{$question->id}";

            if ($question->type === 'file_upload') {
                if ($question->required && empty($files[$question->id])) {
                    $errors[$key] = ['Please upload a file.'];
                }
                continue;
            }

            $value = $answers[$question->id] ?? null;
            $values = $this->asList($value);

            if (empty($values)) {
                if ($question->required) {
                    $errors[$key] = ['This field is required.'];
                }
                continue;
            }

            $options = array_map('strval', $question->options ?? []);

            switch ($question->type) {
                case 'multiple_choice':
                    foreach ($values as $v) {
                        if (!in_array($v, $options, true)) {
                            $errors[$key] = ['Please choose from the listed options.'];
                            break;
                        }
                    }
                    break;
                case 'single_choice':
                    if (count($values) !== 1 || !in_array($values[0], $options, true)) {
                        $errors[$key] = ['Please choose one of the listed options.'];
                    }
                    break;
                case 'yes_no':
                    if (count($values) !== 1 || !in_array(strtolower($values[0]), ['yes', 'no'], true)) {
                        $errors[$key] = ['Please answer Yes or No.'];
                    }
                    break;
                case 'number':
                    if (count($values) !== 1 || !is_numeric($values[0])) {
                        $errors[$key] = ['Please enter a number.'];
                    }
                    break;
                case 'date':
                    $d = count($values) === 1 ? \DateTime::createFromFormat('Y-m-d', $values[0]) : false;
                    if (!$d || $d->format('Y-m-d') !== $values[0]) {
                        $errors[$key] = ['Please enter a valid date.'];
                    }
                    break;
                default: // short_text, long_text
                    if (count($values) !== 1) {
                        $errors[$key] = ['Invalid answer.'];
                    }
            }
        }

        return $errors;
    }

    /** Resume upload is allowed unless this job switched it off. */
    public function resumeAllowed(Job $job): bool
    {
        return $job->resolvedApplicationFields()['resume'] !== 'disabled';
    }

    /**
     * Drops the built-in fields this job switched off (a candidate cannot send what the form does not
     * show) and cleans the work-history / education lists down to the known keys.
     */
    public function applyFieldSettings(Job $job, array $data): array
    {
        $fields = $job->resolvedApplicationFields();

        foreach (['phone', 'address', 'desired_salary', 'preferred_location', 'experience_summary', 'cover_letter'] as $key) {
            if ($fields[$key] === 'disabled') {
                unset($data[$key]);
            }
        }
        if ($fields['linkedin'] === 'disabled') {
            unset($data['linkedin_url']);
        }

        $data['work_history'] = $fields['work_history'] === 'disabled'
            ? []
            : $this->cleanEntries($data['work_history'] ?? [], ['company', 'title', 'start', 'end', 'description'], ['company', 'title']);
        $data['education'] = $fields['education'] === 'disabled'
            ? []
            : $this->cleanEntries($data['education'] ?? [], ['school', 'degree', 'field', 'year'], ['school', 'degree']);

        return $data;
    }

    /**
     * Required fields of this job (PRD Sec 94: the application form is customizable per job).
     * Returns a Laravel-style errors array keyed by field name (empty if valid).
     */
    public function validateProfileFields(Job $job, array $data, ?UploadedFile $resume = null): array
    {
        $fields = $job->resolvedApplicationFields();
        $errors = [];

        $text = [
            'phone' => 'Phone number',
            'address' => 'Address',
            'desired_salary' => 'Desired salary',
            'preferred_location' => 'Preferred location',
            'experience_summary' => 'Experience summary',
            'cover_letter' => 'Cover letter',
        ];
        foreach ($text as $key => $label) {
            if ($fields[$key] === 'required' && trim((string) ($data[$key] ?? '')) === '') {
                $errors[$key] = ["{$label} is required."];
            }
        }

        if ($fields['linkedin'] === 'required' && trim((string) ($data['linkedin_url'] ?? '')) === '') {
            $errors['linkedin_url'] = ['LinkedIn profile is required.'];
        }

        if ($fields['resume'] === 'required' && !$resume) {
            $errors['resume'] = ['Please upload your resume.'];
        }
        if ($fields['work_history'] === 'required' && empty($data['work_history'])) {
            $errors['work_history'] = ['Please add at least one work experience.'];
        }
        if ($fields['education'] === 'required' && empty($data['education'])) {
            $errors['education'] = ['Please add at least one education entry.'];
        }

        return $errors;
    }

    /** Keeps only the allowed keys of each entry, and drops entries where none of the main keys is filled in. */
    protected function cleanEntries(mixed $entries, array $allowedKeys, array $mainKeys): array
    {
        if (!is_array($entries)) {
            return [];
        }

        $out = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $clean = [];
            foreach ($allowedKeys as $key) {
                $clean[$key] = is_scalar($entry[$key] ?? null) ? trim((string) $entry[$key]) : '';
            }
            $hasMain = false;
            foreach ($mainKeys as $key) {
                if ($clean[$key] !== '') {
                    $hasMain = true;
                }
            }
            if ($hasMain) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /** The per-application extras, or null when the candidate filled none in. */
    protected function buildApplicationData(array $data): ?array
    {
        $out = [];
        foreach (['address', 'desired_salary', 'preferred_location', 'experience_summary', 'cover_letter'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value !== '') {
                $out[$key] = $value;
            }
        }
        foreach (['work_history', 'education'] as $key) {
            if (!empty($data[$key])) {
                $out[$key] = $data[$key];
            }
        }

        return $out ?: null;
    }

    /** A string or a list of strings -> a clean list with blanks removed. */
    protected function asList(mixed $value): array
    {
        $list = is_array($value) ? $value : [$value];

        return array_values(array_filter(
            array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $list),
            fn ($v) => $v !== ''
        ));
    }

    /**
     * PRD Section 41/93 (candidate applies) + Section 133 (dedup) +
     * Section 135 (duplicate application) + Section 29 (knockout,
     * silent to the candidate either way).
     *
     * @throws DuplicateApplicationException
     */
    public function submit(Job $job, array $data, string $connection, ?UploadedFile $resume = null, array $answerFiles = []): array
    {
        $normalizedEmail = strtolower(trim($data['email']));
        $region = RegionResolver::regionForConnection($connection);
        $resumeMeta = $this->resumeStorage->store($resume, $region);
        $storedFiles = $this->storeAnswerFiles($job, $answerFiles, $connection, $region);

        try {
            return $this->createApplication($job, $data, $connection, $normalizedEmail, $resumeMeta, $storedFiles);
        } catch (\Throwable $e) {
            // The application was not created (e.g. a duplicate) — don't leave its files orphaned in storage.
            $this->deleteStoredFiles($storedFiles);
            throw $e;
        }
    }

    protected function createApplication(Job $job, array $data, string $connection, string $normalizedEmail, ?array $resumeMeta, array $storedFiles): array
    {
        return DB::connection($connection)->transaction(function () use ($job, $data, $connection, $normalizedEmail, $resumeMeta, $storedFiles) {
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
                    'location' => ($data['preferred_location'] ?? null) ?: null,
                    'linkedin_url' => $data['linkedin_url'] ?? null,
                    // Unassigned — nobody "owns" a self-applied candidate
                    // until a staff member picks them up. Unlike manually
                    // -added candidates (CandidateService::addToJob()),
                    // there's no actor here to default to.
                    'assigned_user_id' => null,
                    'status' => 'active',
                    ...($resumeMeta ?? []),
                ]);
            } elseif ($resumeMeta) {
                $candidate->update($resumeMeta);
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
                'application_data' => $this->buildApplicationData($data),
            ]);

            $this->storeAnswers($application, $data['answers'] ?? [], $storedFiles, $connection);

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
            if ($question->type === 'file_upload') {
                continue; // a file cannot be compared with an expected answer
            }

            // A multiple-choice answer is a list: the knockout fires if ANY chosen option is the disqualifying one.
            foreach ($this->asList($answers[$question->id] ?? null) as $answer) {
                if (strcasecmp($answer, trim($question->knockout_expected_answer ?? '')) === 0) {
                    return [true, "Auto-rejected — knockout question \"{$question->question}\" answered \"{$answer}\"."];
                }
            }
        }

        return [false, null];
    }

    /**
     * Only answers to THIS job's questions are stored (a made-up question id used to hit the foreign key and
     * fail the whole application with a 500). Multiple-choice answers are stored as a JSON list; a
     * file-upload answer keeps the original file name in answer_text and the storage location in
     * answer_file_disk / answer_file_path.
     */
    protected function storeAnswers(Application $application, array $answers, array $storedFiles, string $connection): void
    {
        $questions = ApplicationQuestion::on($connection)->where('job_id', $application->job_id)->get();

        foreach ($questions as $question) {
            if ($question->type === 'file_upload') {
                if ($file = $storedFiles[$question->id] ?? null) {
                    ApplicationAnswer::on($connection)->create([
                        'application_id' => $application->id,
                        'question_id' => $question->id,
                        'answer_text' => $file['name'],
                        'answer_file_disk' => $file['disk'],
                        'answer_file_path' => $file['path'],
                    ]);
                }
                continue;
            }

            $values = $this->asList($answers[$question->id] ?? null);
            if (empty($values)) {
                continue;
            }

            ApplicationAnswer::on($connection)->create([
                'application_id' => $application->id,
                'question_id' => $question->id,
                'answer_text' => $question->type === 'multiple_choice'
                    ? json_encode($values, JSON_UNESCAPED_UNICODE)
                    : $values[0],
            ]);
        }
    }

    /**
     * Stores the uploaded files of the job's file_upload questions in the company's region bucket
     * (PRD Sec 73 — same rule as resumes). Files for any other key are ignored.
     *
     * @return array<string, array{disk: string, path: string, name: string}> question id => stored file
     */
    protected function storeAnswerFiles(Job $job, array $files, string $connection, string $region): array
    {
        if (empty($files)) {
            return [];
        }

        $fileQuestionIds = ApplicationQuestion::on($connection)
            ->where('job_id', $job->id)->where('type', 'file_upload')
            ->pluck('id')->all();

        $disk = RegionResolver::storageDiskFor($region);
        $stored = [];

        try {
            foreach ($files as $questionId => $file) {
                if (!in_array($questionId, $fileQuestionIds, true) || !$file instanceof UploadedFile) {
                    continue;
                }
                $path = $file->store("application-answers/{$job->company_id}", $disk);
                if ($path === false) {
                    throw new \RuntimeException('File upload failed. Please check storage configuration.');
                }
                $stored[$questionId] = ['disk' => $disk, 'path' => $path, 'name' => $file->getClientOriginalName()];
            }
        } catch (\Throwable $e) {
            $this->deleteStoredFiles($stored);
            throw $e;
        }

        return $stored;
    }

    protected function deleteStoredFiles(array $stored): void
    {
        foreach ($stored as $file) {
            try {
                Storage::disk($file['disk'])->delete($file['path']);
            } catch (\Throwable $e) {
                report($e); // cleanup is best-effort and must never mask the real error
            }
        }
    }
}
