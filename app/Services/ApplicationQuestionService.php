<?php

namespace App\Services;

use App\Models\ApplicationQuestion;
use App\Models\Job;
use App\Support\CacheVersion;
use Illuminate\Support\Facades\DB;

class ApplicationQuestionService
{
    public function create(Job $job, array $data, string $connection): ApplicationQuestion
    {
        $nextOrder = ApplicationQuestion::on($connection)->where('job_id', $job->id)->max('order') + 1;

        $question = ApplicationQuestion::on($connection)->create([
            ...$data,
            'job_id' => $job->id,
            'order' => $nextOrder,
        ]);

        $this->forgetJobCache($job);

        return $question;
    }

    public function update(ApplicationQuestion $question, array $data, string $connection): ApplicationQuestion
    {
        $question->update($data);
        $this->forgetJobCacheById($question->job_id, $connection);

        return $question->fresh();
    }

    public function reorder(Job $job, array $orderedQuestionIds, string $connection): void
    {
        $questions = ApplicationQuestion::on($connection)->where('job_id', $job->id)->get()->keyBy('id');

        foreach ($orderedQuestionIds as $id) {
            if (!$questions->has($id)) {
                throw new \RuntimeException("Question {$id} does not belong to this job.");
            }
        }

        if (count($orderedQuestionIds) !== $questions->count()) {
            throw new \RuntimeException('The reorder list must include every question on this job exactly once.');
        }

        foreach ($orderedQuestionIds as $i => $id) {
            ApplicationQuestion::on($connection)->where('id', $id)->update(['order' => $i + 1]);
        }

        $this->forgetJobCache($job);
    }

    /**
     * @throws \RuntimeException if candidates have already answered this question
     */
    public function delete(ApplicationQuestion $question, string $connection): void
    {
        // Safety check — never delete a question that candidates have
        // already answered (application_answers table already exists in
        // the schema even though candidate submission itself isn't built
        // yet — this check is forward-safe regardless, same pattern as
        // PipelineStageService's delete safety check).
        $hasAnswers = DB::connection($connection)->table('application_answers')
            ->where('question_id', $question->id)
            ->exists();

        if ($hasAnswers) {
            throw new \RuntimeException('Candidates have already answered this question — it cannot be deleted.');
        }

        $jobId = $question->job_id;
        $question->delete();

        $job = Job::on($connection)->find($jobId);
        if ($job) {
            $this->forgetJobCache($job);
        }
    }

    protected function forgetJobCache(Job $job): void
    {
        CacheVersion::bump("company:{$job->company_id}:jobs");
    }

    protected function forgetJobCacheById(string $jobId, string $connection): void
    {
        $job = Job::on($connection)->find($jobId);
        if ($job) {
            $this->forgetJobCache($job);
        }
    }
}
