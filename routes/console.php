<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// PRD Section 10 — trial expiry (billing.trial_days, 14 by default): moves companies to read_only.
Schedule::command('trial:enforce')->hourly();

// Reminder emails to the Owner: trial ends in 3 days, and again the day before. Once per company and reminder.
Schedule::command('trial:remind')->dailyAt('09:00');

// PRD Section 57 — Gmail watch registrations expire after 7 days;
// renew any expiring within 24 hours so reply-sync never silently stops.
Schedule::command('gmail:renew-watches')->daily();

// PRD Section 57 — Microsoft Graph subscriptions expire much sooner
// (~3 days) — runs more frequently than the Gmail equivalent.
Schedule::command('microsoft:renew-subscriptions')->everySixHours();
