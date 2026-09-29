<?php
// PRD Section 125 — "Webhook endpoints must be authenticated, idempotent,
// logged, and retry-safe." Lives in routing_db (not a regional tenant
// DB) because idempotency must be checked BEFORE we even know which
// region the company belongs to — webhook events are billing
// infrastructure data, not per-tenant data.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('routing_db')->create('billing_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('event_id')->unique();
            $table->string('event_type');
            $table->jsonb('payload');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::connection('routing_db')->dropIfExists('billing_webhook_events');
    }
};
