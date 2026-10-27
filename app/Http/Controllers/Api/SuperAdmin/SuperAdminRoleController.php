<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\SaveAdminRoleRequest;
use App\Models\AdminRole;
use App\Models\AdminRolePermission;
use App\Services\SuperAdminAuditService;
use App\Support\AdminPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Owner-only (EnsureAdminIsOwner). The Owner role is system-defined and can be neither edited nor deleted. */
class SuperAdminRoleController extends Controller
{
    public function __construct(protected SuperAdminAuditService $audit) {}

    public function index()
    {
        $roles = AdminRole::with('permissions')->withCount('admins')->orderByDesc('is_owner')->orderBy('name')->get()
            ->map(fn (AdminRole $role) => $this->present($role));

        $modules = collect(AdminPermissions::modules())->map(fn (array $module, string $key) => [
            'key' => $key,
            'label' => $module['label'],
            'actions' => collect($module['actions'])->map(fn (string $label, string $action) => ['key' => $action, 'label' => $label])->values(),
        ])->values();

        return response()->json(['roles' => $roles, 'modules' => $modules]);
    }

    public function store(SaveAdminRoleRequest $request)
    {
        $data = $request->validated();
        $permissions = AdminPermissions::normalize($data['permissions']);

        $role = DB::connection('admin_db')->transaction(function () use ($data, $permissions) {
            $role = AdminRole::create(['name' => $data['name'], 'description' => $data['description'] ?? null]);
            $this->savePermissions($role, $permissions);

            return $role;
        });

        $this->audit->log($request->attributes->get('super_admin'), 'admin_role_created', $request->ip(), 'admin_role', $role->id, [
            'name' => $role->name, 'permissions' => $permissions,
        ]);

        return response()->json(['role' => $this->present($role->load('permissions')->loadCount('admins'))], 201);
    }

    public function update(SaveAdminRoleRequest $request, string $id)
    {
        $role = AdminRole::with('permissions')->findOrFail($id);
        abort_if($role->is_owner, 422, 'The Owner role cannot be edited.');

        $data = $request->validated();
        $before = $role->permissions->pluck('permission')->sort()->values()->all();
        $after = AdminPermissions::normalize($data['permissions']);

        DB::connection('admin_db')->transaction(function () use ($role, $data, $after) {
            $role->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);
            $role->permissions()->delete();
            $this->savePermissions($role, $after);
        });

        $this->audit->log($request->attributes->get('super_admin'), 'admin_role_updated', $request->ip(), 'admin_role', $role->id, [
            'name' => $role->name, 'added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after)),
        ]);

        return response()->json(['role' => $this->present($role->load('permissions')->loadCount('admins'))]);
    }

    public function destroy(Request $request, string $id)
    {
        $role = AdminRole::withCount('admins')->findOrFail($id);
        abort_if($role->is_owner, 422, 'The Owner role cannot be deleted.');
        abort_if($role->admins_count > 0, 422, "{$role->admins_count} admin(s) still use this role. Move them to another role first.");

        $role->delete();

        $this->audit->log($request->attributes->get('super_admin'), 'admin_role_deleted', $request->ip(), 'admin_role', $id, ['name' => $role->name]);

        return response()->json(['message' => 'Role deleted.']);
    }

    /** @param string[] $permissions */
    protected function savePermissions(AdminRole $role, array $permissions): void
    {
        AdminRolePermission::insert(array_map(fn (string $permission) => ['role_id' => $role->id, 'permission' => $permission], $permissions));
    }

    protected function present(AdminRole $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'is_owner' => $role->is_owner,
            'permissions' => $role->is_owner ? AdminPermissions::all() : $role->permissions->pluck('permission')->sort()->values(),
            'admins_count' => $role->admins_count,
        ];
    }
}
