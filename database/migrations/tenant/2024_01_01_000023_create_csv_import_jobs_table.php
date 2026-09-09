<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE import_job_status AS ENUM ('pending', 'mapping', 'previewing', 'processing', 'completed', 'completed_with_errors', 'failed')");

        Schema::create('csv_import_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('initiated_by')->constrained('users');

            $table->string('original_filename');
            $table->string('file_disk', 20)->nullable();
            $table->text('file_path')->nullable();

            $table->jsonb('column_mapping')->nullable();
            $table->string('status')->default('pending');

            $table->integer('total_rows')->nullable();
            $table->integer('successful_rows')->default(0);
            $table->integer('failed_rows')->default(0);
            $table->string('error_report_disk', 20)->nullable();
            $table->text('error_report_path')->nullable();

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();

            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_import_jobs');
        DB::statement('DROP TYPE IF EXISTS import_job_status');
    }
};
