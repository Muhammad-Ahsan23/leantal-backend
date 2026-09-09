<?php
// database/migrations/2024_01_01_000002_create_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE user_role AS ENUM ('owner', 'hiring_manager', 'recruiter')");
        DB::statement("CREATE TYPE user_status AS ENUM ('active', 'invited', 'removed', 'suspended')");

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->string('email');
            $table->string('password_hash');
            $table->string('role');   // 'owner' | 'hiring_manager' | 'recruiter'
            $table->string('status')->default('active');

            $table->string('mfa_secret')->nullable();
            $table->boolean('mfa_enabled')->default(false);

            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('removed_at')->nullable();

            $table->timestampsTz();

            $table->index('company_id');
        });

        // Partial unique index — sirf active/invited users ke liye email unique honi chahiye
        // (removed user ko dobara same email se invite kiya ja sake, Section 70)
        DB::statement("
            CREATE UNIQUE INDEX idx_users_email_active
            ON users(email) WHERE status != 'removed'
        ");

        // Rule 6: Exactly one Owner per company
        DB::statement("
            CREATE UNIQUE INDEX idx_one_owner_per_company
            ON users(company_id) WHERE role = 'owner' AND status = 'active'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        DB::statement('DROP TYPE IF EXISTS user_role');
        DB::statement('DROP TYPE IF EXISTS user_status');
    }
};
