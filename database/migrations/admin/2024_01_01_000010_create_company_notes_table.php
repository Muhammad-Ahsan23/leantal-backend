<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Internal notes a Super Admin keeps about a company (e.g. "asked for a refund, credited 1 month").
// company_id is a plain string: the company lives in a regional database, so no foreign key is possible.
return new class extends Migration
{
    public function getConnection()
    {
        return 'admin_db';
    }

    public function up(): void
    {
        Schema::connection('admin_db')->create('company_notes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('company_id');
            $table->string('category', 30)->default('billing');
            $table->foreignUuid('super_admin_id')->constrained('super_admins');
            $table->text('body');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['company_id', 'category', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('admin_db')->dropIfExists('company_notes');
    }
};
