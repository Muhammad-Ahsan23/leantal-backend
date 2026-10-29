<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Copy for the person who scheduled the interview (the host), so they get the Zoom join link by email too. */
class InterviewOrganizerNotice extends Mailable
{
    public function __construct(
        public string $method,
        public string $organizerName,
        public string $candidateName,
        public string $jobTitle,
        public string $when,
        public ?string $meetingUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->method === 'CANCEL' ? 'Interview cancelled' : 'Interview scheduled').": {$this->candidateName} - {$this->jobTitle}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.interview_organizer_notice');
    }
}
