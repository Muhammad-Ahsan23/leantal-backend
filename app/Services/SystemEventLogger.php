<?php

namespace App\Services;

use App\Models\SystemEvent;

/**
 * PRD Section 82 — "failed email dispatches, calendar sync errors,
 * webhook failures... with safe retry triggers." One reusable entry
 * point so every existing try/catch in EmailService, InterviewService,
 * etc. logs consistently into a queryable table instead of only a
 * text log file — that's what makes the Super Admin Debugging view
 * (Section 82) actually possible without "arbitrary SQL in the UI."
 */
class SystemEventLogger
{
    public function log(string $category, string $message, ?string $companyId = null, ?string $region = null, array $context = []): void
    {
        SystemEvent::create([
            'category' => $category,
            'region' => $region,
            'company_id' => $companyId,
            'message' => $message,
            'context' => $context,
        ]);
    }
}
