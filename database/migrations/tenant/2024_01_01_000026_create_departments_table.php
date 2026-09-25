<?php
// PRD Section 68 — "Careers Page (Owner only): Slug, company URL,
// departments." + Section 46 — "Department (custom, created by
// company)." Companies maintain their own named list of departments,
// used to populate the dropdown when creating a Job — Jobs.department
// stays a plain string column (unchanged) so this is purely additive,
// nothing about existing Job data/behavior changes.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name');
            $table->timestampsTz();

            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
