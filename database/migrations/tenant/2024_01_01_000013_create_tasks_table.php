<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE task_type AS ENUM ('normal', 'assign_candidate', 'assign_job')");
        DB::statement("CREATE TYPE task_status AS ENUM ('to_do', 'in_progress', 'done', 'cancelled')");

        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('type');
            $table->string('title');
            $table->foreignUuid('assigned_user_id')->constrained('users');
            $table->foreignUuid('created_by')->constrained('users'); // Owner/HM only

            $table->foreignUuid('candidate_id')->nullable()->constrained('candidates');
            $table->foreignUuid('job_id')->nullable()->constrained('jobs');

            $table->date('due_date')->nullable();
            $table->string('status')->default('to_do');
            $table->text('notes')->nullable();
            $table->boolean('auto_generated')->default(false);

            $table->timestampsTz();

            $table->index('company_id');
            $table->index(['assigned_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
        DB::statement('DROP TYPE IF EXISTS task_type');
        DB::statement('DROP TYPE IF EXISTS task_status');
    }
};
