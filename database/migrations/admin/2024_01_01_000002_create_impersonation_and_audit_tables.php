<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'admin_db';

    public function up(): void
    {
        Schema::connection('admin_db')->create('impersonation_logs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('super_admin_id')->constrained('super_admins');
            $table->uuid('target_user_id');       // in a REGIONAL db — no cross-db FK possible
            $table->string('target_region');       // 'us' | 'eu' | 'uk' — tells app which DB to check
            $table->uuid('target_company_id');

            $table->text('reason');
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('ended_at')->nullable();
        });

        DB::statement("CREATE INDEX idx_impersonation_logs_admin ON impersonation_logs(super_admin_id, started_at DESC)");

        Schema::connection('admin_db')->create('admin_audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('super_admin_id')->constrained('super_admins');
            $table->string('action', 100); // 'login' | 'company.suspend' | 'flag.toggle' | etc.
            $table->string('target_type', 50)->nullable();
            $table->uuid('target_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::connection('admin_db')->dropIfExists('admin_audit_log');
        Schema::connection('admin_db')->dropIfExists('impersonation_logs');
    }
};
