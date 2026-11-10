<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One candidate per email per company (PRD Section 43/133 - identity = normalized email).
 *
 * Until now this rule lived only in PHP ("look for the email, create if missing"), so two requests arriving at the same
 * moment (a double click, a careers-page submit racing a manual add) could each create the same person. The database
 * now enforces it. Deleted (soft-deleted) candidates are ignored, so a person can be added again after being deleted.
 *
 * The index cannot be built while duplicates exist, so this stops with the list instead of failing with an obscure
 * error halfway. Merge those candidates first (move their applications, notes and emails to one of them).
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::select("
            SELECT company_id, normalized_email, COUNT(*) AS total
            FROM candidates
            WHERE deleted_at IS NULL
            GROUP BY company_id, normalized_email
            HAVING COUNT(*) > 1
        ");

        if (!empty($duplicates)) {
            $list = collect($duplicates)->take(20)
                ->map(fn ($d) => "{$d->normalized_email} (company {$d->company_id}, {$d->total} candidates)")
                ->implode('; ');

            throw new RuntimeException(
                'Cannot add the unique candidate-email index: ' . count($duplicates) . ' email(s) belong to more than one candidate. ' .
                'Merge them first. ' . $list
            );
        }

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_candidates_company_email_unique
            ON candidates (company_id, normalized_email)
            WHERE deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_candidates_company_email_unique');
    }
};
