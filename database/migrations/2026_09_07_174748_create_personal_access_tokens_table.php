<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lives in routing_db — see earlier comment for why (auth:sanctum has no
// way to know a token's region before verifying it). The 'region' column
// is what lets us correctly resolve $request->user() from the RIGHT
// regional database afterward — see PersonalAccessToken::tokenable().
return new class extends Migration
{
    protected $connection = 'routing_db';

    public function up(): void
    {
        Schema::connection('routing_db')->create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->string('region', 5)->nullable(); // 'us' | 'eu' | 'uk'
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('routing_db')->dropIfExists('personal_access_tokens');
    }
};
