<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Jobs\CreateQuestionRequest;
use App\Http\Requests\Jobs\ReorderQuestionsRequest;
use App\Http\Requests\Jobs\UpdateQuestionRequest;
use App\Models\ApplicationQuestion;
use App\Models\Job;
use App\Services\ApplicationQuestionService;
use Illuminate\Http\Request;

class ApplicationQuestionController extends Controller
{
    public function __construct(protected ApplicationQuestionService $questions) {}

    public function index(Request $request, string $jobId)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $job = Job::on($connection)->find($jobId);

        if (!$job) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        if (!$user->can('view', $job)) {
            return response()->json(['message' => 'You do not have permission to view this job.'], 403);
        }

        return response()->json(['questions' => $job->applicationQuestions]);
    }

    /**
     * PRD Section 28-29 — same permission rule as editing the job itself
     * (Owner/HM only); the PRD doesn't define a separate ability for
     * application questions.
     */
    public function store(CreateQuestionRequest $request, string $jobId)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $job = Job::on($connection)->find($jobId);

        if (!$job) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        if (!$user->can('update', $job)) {
            return response()->json(['message' => 'You do not have permission to manage this job\'s application questions.'], 403);
        }

        $question = $this->questions->create($job, $request->validated(), $connection);

        return response()->json(['question' => $question], 201);
    }

    public function update(UpdateQuestionRequest $request, string $jobId, string $questionId)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $job = Job::on($connection)->find($jobId);

        if (!$job) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        if (!$user->can('update', $job)) {
            return response()->json(['message' => 'You do not have permission to manage this job\'s application questions.'], 403);
        }

        $question = ApplicationQuestion::on($connection)->where('job_id', $jobId)->find($questionId);

        if (!$question) {
            return response()->json(['message' => 'Question not found.'], 404);
        }

        $question = $this->questions->update($question, $request->validated(), $connection);

        return response()->json(['question' => $question]);
    }

    public function reorder(ReorderQuestionsRequest $request, string $jobId)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $job = Job::on($connection)->find($jobId);

        if (!$job) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        if (!$user->can('update', $job)) {
            return response()->json(['message' => 'You do not have permission to manage this job\'s application questions.'], 403);
        }

        try {
            $this->questions->reorder($job, $request->validated()['question_order'], $connection);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['questions' => $job->applicationQuestions()->get()]);
    }

    public function destroy(Request $request, string $jobId, string $questionId)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $job = Job::on($connection)->find($jobId);

        if (!$job) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        if (!$user->can('update', $job)) {
            return response()->json(['message' => 'You do not have permission to manage this job\'s application questions.'], 403);
        }

        $question = ApplicationQuestion::on($connection)->where('job_id', $jobId)->find($questionId);

        if (!$question) {
            return response()->json(['message' => 'Question not found.'], 404);
        }

        try {
            $this->questions->delete($question, $connection);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Question deleted.']);
    }
}
