<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Emails\ComposeEmailRequest;
use App\Http\Requests\Emails\SendEmailRequest;
use App\Models\Candidate;
use App\Models\Company;
use App\Models\Interview;
use App\Models\Job;
use App\Services\EmailService;
use App\Support\EmailText;
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

        $emails = $this->emails->listForCandidate($candidateId, $user, $connection)
            ->map(function ($email) {
                // Stored bodies are HTML. The app shows only these plain-text fields, so no sender
                // HTML ever reaches the browser; the quoted history is kept separate, not discarded.
                $parts = EmailText::split($email->body);
                $email->setAttribute('body_text', $parts['text']);
                $email->setAttribute('quoted_text', $parts['quoted']);

                return $email;
            });

        // The list above was read first, so this response still shows what was new.
        $this->emails->markThreadRead($candidateId, $user, $connection);

        return response()->json(['emails' => $emails]);
    }

    /**
     * Compose: an email to any address(es) (not only a candidate), with optional attachments, sent from the user's own
     * connected mailbox. Any signed-in user can use it; it is strictly personal like the rest of their mail.
     */
    public function compose(ComposeEmailRequest $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $data = $request->validated();
        $files = $request->file('attachments') ?? [];

        $total = array_sum(array_map(fn ($f) => $f->getSize(), $files));
        if ($total > ComposeEmailRequest::MAX_KB * 1024) {
            return response()->json(['message' => 'The files together must be 3 MB or smaller.', 'errors' => ['attachments' => ['The files together must be 3 MB or smaller.']]], 422);
        }

        try {
            $email = $this->emails->composeEmail($user, $data['to'], $data['subject'], $data['body'], $files, $connection);
        } catch (\RuntimeException $e) {
            // Same rule as store(): the failure is visible to Super Admin, and the user never sees transport details.
            // The body is deliberately NOT logged: this is free-form personal mail, unlike a template send.
            $this->systemEvents->log('email', $e->getMessage(), $user->company_id, str_replace('pgsql_', '', $connection), [
                'mode' => 'compose',
                'recipient_count' => count($data['to']),
                'attachment_count' => count($files),
                'user_id' => $user->id,
            ]);

            $message = $e instanceof \Symfony\Component\Mailer\Exception\TransportExceptionInterface
                ? "We couldn't send the email right now. Please try again in a few minutes."
                : $e->getMessage();

            return response()->json(['message' => str_starts_with($message, 'Gmail send failed') || str_starts_with($message, 'Microsoft Graph mail send failed')
                ? "We couldn't send the email right now. Please try again in a few minutes."
                : $message], 422);
        }

        return response()->json(['email' => $email], 201);
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
        $files = $request->file('attachments') ?? [];

        if ($files) {
            // Files go out only from the user's own mailbox: the system address and "log" have nowhere to put them.
            if ($data['mode'] !== 'send_personal') {
                return response()->json(['message' => 'Files can only be attached to an email sent from your own mailbox.'], 422);
            }
            if (array_sum(array_map(fn ($f) => $f->getSize(), $files)) > ComposeEmailRequest::MAX_KB * 1024) {
                return response()->json(['message' => 'The files together must be 3 MB or smaller.', 'errors' => ['attachments' => ['The files together must be 3 MB or smaller.']]], 422);
            }
        }

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

        // The caller says WHICH application the email is about (e.g. the one just rejected): its job
        // wins over "latest application". An id that is not this candidate's is refused rather than
        // silently falling back to a different job's title in a rejection email.
        if ($data['application_id'] ?? null) {
            $selected = \App\Models\Application::on($connection)
                ->where('candidate_id', $candidateId)
                ->find($data['application_id']);

            if (!$selected) {
                return response()->json(['message' => 'That application does not belong to this candidate.'], 404);
            }

            $job = Job::on($connection)->find($selected->job_id);
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
                    $job, $interview, $company->name, $connection, $files
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
                'attachment_count' => count($files), // a retry re-sends the text only: the files are not kept
                'user_id' => $user->id,
            ]);

            // A mail-TRANSPORT failure (SMTP down / wrong credentials) carries server and username
            // details in its message: those stay in the Debugging log above, the user gets a plain one.
            // Our own messages (template errors, "connect your Gmail first") are meant for the user.
            $message = $e instanceof \Symfony\Component\Mailer\Exception\TransportExceptionInterface
                ? "We couldn't send the email right now. Please try again in a few minutes."
                : $e->getMessage();

            return response()->json(['message' => $message], 422);
        }

        return response()->json(['email' => $email], 201);
    }
}
