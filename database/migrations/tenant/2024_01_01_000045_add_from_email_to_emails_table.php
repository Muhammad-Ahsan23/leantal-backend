<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Who an INBOUND email came from. Replies from candidates already know their sender (the candidate row), but a reply to
// an email composed to someone who is not a candidate has no candidate, so the sender address is kept here instead.
// Runs on every regional database (--database=pgsql_us / pgsql_eu / pgsql_uk), like the other tenant migrations.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE emails ADD COLUMN IF NOT EXISTS from_email varchar(255)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE emails DROP COLUMN IF EXISTS from_email');
    }
};
