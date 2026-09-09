<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'admin_db';

    public function up(): void
    {
        DB::statement("CREATE TYPE flag_scope AS ENUM ('global', 'company')");
        DB::statement("CREATE TYPE blog_status AS ENUM ('draft', 'published', 'archived')");

        Schema::connection('admin_db')->create('feature_flags', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('key', 100)->unique();
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('scope')->default('global');
            $table->jsonb('company_ids')->nullable(); // cross-region, no FK — stored as array
            $table->timestampsTz();
        });

        Schema::connection('admin_db')->create('blog_posts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('content');
            $table->string('status')->default('draft');
            $table->foreignUuid('author_id')->constrained('super_admins');
            $table->string('cover_image_disk', 20)->nullable();
            $table->text('cover_image_path')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
        });

        Schema::connection('admin_db')->create('platform_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('super_admin_id')->constrained('super_admins');
            $table->text('message');
            $table->string('target_scope', 20); // 'all' | 'selected_companies' | 'selected_users'
            $table->jsonb('target_company_ids')->nullable();
            $table->jsonb('target_user_ids')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::connection('admin_db')->dropIfExists('platform_notifications');
        Schema::connection('admin_db')->dropIfExists('blog_posts');
        Schema::connection('admin_db')->dropIfExists('feature_flags');
        DB::statement('DROP TYPE IF EXISTS flag_scope');
        DB::statement('DROP TYPE IF EXISTS blog_status');
    }
};
