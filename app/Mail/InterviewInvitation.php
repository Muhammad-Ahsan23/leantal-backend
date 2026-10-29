<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The candidate's interview email for providers that do not send their own invite (Zoom). Carries an .ics file so it
 * lands in the candidate's calendar like a Google Meet invite does. $method: REQUEST (new / moved) or CANCEL.
 */
class InterviewInvitation extends Mailable
{
    public function __construct(
        public string $method,
        public string $candidateName,
        public string $jobTitle,
        public string $companyName,
        public string $organizerName,
        public string $organizerEmail,
        public string $when,
        public ?string $meetingUrl,
        public string $ics,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: ($this->method === 'CANCEL' ? 'Interview cancelled' : 'Interview invitation').": {$this->jobTitle} at {$this->companyName}",
            replyTo: [new \Illuminate\Mail\Mailables\Address($this->organizerEmail, $this->organizerName)],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.interview_invitation');
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->ics, 'interview.ics')->withMime("text/calendar; method={$this->method}; charset=UTF-8")];
    }
}
