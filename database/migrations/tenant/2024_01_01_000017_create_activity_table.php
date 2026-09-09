<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users'); // null = system event

            $table->string('action', 100);      // e.g. 'job.published', 'candidate.moved_stage'
            $table->string('object_type', 50);  // 'job' | 'candidate' | 'application' | 'task' | etc.
            $table->uuid('object_id')->nullable(); // polymorphic — no FK, object may be in different tables

            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("CREATE INDEX idx_activity_company_id ON activity(company_id, created_at DESC)");
        DB::statement("CREATE INDEX idx_activity_actor ON activity(actor_id, created_at DESC)");
        DB::statement("CREATE INDEX idx_activity_object ON activity(object_type, object_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('activity');
    }
};
