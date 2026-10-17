<?php

return [
    /*
    | Days a company keeps FULL access after a renewal payment fails (Creem reports the subscription
    | as past_due). Creem retries the charge during this time; if it succeeds the company carries on
    | untouched. When the days run out, or Creem reports the subscription unpaid, the company becomes
    | read_only (PRD Sections 120 / 129: view and export only, nothing is deleted).
    */
    'past_due_grace_days' => (int) env('BILLING_PAST_DUE_GRACE_DAYS', 7),
];
