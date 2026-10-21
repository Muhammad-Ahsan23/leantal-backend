<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files attached to a task (shown on the task detail page, and addable while creating a task).
 * The file itself lives on the company's regional storage disk (same rule as resumes); this table
 * only holds the pointer plus the display name, type and size.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('task_id');
            $table->uuid('uploaded_by')->nullable();
            $table->string('original_name', 255);
            $table->string('disk', 50);
            $table->string('path', 500);
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->index('task_id');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_attachments');
    }
};
