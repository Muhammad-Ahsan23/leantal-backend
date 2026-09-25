<?php

namespace App\Services;

use App\Mail\CandidateEmail;
use App\Models\Candidate;
use App\Models\Email;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EmailService
{
    /**
     * PRD Section 55-56 — sends a real email via our own SMTP (the same
     * Mailer already used for OTP/invite/password-reset — no Gmail/
     * Outlook OAuth needed for this path) and logs it against the
     * candidate. provider='system' distinguishes this from a future
     * Gmail/Outlook-sent email (provider='gmail'/'outlook', not built
     * yet — see OAuthConnectionController).
     *
     * If $templateId is given, {{variable}} placeholders in the
     * template's subject/body are substituted from $variables before
     * sending — otherwise $subject/$body are used as-is (free text).
     */
    public function sendSystemEmail(
        Candidate $candidate,
        ?string $templateId,
        ?string $subject,
        ?string $body,
        array $variables,
        ?User $actor,
        string $connection
    ): Email {
        if ($templateId) {
            $template = EmailTemplate::on($connection)->where('company_id', $candidate->company_id)->find($templateId);
            if (!$template) {
                throw new \RuntimeException('Email template not found.');
            }
            $subject = $this->renderTemplate($template->subject, $variables);
            $body = $this->renderTemplate($template->body, $variables);
        }

        if (!$subject || !$body) {
            throw new \RuntimeException('Subject and body are required (either directly or via a template).');
        }

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

        $this->logActivity($connection, $candidate->company_id, $actor?->id, 'candidate.email_sent', $candidate->id, [
            'subject' => $subject,
        ]);

        return $email;
    }

    /**
     * PRD Section 53-54 — for when a staff member sent an email from
     * their OWN Gmail/Outlook (not through our system — that live sync
     * isn't wired yet) but still wants it tracked on the candidate's
     * timeline. This does NOT actually send anything, just records it.
     */
    public function logManualEmail(Candidate $candidate, User $actor, array $data, string $connection): Email
    {
        $email = Email::on($connection)->create([
            'company_id' => $candidate->company_id,
            'user_id' => $actor->id,
            'candidate_id' => $candidate->id,
            'direction' => $data['direction'] ?? 'outbound',
            'provider' => $data['provider'] ?? 'gmail',
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'] ?? null,
            'sent_at' => $data['sent_at'] ?? now(),
        ]);

        $this->logActivity($connection, $candidate->company_id, $actor->id, 'candidate.email_logged', $candidate->id, [
            'subject' => $email->subject,
        ]);

        return $email;
    }

    protected function renderTemplate(string $text, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $text = str_replace('{{'.$key.'}}', $value, $text);
        }

        return $text;
    }

    protected function logActivity(string $connection, string $companyId, ?string $actorId, string $action, string $candidateId, array $metadata = []): void
    {
        DB::connection($connection)->table('activity')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'actor_id' => $actorId, // nullable — system-sent emails have no staff actor
            'action' => $action,
            'object_type' => 'candidate',
            'object_id' => $candidateId,
            'metadata' => json_encode($metadata),
            'created_at' => now(),
        ]);
    }
}
