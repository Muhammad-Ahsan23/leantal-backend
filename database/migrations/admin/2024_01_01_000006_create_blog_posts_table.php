<?php
// PRD Section 84/121 — marketing blog posts.
// CONFIRMED: this table already existed in the original foundational
// schema — cover_image_disk + cover_image_path (R2-storage pattern,
// same shape as Candidate resumes), not a plain URL column. Guarded
// with hasTable() so this is a no-op wherever it already exists.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function getConnection()
    {
        return 'admin_db';
    }

    public function up(): void
    {
        if (Schema::connection('admin_db')->hasTable('blog_posts')) {
            return;
        }

        Schema::connection('admin_db')->create('blog_posts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content')->nullable();
            $table->string('status')->default('draft');
            $table->foreignUuid('author_id')->constrained('super_admins');
            $table->string('cover_image_disk')->nullable();
            $table->text('cover_image_path')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        // Deliberately not dropping — pre-dates this migration.
    }
};
