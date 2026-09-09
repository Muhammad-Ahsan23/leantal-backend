<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE interview_provider AS ENUM ('google_meet', 'microsoft_teams', 'zoom')");

        Schema::create('interviews', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('candidate_id')->constrained('candidates');
            $table->foreignUuid('job_id')->constrained('jobs');
            $table->foreignUuid('organizer_id')->constrained('users');

            $table->string('interview_type')->nullable();
            $table->string('provider');
            $table->string('calendar_event_id')->nullable();
            $table->string('meeting_url', 500)->nullable();

            $table->timestampTz('start_time');
            $table->timestampTz('end_time');
            $table->timestampsTz();

            $table->index('company_id');
            $table->index(['organizer_id', 'start_time']);
            $table->index('candidate_id');
            $table->index('job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interviews');
        DB::statement('DROP TYPE IF EXISTS interview_provider');
    }
};
