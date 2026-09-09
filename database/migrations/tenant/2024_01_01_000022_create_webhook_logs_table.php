<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('source', 50); // 'creem' | 'gmail' | 'outlook' | 'google_calendar' | 'zoom'
            $table->string('event_type', 100);
            $table->jsonb('payload');
            $table->string('idempotency_key')->unique(); // retry-safe processing (Section 124)
            $table->string('status', 20)->default('received'); // 'received' | 'processed' | 'failed'
            $table->text('error_message')->nullable();
            $table->timestampTz('received_at')->useCurrent();
            $table->timestampTz('processed_at')->nullable();

            $table->index('company_id');
        });

        DB::statement("CREATE INDEX idx_webhook_logs_status ON webhook_logs(status) WHERE status = 'failed'");
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
