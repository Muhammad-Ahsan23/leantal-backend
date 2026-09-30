<?php
// PRD Section 57 — "Receive and display replies... maintain
// conversation context." Two additions needed:
// 1. oauth_tokens.provider_email — the CONNECTED Gmail address itself.
//    Gmail's Pub/Sub push notifications identify the mailbox by email
//    address, not by our internal user_id — we need this to match an
//    incoming notification back to the right user.
// 2. gmail_watch_state — tracks each connection's Gmail "watch"
//    registration (historyId to resume syncing from, and when the
//    watch itself expires and needs renewal — Gmail watches expire
//    after 7 days).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_tokens', function (Blueprint $table) {
            $table->string('provider_email')->nullable()->after('provider');
        });

        Schema::create('gmail_watch_state', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('history_id')->nullable();
            $table->timestampTz('watch_expires_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_watch_state');
        Schema::table('oauth_tokens', function (Blueprint $table) {
            $table->dropColumn('provider_email');
        });
    }
};
