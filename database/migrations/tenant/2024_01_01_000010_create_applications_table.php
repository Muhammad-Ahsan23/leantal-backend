<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE application_status AS ENUM ('active', 'hired', 'rejected')");

        Schema::create('applications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->foreignUuid('job_id')->constrained('jobs')->cascadeOnDelete();
            $table->foreignUuid('stage_id')->constrained('pipeline_stages');

            $table->string('status')->default('active');
            $table->text('rejection_reason_internal')->nullable(); // never shown to candidate

            $table->timestampTz('applied_at')->useCurrent();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('hired_at')->nullable();
            $table->timestampsTz();

            $table->integer('lock_version')->default(1); // optimistic locking (Section 98)

            $table->index('company_id');
            $table->index('candidate_id');
            $table->index(['job_id', 'stage_id']);
        });

        // CRITICAL: one candidate cannot have two ACTIVE applications for the same job (Section 33, 135)
        DB::statement("
            CREATE UNIQUE INDEX idx_one_active_application_per_job
            ON applications(candidate_id, job_id) WHERE status = 'active'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
        DB::statement('DROP TYPE IF EXISTS application_status');
    }
};
