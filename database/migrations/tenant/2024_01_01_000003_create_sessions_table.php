<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('refresh_token_hash');
            $table->text('user_agent')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('refresh_token_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
