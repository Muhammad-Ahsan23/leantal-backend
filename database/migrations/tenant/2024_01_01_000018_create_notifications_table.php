<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users');

            $table->string('type', 100);
            $table->text('message');
            $table->string('link', 500)->nullable();

            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("CREATE INDEX idx_notifications_user_id ON notifications(user_id, read_at, created_at DESC)");
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
