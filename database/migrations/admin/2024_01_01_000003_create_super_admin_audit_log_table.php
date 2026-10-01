<?php
// PRD Section 159 — "Log all Super Admin actions: logins, logouts,
// user impersonations, tenant suspensions, trial extensions,
// notifications, and feature flag changes." target_id is a plain
// string (not a foreign key) because the target (a company/user) lives
// in one of 3 SEPARATE regional databases, not admin_db — a real FK
// constraint across databases isn't possible in Postgres.

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
        Schema::connection('admin_db')->create('super_admin_audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('super_admin_id')->constrained('super_admins')->cascadeOnDelete();
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['super_admin_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('admin_db')->dropIfExists('super_admin_audit_log');
    }
};
