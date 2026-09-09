<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE email_direction AS ENUM ('outbound', 'inbound')");
        DB::statement("CREATE TYPE email_provider AS ENUM ('gmail', 'outlook', 'system')");

        Schema::create('emails', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users'); // null = system rejection email
            $table->foreignUuid('candidate_id')->constrained('candidates');

            $table->string('direction');
            $table->string('provider');
            $table->string('thread_id')->nullable();

            $table->string('subject')->nullable();
            $table->text('body')->nullable();

            $table->timestampTz('sent_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('company_id');
            $table->index('candidate_id');
            $table->index('user_id');
        });

        DB::statement("CREATE INDEX idx_emails_search ON emails USING gin (to_tsvector('english', coalesce(subject,'') || ' ' || coalesce(body,'')))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emails');
        DB::statement('DROP TYPE IF EXISTS email_direction');
        DB::statement('DROP TYPE IF EXISTS email_provider');
    }
};
