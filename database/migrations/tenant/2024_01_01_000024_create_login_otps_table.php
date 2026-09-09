<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

// New table discovered as a schema gap while implementing Login (PRD
// Section 15). users.mfa_secret was designed for a persistent secret;
// the PRD's OTP is a fresh, short-lived, per-login code — it needs its
// own table with expiry + attempt tracking, not a column on users.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_otps', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('otp_hash');           // never store the raw code
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestampTz('expires_at');    // Section 15: "OTP expires quickly, e.g. 10 minutes"
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_otps');
    }
};
