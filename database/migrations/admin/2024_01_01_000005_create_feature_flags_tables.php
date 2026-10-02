<?php
// PRD Section 83 — "enable/disable specific features globally or for
// targeted company IDs."
//
// CONFIRMED: this table already existed in the original foundational
// schema with its OWN exact design — 'enabled' + 'scope' + 'company_ids'
// (jsonb array, stored DIRECTLY on this row) — simpler than a separate
// pivot table, which this migration no longer creates. Guarded with
// hasTable() so it's a no-op wherever the table already exists.

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
        if (Schema::connection('admin_db')->hasTable('feature_flags')) {
            return;
        }

        Schema::connection('admin_db')->create('feature_flags', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('key')->unique();
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('scope')->default('global'); // 'global' | 'company'
            $table->jsonb('company_ids')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        // Deliberately not dropping — this table pre-dates this
        // migration in the original schema.
    }
};
