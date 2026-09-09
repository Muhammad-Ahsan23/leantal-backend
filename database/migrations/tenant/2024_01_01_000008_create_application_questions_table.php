<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE question_type AS ENUM ('short_text', 'long_text', 'yes_no', 'multiple_choice', 'single_choice', 'number', 'date', 'file_upload')");
        DB::statement("CREATE TYPE knockout_action AS ENUM ('reject', 'flag_only')");

        Schema::create('application_questions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('job_id')->constrained('jobs')->cascadeOnDelete();
            $table->text('question');
            $table->string('type');
            $table->boolean('required')->default(false);
            $table->integer('order')->default(0);

            $table->boolean('knockout')->default(false);
            $table->string('knockout_action')->nullable(); // must be set if knockout=true (app-layer validated)
            $table->text('knockout_expected_answer')->nullable();

            $table->jsonb('options')->nullable(); // for multiple_choice / single_choice

            $table->timestampTz('created_at')->useCurrent();

            $table->index(['job_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_questions');
        DB::statement('DROP TYPE IF EXISTS question_type');
        DB::statement('DROP TYPE IF EXISTS knockout_action');
    }
};
