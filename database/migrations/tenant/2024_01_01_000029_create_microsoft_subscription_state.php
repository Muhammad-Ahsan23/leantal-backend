<?php
// PRD Section 54/60/131 — Outlook/Microsoft 365 email + calendar
// integration. Parallel to gmail_watch_state, but Microsoft Graph's
// subscription model is simpler (no separate history_id — each
// notification carries the changed resource's own ID directly) and
// expires much sooner (~3 days vs Gmail's 7), so renewal needs to run
// more frequently.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('microsoft_subscription_state', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('subscription_id'); // Graph's own subscription ID, needed to renew/delete it
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('microsoft_subscription_state');
    }
};
