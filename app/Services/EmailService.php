<?php

namespace App\Services;

use App\Mail\CandidateEmail;
use App\Models\Candidate;
use App\Models\Email;
use App\Models\EmailTemplate;
use App\Models\Interview;
use App\Models\Job;
use App\Models\OAuthToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * PRD Section 53-58 — Email module.
 *
 * SCOPE NOTE: this covers sending (system address for rejections, the
 * user's own connected Gmail for everything else) and template
 * rendering/validation. PRD Section 57's "receive and display replies,
 * maintain conversation context" — i.e. syncing INBOUND email — is
 * NOT covered here. That needs either scheduled Gmail API polling or
 * Google Cloud Pub/Sub push notifications (with 7-day watch renewal),
 * which is meaningfully separate infrastructure from sending — flagged
 * for a follow-up pass, not silently dropped.
 */
class EmailService
{
    public function __construct(
        protected GmailService $gmail,
        protected MicrosoftGraphMailService $microsoftMail,
    ) {}

    /**
     * PRD Section 58 — exact variable names: {{candidate.first_name}},
     * {{job.title}}, {{company.name}}, {{recruiter.name}},
     * {{interview.date}}, {{meeting_link}}. Built centrally here (not
     * left to the caller) so every send point — system or personal —
     * uses the same, correct, PRD-exact naming rather than each
     * inventing its own flat variable names.
     */
    protected function buildStandardVariables(Candidate $candidate, ?Job $job, ?User $recruiter, ?Interview $interview, string $companyName): array
    {
        $firstName = trim(explode(' ', $candidate->name)[0] ?? $candidate->name);

        return array_filter([
            'candidate.first_name' => $firstName,
            'job.title' => $job?->title,
            'company.name' => $companyName,
            'recruiter.name' => $recruiter?->name,
            'interview.date' => $interview?->start_time?->format('F j, Y \a\t g:i A'),
            'meeting_link' => $interview?->meeting_url,
        ], fn ($v) => $v !== null);
    }

    protected function renderTemplate(string $text, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $text = str_replace('{{'.$key.'}}', $value, $text);
        }

        return $text;
    }

    /**
     * PRD Section 58 — "Validate variables prior to dispatch; do not
     * send raw unfilled brackets." Runs AFTER substitution — if any
     * {{...}} pattern survives (a variable the template referenced but
     * we had no value for, e.g. {{interview.date}} on a template sent
     * with no interview context), the send is refused rather than
     * silently going out with literal "{{interview.date}}" in it.
     *
     * @throws \RuntimeException listing the unfilled placeholders
     */
    protected function assertNoUnfilledPlaceholders(string $subject, string $body): void
    {
        preg_match_all('/\{\{([a-zA-Z0-9_.]+)\}\}/', $subject.' '.$body, $matches);

        if (!empty($matches[1])) {
            $unique = array_unique($matches[1]);
            throw new \RuntimeException('Template has unfilled variables: '.implode(', ', $unique));
        }
    }

    /**
     * PRD Section 55 — "Rejection emails: Sent from system address
     * noreply@leantal.com." Uses our own SMTP (same Mailer as OTP/
     * invite emails) — no OAuth needed for this path. provider='system'
     * distinguishes this from a personal-inbox send.
     */
    public function sendSystemEmail(
        Candidate $candidate,
        ?string $templateId,
        ?string $subject,
        ?string $body,
        ?Job $job,
        ?User $recruiter,
        ?Interview $interview,
        string $companyName,
        ?User $actor,
        string $connection
    ): Email {
        $variables = $this->buildStandardVariables($candidate, $job, $recruiter, $interview, $companyName);

        if ($templateId) {
            $template = EmailTemplate::on($connection)->where('company_id', $candidate->company_id)->find($templateId);
            if (!$template) {
                throw new \RuntimeException('Email template not found.');
            }
            $subject = $this->renderTemplate($template->subject, $variables);
            $body = $this->renderTemplate($template->body, $variables);
        } elseif ($subject && $body) {
            $subject = $this->renderTemplate($subject, $variables);
            $body = $this->renderTemplate($body, $variables);
        }

        if (!$subject || !$body) {
            throw new \RuntimeException('Subject and body are required (either directly or via a template).');
        }

        $this->assertNoUnfilledPlaceholders($subject, $body);

        Mail::to($candidate->email)->send(new CandidateEmail($subject, $body));

        $email = Email::on($connection)->create([
            'company_id' => $candidate->company_id,
            'user_id' => $actor?->id, // null = automated system send (e.g. auto-rejection)
            'candidate_id' => $candidate->id,
            'direction' => 'outbound',
            'provider' => 'system',
            'subject' => $subject,
            'body' => $body,
            'sent_at' => now(),
        ]);

        $this->logActivity($connection, $candidate->company_id, $actor?->id, 'candidate.email_sent', $candidate->id, ['subject' => $subject]);

        return $email;
    }

    /**
     * PRD Section 55 — "Recruiting/Communication emails: Sent from the
     * user's connected personal inbox." PRD Section 57 — "Strictly
     * personal to the connected user": this email is recorded with
     * user_id = the SENDER, and EmailController::index() filters
     * personal-inbox emails so only that same user can ever see this
     * row again — not even the Owner, per PRD Sections 5.1/142's
     * visibility lists (jobs/candidates/interviews are explicitly
     * Owner-visible; inbox/email content is conspicuously absent from
     * both lists).
     *
     * @throws \RuntimeException if the actor has neither Gmail nor Outlook connected, or on send failure
     */
    public function sendPersonalEmail(
        Candidate $candidate,
        User $actor,
        ?string $templateId,
        ?string $subject,
        ?string $body,
        ?Job $job,
        ?Interview $interview,
        string $companyName,
        string $connection,
        array $files = []
    ): Email {
        // PRD Rule 2 — "Every LeanTal user connects their own Gmail OR
        // Outlook account." Whichever one this actor has connected is
        // the one used — no preference order needed since a user only
        // realistically connects the inbox they actually use.
        $googleToken = OAuthToken::on($connection)->where('user_id', $actor->id)->where('provider', 'google')->whereNull('disconnected_at')->first();
        $microsoftToken = OAuthToken::on($connection)->where('user_id', $actor->id)->where('provider', 'microsoft')->whereNull('disconnected_at')->first();

        if (!$googleToken && !$microsoftToken) {
            throw new \RuntimeException('Connect your Gmail or Outlook account first (Settings > Integrations) to send from your own inbox.');
        }

        $variables = $this->buildStandardVariables($candidate, $job, $actor, $interview, $companyName);

        if ($templateId) {
            $template = EmailTemplate::on($connection)->where('company_id', $candidate->company_id)->find($templateId);
            if (!$template) {
                throw new \RuntimeException('Email template not found.');
            }
            $subject = $this->renderTemplate($template->subject, $variables);
            $body = $this->renderTemplate($template->body, $variables);
        } elseif ($subject && $body) {
            $subject = $this->renderTemplate($subject, $variables);
            $body = $this->renderTemplate($body, $variables);
        }

        if (!$subject || !$body) {
            throw new \RuntimeException('Subject and body are required (either directly or via a template).');
        }

        $this->assertNoUnfilledPlaceholders($subject, $body);

        ['attachments' => $attachments, 'meta' => $meta] = $this->readAttachments($files);

        $provider = $googleToken ? 'gmail' : 'outlook';
        if ($googleToken) {
            $this->gmail->sendEmail($googleToken, $candidate->email, $subject, $body, $attachments);
        } else {
            $this->microsoftMail->sendEmail($microsoftToken, $candidate->email, $subject, $body, $attachments);
        }

        $email = Email::on($connection)->create([
            'company_id' => $candidate->company_id,
            'user_id' => $actor->id,
            'candidate_id' => $candidate->id,
            'direction' => 'outbound',
            'provider' => $provider,
            'subject' => $subject,
            'body' => $body,
            'attachments' => $meta ?: null,
            'sent_at' => now(),
        ]);

        // Activity log entry is visible company-wide (PRD Section 141 —
        // "Email Sent" is a timeline event) even though the email's own
        // content stays private — the FACT an email was sent is not
        // secret, only what it said.
        $this->logActivity($connection, $candidate->company_id, $actor->id, 'candidate.email_sent', $candidate->id, ['subject' => $subject]);

        return $email;
    }

    /**
     * Uploaded files -> what the mail providers need (raw content) and what is kept on the email (names and sizes only).
     *
     * @param \Illuminate\Http\UploadedFile[] $files
     * @return array{attachments: array<int, array{name: string, mime: string, data: string}>, meta: array<int, array{name: string, size: int, mime: string}>}
     */
    protected function readAttachments(array $files): array
    {
        $attachments = [];
        $meta = [];
        foreach ($files as $file) {
            $name = preg_replace('#[\x00-\x1F"\\\\/]+#', '_', $file->getClientOriginalName()) ?: 'attachment';
            $mime = $file->getMimeType() ?: 'application/octet-stream';
            $attachments[] = ['name' => $name, 'mime' => $mime, 'data' => (string) file_get_contents($file->getRealPath())];
            $meta[] = ['name' => $name, 'size' => $file->getSize(), 'mime' => $mime];
        }

        return ['attachments' => $attachments, 'meta' => $meta];
    }

    /**
     * Compose: sends an email to ANY address(es) from the actor's own connected Gmail/Outlook, with optional attachments.
     * Never from the system address. The message is recorded as the actor's personal mail (user_id = actor) with who it
     * went to and the attachment names. If one of the recipients is a candidate of this company that the actor may work
     * with, the email is also linked to that candidate, so it shows in their conversation like any other email.
     *
     * @param string[] $recipients validated, distinct e-mail addresses
     * @param \Illuminate\Http\UploadedFile[] $files
     * @throws \RuntimeException when no mailbox is connected, or the provider refuses the send
     */
    public function composeEmail(User $actor, array $recipients, string $subject, string $body, array $files, string $connection): Email
    {
        $googleToken = OAuthToken::on($connection)->where('user_id', $actor->id)->where('provider', 'google')->whereNull('disconnected_at')->first();
        $microsoftToken = OAuthToken::on($connection)->where('user_id', $actor->id)->where('provider', 'microsoft')->whereNull('disconnected_at')->first();

        if (!$googleToken && !$microsoftToken) {
            throw new \RuntimeException('Connect your Gmail or Outlook account first (Settings > Integrations) to send from your own inbox.');
        }

        $recipients = array_values(array_map('strtolower', $recipients));

        ['attachments' => $attachments, 'meta' => $meta] = $this->readAttachments($files);

        // What the person typed is plain text: escape it, and keep their line breaks.
        $html = nl2br(e($body), false);

        $provider = $googleToken ? 'gmail' : 'outlook';
        if ($googleToken) {
            $this->gmail->sendEmail($googleToken, $recipients, $subject, $html, $attachments);
        } else {
            $this->microsoftMail->sendEmail($microsoftToken, $recipients, $subject, $html, $attachments);
        }

        $candidate = Candidate::on($connection)
            ->where('company_id', $actor->company_id)
            ->whereIn('normalized_email', $recipients)
            ->get()
            ->first(fn (Candidate $c) => $actor->can('update', $c));

        $email = Email::on($connection)->create([
            'company_id' => $actor->company_id,
            'user_id' => $actor->id,
            'candidate_id' => $candidate?->id,
            'direction' => 'outbound',
            'provider' => $provider,
            'subject' => $subject,
            'body' => $html,
            'to_emails' => array_map(fn ($a) => ['email' => $a], $recipients),
            'attachments' => $meta ?: null,
            'sent_at' => now(),
        ]);

        if ($candidate) {
            $this->logActivity($connection, $actor->company_id, $actor->id, 'candidate.email_sent', $candidate->id, ['subject' => $subject]);
        }

        return $email;
    }

    /**
     * For when a staff member sent an email from their own Gmail/
     * Outlook through some OTHER means (not via this system's send
     * button) but still wants it tracked on the candidate's timeline —
     * or for Outlook specifically, since Outlook OAuth isn't wired yet.
     * Does NOT actually send anything, just records it. Same privacy
     * scoping as sendPersonalEmail (user_id = the logging user).
     */
    public function logManualEmail(Candidate $candidate, User $actor, array $data, string $connection): Email
    {
        $email = Email::on($connection)->create([
            'company_id' => $candidate->company_id,
            'user_id' => $actor->id,
            'candidate_id' => $candidate->id,
            'direction' => $data['direction'] ?? 'outbound',
            'provider' => $data['provider'] ?? 'outlook',
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'] ?? null,
            'sent_at' => $data['sent_at'] ?? now(),
        ]);

        $this->logActivity($connection, $candidate->company_id, $actor->id, 'candidate.email_logged', $candidate->id, ['subject' => $email->subject]);

        return $email;
    }

    /**
     * PRD Section 57 — thread visibility. System emails (rejections)
     * are visible to anyone who can view the candidate (no personal-
     * inbox privacy applies — they're not from anyone's personal
     * account). Personal-inbox emails (gmail/outlook) are visible ONLY
     * to the user_id that sent them, "strictly personal" per PRD —
     * this applies even to the Owner.
     */
    /**
     * Opening a conversation = reading it. Marks the VIEWER's own unread replies from
     * this candidate as read (other users' mail is never touched).
     */
    public function markThreadRead(string $candidateId, User $viewer, string $connection): void
    {
        Email::on($connection)
            ->where('candidate_id', $candidateId)
            ->where('user_id', $viewer->id)
            ->where('direction', 'inbound')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function listForCandidate(string $candidateId, User $viewer, string $connection): \Illuminate\Support\Collection
    {
        return Email::on($connection)
            ->where('candidate_id', $candidateId)
            ->where(function ($query) use ($viewer) {
                $query->where('provider', 'system')
                    ->orWhere('user_id', $viewer->id);
            })
            ->with('user:id,name')
            ->orderBy('sent_at')
            ->get();
    }

    /**
     * PRD Section 82 — "safe retry triggers" for failed email
     * dispatches. Re-runs the exact same send using the context stored
     * at the time of the original failure — never an arbitrary
     * operation, just replaying a known, specific action.
     *
     * @throws \RuntimeException if the candidate/user no longer exists, or the retry itself fails
     */
    public function retryFailedEmail(array $context, string $companyName, string $connection): Email
    {
        $candidate = Candidate::on($connection)->find($context['candidate_id']);
        if (!$candidate) {
            throw new \RuntimeException('Candidate no longer exists — cannot retry.');
        }

        $job = null;
        $application = \App\Models\Application::on($connection)->where('candidate_id', $candidate->id)->latest('created_at')->first();
        if ($application) {
            $job = Job::on($connection)->find($application->job_id);
        }

        if ($context['mode'] === 'send_personal') {
            $actor = User::on($connection)->find($context['user_id']);
            if (!$actor) {
                throw new \RuntimeException('The original sender no longer exists — cannot retry.');
            }

            return $this->sendPersonalEmail($candidate, $actor, $context['template_id'] ?? null, $context['subject'] ?? null, $context['body'] ?? null, $job, null, $companyName, $connection);
        }

        $actor = User::on($connection)->find($context['user_id']);

        return $this->sendSystemEmail($candidate, $context['template_id'] ?? null, $context['subject'] ?? null, $context['body'] ?? null, $job, $actor, null, $companyName, $actor, $connection);
    }

    protected function logActivity(string $connection, string $companyId, ?string $actorId, string $action, string $candidateId, array $metadata = []): void
    {
        DB::connection($connection)->table('activity')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'action' => $action,
            'object_type' => 'candidate',
            'object_id' => $candidateId,
            'metadata' => json_encode($metadata),
            'created_at' => now(),
        ]);
    }
}
