<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('job_id')->constrained('jobs')->cascadeOnDelete();
            $table->string('name', 100);
            $table->integer('order');
            $table->boolean('protected')->default(false); // Applied/Hired/Rejected — cannot delete
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['job_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_stages');
    }
};
