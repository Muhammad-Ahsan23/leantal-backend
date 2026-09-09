<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE oauth_provider AS ENUM ('gmail', 'outlook', 'google_calendar', 'outlook_calendar', 'zoom')");

        Schema::create('oauth_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider');
            $table->text('access_token');   // encrypted at rest via Laravel's encrypted cast
            $table->text('refresh_token')->nullable();
            $table->text('scope')->nullable();
            $table->timestampTz('connected_at')->useCurrent();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('disconnected_at')->nullable();

            $table->unique(['user_id', 'provider']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_tokens');
        DB::statement('DROP TYPE IF EXISTS oauth_provider');
    }
};
