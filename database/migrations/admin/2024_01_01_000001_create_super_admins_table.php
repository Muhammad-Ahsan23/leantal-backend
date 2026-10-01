<?php
// PRD Section 145 — "Super Admin accounts require dedicated
// authentication, mandatory MFA, aggressive session timeouts, and
// comprehensive audit logging." Lives in admin_db — completely
// separate from the tenant 'users' table (PRD Section 4: "The Super
// Admin is not a customer role").

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
        Schema::connection('admin_db')->create('super_admins', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password_hash');
            // Null until the admin completes MFA enrollment — PRD says
            // MFA is mandatory, so login is blocked until this is set
            // (see SuperAdminAuthController).
            $table->text('mfa_secret')->nullable();
            $table->timestampTz('mfa_enabled_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::connection('admin_db')->dropIfExists('super_admins');
    }
};
