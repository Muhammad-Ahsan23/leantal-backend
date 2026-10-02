<?php
// PRD Section 82 — "View failed email dispatches, calendar sync
// errors, webhook failures... with safe retry triggers. No arbitrary
// SQL execution in UI." Lives in admin_db (cross-tenant, platform-
// wide visibility) rather than any single region's tenant DB —
// Super Admin needs to see failures from ALL regions in one place.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function getConnection()
    {
        return 'admin_db';
    }

    public function up(): void
    {
        Schema::connection('admin_db')->create('system_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            // category: 'email' | 'calendar_sync' | 'webhook' | 'indexing'
            $table->string('category');
            $table->string('region')->nullable(); // which regional DB the failure happened in
            $table->uuid('company_id')->nullable();
            $table->text('message');
            // Enough context to safely RE-RUN the same operation on
            // retry (e.g. interview_id for calendar_sync, or the email
            // send parameters for email) — shape varies by category,
            // hence jsonb rather than fixed columns.
            $table->jsonb('context')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['category', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('admin_db')->dropIfExists('system_events');
    }
};
