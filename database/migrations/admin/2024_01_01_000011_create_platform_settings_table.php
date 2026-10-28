<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Platform-wide switches a Super Admin controls: the maintenance mode and the customer announcement banner.
// One row per setting (key => JSON value), so a new setting never needs a new table or column.
return new class extends Migration
{
    public function getConnection()
    {
        return 'admin_db';
    }

    public function up(): void
    {
        Schema::connection('admin_db')->create('platform_settings', function (Blueprint $table) {
            $table->string('key', 50)->primary();
            $table->jsonb('value');
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::connection('admin_db')->dropIfExists('platform_settings');
    }
};
