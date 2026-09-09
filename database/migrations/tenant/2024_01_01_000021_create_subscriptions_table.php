<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete()->unique();

            $table->string('plan');
            $table->string('status');

            $table->string('creem_customer_id')->nullable();
            $table->string('creem_subscription_id')->nullable();

            $table->timestampTz('current_period_start')->nullable();
            $table->timestampTz('current_period_end')->nullable();
            $table->timestampTz('cancelled_at')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
