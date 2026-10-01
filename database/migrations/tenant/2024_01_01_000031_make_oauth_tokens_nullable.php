<?php
// PRD Section 131 — "Disconnecting Gmail/Outlook... deletes stored
// access/refresh tokens." CONFIRMED via a real constraint violation
// (2026-09-30): access_token/refresh_token were created NOT NULL in
// the original schema — nulling them on disconnect is literally
// impossible until this constraint is relaxed. scope is included too
// since it's cleared alongside them and may share the same constraint.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_tokens', function (Blueprint $table) {
            $table->text('access_token')->nullable()->change();
            $table->text('refresh_token')->nullable()->change();
            $table->string('scope')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately NOT reverting to NOT NULL — by the time this
        // migration has run, disconnected rows may legitimately have
        // NULL tokens; rolling back would break those existing rows.
    }
};
