<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE invitation_status AS ENUM ('pending', 'accepted', 'expired', 'revoked')");

        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('email');
            $table->string('role'); // 'hiring_manager' | 'recruiter' only — Owner cannot invite Owner
            $table->foreignUuid('invited_by')->constrained('users');
            $table->string('token')->unique();
            $table->string('status')->default('pending');
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('company_id');
            $table->index('token');
        });

        DB::statement("ALTER TABLE invitations ADD CONSTRAINT chk_invitation_role CHECK (role IN ('hiring_manager', 'recruiter'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        DB::statement('DROP TYPE IF EXISTS invitation_status');
    }
};
