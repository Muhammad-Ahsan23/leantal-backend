<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users'); // creator — all roles can create
            $table->string('name');
            $table->string('subject');
            $table->text('body'); // contains {{variable}} placeholders
            $table->timestampsTz();

            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
