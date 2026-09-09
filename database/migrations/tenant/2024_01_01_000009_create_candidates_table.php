<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE candidate_status AS ENUM ('active', 'archived')");

        Schema::create('candidates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->string('email');
            $table->string('normalized_email'); // trimmed + lowercased, for dedup (Section 133)
            $table->string('phone', 50)->nullable();
            $table->string('location')->nullable();
            $table->string('current_title')->nullable();
            $table->string('current_company')->nullable();
            $table->string('linkedin_url')->nullable();

            // Resume file — disk-aware for our r2_us / r2_eu split
            $table->string('resume_disk', 20)->nullable();
            $table->text('resume_path')->nullable();
            $table->string('resume_original_name')->nullable();

            $table->foreignUuid('assigned_user_id')->nullable()->constrained('users');
            $table->string('status')->default('active');

            $table->timestampsTz();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();

            $table->index('company_id');
            $table->index('assigned_user_id');
        });

        DB::statement("CREATE INDEX idx_candidates_normalized_email ON candidates(company_id, normalized_email)");
        DB::statement("CREATE INDEX idx_candidates_search ON candidates USING gin (to_tsvector('english', name || ' ' || email || ' ' || coalesce(phone, '')))");
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');
        DB::statement('DROP TYPE IF EXISTS candidate_status');
    }
};
