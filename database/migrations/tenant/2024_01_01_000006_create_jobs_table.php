<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE job_status AS ENUM ('draft', 'published', 'paused', 'closed', 'archived')");
        DB::statement("CREATE TYPE employment_type AS ENUM ('full_time', 'part_time', 'contract', 'temporary', 'internship')");
        DB::statement("CREATE TYPE location_type AS ENUM ('remote', 'hybrid', 'onsite')");
        DB::statement("CREATE TYPE compensation_type AS ENUM ('salary_range', 'hourly_rate', 'annual_compensation', 'free_text')");

        Schema::create('jobs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('title');
            $table->string('department');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('location_type')->nullable();
            $table->string('employment_type')->nullable();

            $table->boolean('compensation_enabled')->default(false);
            $table->string('compensation_type')->nullable();
            $table->text('compensation_value')->nullable();

            $table->text('about_company')->nullable();
            $table->text('benefits')->nullable();
            $table->text('team_info')->nullable();
            $table->jsonb('additional_sections')->nullable();

            $table->string('status')->default('draft');
            $table->foreignUuid('assigned_user_id')->nullable()->constrained('users');
            $table->foreignUuid('created_by')->constrained('users');

            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('assigned_user_id');
        });

        DB::statement("CREATE INDEX idx_jobs_status ON jobs(company_id, status)");
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
        DB::statement('DROP TYPE IF EXISTS job_status');
        DB::statement('DROP TYPE IF EXISTS employment_type');
        DB::statement('DROP TYPE IF EXISTS location_type');
        DB::statement('DROP TYPE IF EXISTS compensation_type');
    }
};
