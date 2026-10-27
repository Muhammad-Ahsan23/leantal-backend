<?php
// Super Admin roles & permissions. Adds roles, their permissions and two columns on super_admins, then creates the
// Owner role (holds every permission) and moves every existing admin onto it so nobody is locked out.
// Three editable starter roles are added; the Owner can change or delete them.

use App\Support\AdminPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function getConnection()
    {
        return 'admin_db';
    }

    public function up(): void
    {
        $schema = Schema::connection('admin_db');

        $schema->create('admin_roles', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_owner')->default(false);
            $table->timestampsTz();
        });

        $schema->create('admin_role_permissions', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained('admin_roles')->cascadeOnDelete();
            $table->string('permission', 100);
            $table->primary(['role_id', 'permission']);
        });

        $schema->table('super_admins', function (Blueprint $table) {
            $table->foreignUuid('role_id')->nullable()->constrained('admin_roles')->restrictOnDelete();
            $table->timestampTz('disabled_at')->nullable();
        });

        $db = DB::connection('admin_db');
        $now = now();

        $ownerId = (string) Str::uuid();
        $db->table('admin_roles')->insert([
            'id' => $ownerId, 'name' => 'Owner', 'is_owner' => true, 'created_at' => $now, 'updated_at' => $now,
            'description' => 'Full access to everything, including managing admins and roles. Cannot be edited or deleted.',
        ]);
        $db->table('super_admins')->update(['role_id' => $ownerId]);

        $starters = [
            'Support' => ['Handles customer questions: looks things up, extends trials, retries failed events.', [
                'dashboard.view', 'companies.view', 'companies.extend_trial', 'search.view',
                'debugging.view', 'debugging.retry', 'system_health.view',
            ]],
            'Content Editor' => ['Manages the marketing blog.', [
                'blog.view', 'blog.create', 'blog.edit', 'blog.delete',
            ]],
            'Read-only' => ['Can look at everything but change nothing.', array_values(array_filter(
                AdminPermissions::all(), fn (string $p) => str_ends_with($p, '.view')
            ))],
        ];

        foreach ($starters as $name => [$description, $permissions]) {
            $id = (string) Str::uuid();
            $db->table('admin_roles')->insert([
                'id' => $id, 'name' => $name, 'description' => $description, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('admin_role_permissions')->insert(
                array_map(fn (string $permission) => ['role_id' => $id, 'permission' => $permission], $permissions)
            );
        }
    }

    public function down(): void
    {
        Schema::connection('admin_db')->table('super_admins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn('disabled_at');
        });
        Schema::connection('admin_db')->dropIfExists('admin_role_permissions');
        Schema::connection('admin_db')->dropIfExists('admin_roles');
    }
};
