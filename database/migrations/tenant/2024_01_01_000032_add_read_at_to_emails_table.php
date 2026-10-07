<?php
// PRD Section 53 (Inbox) + Section 17 ("Unread Messages") — the inbox needs to
// know which RECEIVED emails the owner has not opened yet, which nothing stored
// until now. Guarded with hasColumn() so it is safe to run more than once, and on
// every region.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('emails', 'read_at')) {
            Schema::table('emails', function (Blueprint $table) {
                $table->timestampTz('read_at')->nullable();
            });

            // Emails that already exist were all visible inside the candidate threads
            // before this column existed. Treat them as read, so deploying does not
            // suddenly show every user a pile of "unread" mail.
            DB::table('emails')->update(['read_at' => DB::raw('created_at')]);
        }

        // Serves the inbox/sent listing: one user, one direction, newest first.
        DB::statement('CREATE INDEX IF NOT EXISTS emails_user_direction_sent_idx ON emails (user_id, direction, sent_at DESC)');
    }

    public function down(): void
    {
        // Deliberately not dropping: by then real read/unread state would be lost.
    }
};
