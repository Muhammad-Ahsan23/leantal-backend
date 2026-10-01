<?php
// Deliberately separate from Sanctum's personal_access_tokens (which
// is tenant/customer-focused) — PRD Section 145's "dedicated
// authentication" means Super Admin auth is its own isolated system,
// not an extension of the customer login flow.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function getConnection()
    {
        return 'admin_db';
    }

    public function up(): void
    {
        Schema::connection('admin_db')->create('super_admin_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('super_admin_id')->constrained('super_admins')->cascadeOnDelete();
            // SHA-256 hash of the raw token — same pattern as Sanctum
            // itself: the raw token is shown once, only the hash is
            // ever stored.
            $table->string('token_hash')->unique();
            // PRD Section 145 — "aggressive session timeouts." Short
            // (30-min) expiry, no refresh-token rotation for V1 —
            // re-authenticating (incl. MFA) often is the point here,
            // not a convenience to engineer around.
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::connection('admin_db')->dropIfExists('super_admin_tokens');
    }
};
