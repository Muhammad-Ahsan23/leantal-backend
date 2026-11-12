<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Why a session was ended. 'replaced' = the same account logged in on another device (one device at a time).
// Runs on every regional database (--database=pgsql_us / pgsql_eu / pgsql_uk). Safe to run after the SQL script.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('sessions', 'revoked_reason')) {
            Schema::table('sessions', function (Blueprint $table) {
                $table->string('revoked_reason', 30)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropColumn('revoked_reason');
        });
    }
};
