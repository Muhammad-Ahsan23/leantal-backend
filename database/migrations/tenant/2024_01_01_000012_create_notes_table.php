<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->foreignUuid('author_id')->constrained('users');
            $table->text('body');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('candidate_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
