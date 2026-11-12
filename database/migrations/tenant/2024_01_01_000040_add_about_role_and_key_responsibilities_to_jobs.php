<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Three optional job content fields: "About the Role", "Key Responsibilities" and "Requirements".
// Runs on every regional database (--database=pgsql_us / pgsql_eu / pgsql_uk), like the other tenant migrations.
// Safe to run even if the columns were already added by hand with the SQL script (it checks first).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('jobs', 'about_role')) {
                $table->text('about_role')->nullable();
            }
            if (!Schema::hasColumn('jobs', 'key_responsibilities')) {
                $table->text('key_responsibilities')->nullable();
            }
            if (!Schema::hasColumn('jobs', 'requirements')) {
                $table->text('requirements')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn(['about_role', 'key_responsibilities', 'requirements']);
        });
    }
};
