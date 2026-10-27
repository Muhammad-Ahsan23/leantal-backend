<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Step-up authentication: until this moment the session counts as "freshly verified with an authenticator code".
return new class extends Migration
{
    public function getConnection()
    {
        return 'admin_db';
    }

    public function up(): void
    {
        Schema::connection('admin_db')->table('super_admin_tokens', function (Blueprint $table) {
            $table->timestampTz('step_up_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('admin_db')->table('super_admin_tokens', function (Blueprint $table) {
            $table->dropColumn('step_up_until');
        });
    }
};
