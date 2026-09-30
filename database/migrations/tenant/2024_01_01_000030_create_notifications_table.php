<?php
// PRD Section 106 — "Notification: id, company_id, user_id, type,
// message, read_at." PRD Section 20 — notification bell, only
// important events (candidate/job/task assignment, interview
// scheduled/changed, security events, Owner broadcasts).
//
// CONFIRMED: this table already exists in the US region (created via
// the original foundational raw-SQL schema, 01_tenant_schema.sql —
// includes a 'link' column beyond PRD's abbreviated schema list, for
// deep-linking to the relevant Job/Candidate/Task/Interview). Guarded
// with hasTable() so this migration is safe to run on ANY region —
// a no-op where the table already exists, creates it fresh (link
// included, matching the established shape) where it doesn't.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type');
            $table->text('message');
            $table->string('link')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        // Deliberately NOT dropping — this table pre-dates this
        // migration in at least one region (US), dropping it here
        // could destroy data that existed before this migration file
        // was ever written.
    }
};
