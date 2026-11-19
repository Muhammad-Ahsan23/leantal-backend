<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Compose can send to ANY address, not only to a candidate: an email no longer needs a candidate, and it keeps who it
// was sent to (to_emails) and the names/sizes of the files that went with it (attachments; the files themselves are not stored).
// Runs on every regional database (--database=pgsql_us / pgsql_eu / pgsql_uk), like the other tenant migrations.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE emails ALTER COLUMN candidate_id DROP NOT NULL');
        DB::statement('ALTER TABLE emails ADD COLUMN IF NOT EXISTS to_emails jsonb');
        DB::statement('ALTER TABLE emails ADD COLUMN IF NOT EXISTS attachments jsonb');
    }

    public function down(): void
    {
        // Rows without a candidate cannot go back into a NOT NULL column.
        DB::statement('DELETE FROM emails WHERE candidate_id IS NULL');
        DB::statement('ALTER TABLE emails ALTER COLUMN candidate_id SET NOT NULL');
        DB::statement('ALTER TABLE emails DROP COLUMN IF EXISTS to_emails');
        DB::statement('ALTER TABLE emails DROP COLUMN IF EXISTS attachments');
    }
};
