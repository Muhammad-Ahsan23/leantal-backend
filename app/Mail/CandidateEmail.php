<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Generic mailable for candidate-facing emails (rejections, template-based
 * sends, etc.) — subject/body are DYNAMIC (come from an EmailTemplate or
 * free text), not fixed per email type, so this is one reusable Mailable
 * rather than a separate class per email kind.
 */
class CandidateEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $emailSubject,
        public string $htmlBody,
    ) {}

    public function envelope()
    {
        return new \Illuminate\Mail\Mailables\Envelope(subject: $this->emailSubject);
    }

    public function content()
    {
        return new \Illuminate\Mail\Mailables\Content(
            htmlString: $this->htmlBody,
        );
    }
}
