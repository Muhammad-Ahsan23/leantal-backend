<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-company exception to the plan's candidate limit, set by a Super Admin. NULL = use the plan's limit, -1 = unlimited.
// Runs on every regional database (--database=pgsql_us / pgsql_eu / pgsql_uk), like the other tenant migrations.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->integer('candidate_limit_override')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('candidate_limit_override');
        });
    }
};
