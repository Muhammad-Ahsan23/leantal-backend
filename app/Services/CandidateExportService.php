<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\User;

/**
 * PRD Section 101 — "Permission-controlled CSV export containing
 * candidate attributes, application info, current stage, and creation
 * dates. Never export OAuth tokens or passwords." (The latter isn't
 * even reachable here — Candidate has no such fields — but the
 * principle is respected: only the columns explicitly named are ever
 * included.)
 *
 * PRD Section 72 (GDPR) — "Candidate data access and CSV export" is
 * also how the data-access right is satisfied.
 *
 * One row per APPLICATION (not per candidate) — a candidate with
 * applications to 2 different jobs gets 2 rows, since "application
 * info" and "current stage" are per-application facts, not per-person.
 * A candidate with zero applications still gets one row (job/stage
 * columns blank) so they aren't silently dropped from the export.
 */
class CandidateExportService
{
    /**
     * PRD Section 101's "permission-controlled" is satisfied by
     * reusing the EXACT same visibility scope as the candidates list
     * itself (Candidate::scopeVisibleTo()) — an export never surfaces
     * a candidate the exporting user couldn't already see in the UI.
     */
    public function export(User $user, string $connection): string
    {
        $candidates = Candidate::on($connection)
            ->visibleTo($user)
            ->with(['applications.job', 'applications.stage'])
            ->orderBy('created_at')
            ->get();

        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'Name', 'Email', 'Phone', 'Location', 'Current Title', 'Current Company',
            'LinkedIn URL', 'Status', 'Job Title', 'Current Stage', 'Applied At', 'Created At',
        ]);

        foreach ($candidates as $candidate) {
            $applications = $candidate->applications;

            if ($applications->isEmpty()) {
                $this->writeRow($handle, $candidate, null, null, null);
                continue;
            }

            foreach ($applications as $application) {
                $this->writeRow($handle, $candidate, $application->job?->title, $application->stage?->name, $application->applied_at);
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    protected function writeRow($handle, Candidate $candidate, ?string $jobTitle, ?string $stageName, $appliedAt): void
    {
        fputcsv($handle, [
            $candidate->name,
            $candidate->email,
            $candidate->phone,
            $candidate->location,
            $candidate->current_title,
            $candidate->current_company,
            $candidate->linkedin_url,
            $candidate->status,
            $jobTitle,
            $stageName,
            $appliedAt?->toDateString(),
            $candidate->created_at->toDateString(),
        ]);
    }
}
