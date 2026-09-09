<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

// Laravel's stock password_reset_tokens migration was deleted along with
// the default users migration (naming collision) — this replaces it with
// a UUID-consistent version tied to user_id instead of a bare email
// string, matching the rest of our schema.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash');       // never store the raw token
            $table->timestampTz('expires_at');  // 60-minute expiry (industry standard; PRD doesn't specify a number)
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
