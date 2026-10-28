<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent to a Super Admin when their account signs in from an IP it has not used before. */
class SuperAdminNewSignIn extends Mailable
{
    public function __construct(
        public string $adminName,
        public string $ip,
        public string $device,
        public string $when,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New sign-in to the LeanTal admin panel');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.super_admin_new_signin');
    }
}
