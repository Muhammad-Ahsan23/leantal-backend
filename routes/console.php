<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// PRD Section 10 — 72-hour trial expiry, moves companies to read_only.
Schedule::command('trial:enforce')->hourly();

// PRD Section 57 — Gmail watch registrations expire after 7 days;
// renew any expiring within 24 hours so reply-sync never silently stops.
Schedule::command('gmail:renew-watches')->daily();

// PRD Section 57 — Microsoft Graph subscriptions expire much sooner
// (~3 days) — runs more frequently than the Gmail equivalent.
Schedule::command('microsoft:renew-subscriptions')->everySixHours();
