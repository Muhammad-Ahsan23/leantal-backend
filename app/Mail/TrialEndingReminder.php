<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent to a company Owner shortly before the free trial ends ("3 days left" and "ends tomorrow"). */
class TrialEndingReminder extends Mailable
{
    public function __construct(
        public string $ownerName,
        public string $companyName,
        public string $planName,
        public int $daysLeft,
        public string $endsOn,
        public string $billingUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->daysLeft <= 1
                ? 'Your LeanTal trial ends tomorrow'
                : "Your LeanTal trial ends in {$this->daysLeft} days",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.trial_ending');
    }
}
