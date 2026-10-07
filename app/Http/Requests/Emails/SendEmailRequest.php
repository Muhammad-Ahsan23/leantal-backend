<?php

namespace App\Http\Requests\Emails;

use Illuminate\Foundation\Http\FormRequest;

class SendEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // PRD Section 55 — 'send_personal' sends via the ACTING
            // user's own connected Gmail (requires Google connected,
            // "no shared inboxes"). 'send_system' sends via our system
            // SMTP as noreply@leantal.com — intended for rejections,
            // per PRD's explicit carve-out, though not hard-restricted
            // to only that use since the controller can't robotically
            // detect "this is a rejection". 'log' just records an
            // email sent through some other means (e.g. Outlook, not
            // yet wired for real sending).
            'mode' => ['required', 'in:send_personal,send_system,log'],
            'template_id' => ['nullable', 'uuid'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
            // Optional context for template variable-filling
            // ({{interview.date}}, {{meeting_link}}) — omit if not
            // relevant to this send.
            'interview_id' => ['nullable', 'uuid'],
            // Which of the candidate's applications this email is about (PRD Section 32: one
            // candidate can apply to several jobs). A rejection is about ONE of them, so {{job.title}}
            // must come from that application's job, not just the candidate's latest application.
            'application_id' => ['nullable', 'uuid'],
            // Only meaningful for mode=log:
            'direction' => ['nullable', 'in:outbound,inbound'],
            'provider' => ['nullable', 'in:gmail,outlook'],
            'sent_at' => ['nullable', 'date'],
        ];
    }
}
