<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_answers', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignUuid('question_id')->constrained('application_questions');

            $table->text('answer_text')->nullable();
            $table->string('answer_file_disk', 20)->nullable();  // for file_upload-type answers
            $table->text('answer_file_path')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->index('application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_answers');
    }
};
