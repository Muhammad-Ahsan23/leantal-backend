<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Emails\SendEmailRequest;
use App\Models\Candidate;
use App\Models\Company;
use App\Models\Interview;
use App\Models\Job;
use App\Services\EmailService;
use Illuminate\Http\Request;

class EmailController extends Controller
{
    public function __construct(
        protected EmailService $emails,
        protected \App\Services\SystemEventLogger $systemEvents,
    ) {}

    /**
     * PRD Section 57 — thread listing. Privacy filtering (system
     * emails visible to all candidate-viewers, personal-inbox emails
     * visible only to their sender) happens inside
     * EmailService::listForCandidate() — see its docblock for the
     * PRD citations behind that split.
     */
    public function index(Request $request, string $candidateId)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $candidate = Candidate::on($connection)->find($candidateId);

        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found.'], 404);
        }

        if (!$user->can('view', $candidate)) {
            return response()->json(['message' => 'You do not have permission to view this candidate.'], 403);
        }

        $emails = $this->emails->listForCandidate($candidateId, $user, $connection);

        return response()->json(['emails' => $emails]);
    }

    /**
     * PRD Section 55 — three modes:
     * - mode=send_personal: sends via the ACTING user's own connected
     *   Gmail. Requires Google connected — "strictly personal", no
     *   shared inboxes.
     * - mode=send_system: sends via our SMTP as noreply@leantal.com —
     *   powers rejection emails.
     * - mode=log: records an email sent through some other means
     *   (Outlook — not yet wired for real sending).
     *
     * Same permission as adding a note (CandidatePolicy::update).
     */
    public function store(SendEmailRequest $request, string $candidateId)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $candidate = Candidate::on($connection)->find($candidateId);

        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found.'], 404);
        }

        if (!$user->can('update', $candidate)) {
            return response()->json(['message' => 'You do not have permission to email this candidate.'], 403);
        }

        $data = $request->validated();
        $company = Company::on($connection)->find($user->company_id);
        $job = null;
        $interview = null;

        // Job context comes from the candidate's application by
        // default — most rejection/communication emails need
        // {{job.title}} even when NO interview has been scheduled at
        // all. interview_id (below) can still override this when the
        // email is specifically about a particular interview.
        $application = \App\Models\Application::on($connection)
            ->where('candidate_id', $candidateId)
            ->latest('created_at')
            ->first();
        if ($application) {
            $job = Job::on($connection)->find($application->job_id);
        }

        // Best-effort context for template variables ({{interview.date}},
        // {{meeting_link}}) — only overrides $job if this specific
        // interview points to a different one than the latest application.
        if ($data['interview_id'] ?? null) {
            $interview = Interview::on($connection)->where('candidate_id', $candidateId)->find($data['interview_id']);
            if ($interview) {
                $job = Job::on($connection)->find($interview->job_id);
            }
        }

        try {
            $email = match ($data['mode']) {
                'send_personal' => $this->emails->sendPersonalEmail(
                    $candidate, $user, $data['template_id'] ?? null, $data['subject'] ?? null, $data['body'] ?? null,
                    $job, $interview, $company->name, $connection
                ),
                'send_system' => $this->emails->sendSystemEmail(
                    $candidate, $data['template_id'] ?? null, $data['subject'] ?? null, $data['body'] ?? null,
                    $job, $user, $interview, $company->name, $user, $connection
                ),
                'log' => $this->emails->logManualEmail($candidate, $user, $data, $connection),
            };
        } catch (\RuntimeException $e) {
            // PRD Section 82 — "failed email dispatches" must be
            // visible in the Super Admin Debugging view, not just a
            // text log line. Context carries enough to retry (which
            // candidate/mode/template) from there.
            $this->systemEvents->log('email', $e->getMessage(), $user->company_id, str_replace('pgsql_', '', $connection), [
                'candidate_id' => $candidateId,
                'mode' => $data['mode'],
                'template_id' => $data['template_id'] ?? null,
                'subject' => $data['subject'] ?? null,
                'body' => $data['body'] ?? null,
                'user_id' => $user->id,
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['email' => $email], 201);
    }
}
