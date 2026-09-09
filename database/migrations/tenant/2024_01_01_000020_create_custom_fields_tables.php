<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE custom_field_entity AS ENUM ('job', 'candidate')");
        DB::statement("CREATE TYPE custom_field_type AS ENUM ('text', 'number', 'date', 'dropdown', 'boolean')");

        Schema::create('custom_fields', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('entity_type');
            $table->string('field_name');
            $table->string('field_type');
            $table->jsonb('options')->nullable(); // for 'dropdown' type
            $table->boolean('required')->default(false);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['company_id', 'entity_type']);
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('custom_field_id')->constrained('custom_fields')->cascadeOnDelete();
            $table->uuid('entity_id'); // polymorphic — job.id or candidate.id
            $table->text('value')->nullable();

            $table->unique(['custom_field_id', 'entity_id']);
            $table->index('entity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_fields');
        DB::statement('DROP TYPE IF EXISTS custom_field_entity');
        DB::statement('DROP TYPE IF EXISTS custom_field_type');
    }
};
