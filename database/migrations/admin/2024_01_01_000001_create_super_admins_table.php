<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'admin_db';

    public function up(): void
    {
        DB::statement("CREATE TYPE admin_status AS ENUM ('active', 'suspended')");
        DB::statement("CREATE TYPE tenant_region AS ENUM ('us', 'eu', 'uk')");

        Schema::connection('admin_db')->create('super_admins', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('mfa_secret'); // mandatory, no optional MFA
            $table->string('status')->default('active');
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::connection('admin_db')->create('super_admin_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('super_admin_id')->constrained('super_admins')->cascadeOnDelete();
            $table->string('refresh_token_hash');
            $table->timestampTz('expires_at'); // shorter than customer 30-day sessions
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::connection('admin_db')->dropIfExists('super_admin_sessions');
        Schema::connection('admin_db')->dropIfExists('super_admins');
        DB::statement('DROP TYPE IF EXISTS admin_status');
        DB::statement('DROP TYPE IF EXISTS tenant_region');
    }
};
