<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\CreateAdminRequest;
use App\Http\Requests\SuperAdmin\UpdateAdminRequest;
use App\Models\SuperAdmin;
use App\Services\SuperAdminAuditService;
use App\Services\SuperAdminAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Owner-only (EnsureAdminIsOwner). An admin can never change their own role or status, which also guarantees the
 * last Owner cannot be demoted or disabled: only an Owner may call these routes, and never on themselves.
 */
class SuperAdminAdminController extends Controller
{
    public function __construct(
        protected SuperAdminAuditService $audit,
        protected SuperAdminAuthService $auth,
    ) {}

    public function index()
    {
        $admins = SuperAdmin::with('role:id,name,is_owner')->orderBy('name')->get()
            ->map(fn (SuperAdmin $admin) => $this->present($admin));

        return response()->json(['admins' => $admins]);
    }

    public function store(CreateAdminRequest $request)
    {
        $data = $request->validated();

        $admin = SuperAdmin::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'role_id' => $data['role_id'],
        ])->load('role:id,name,is_owner');

        $this->audit->log($this->actor($request), 'admin_created', $request->ip(), 'super_admin', $admin->id, [
            'email' => $admin->email, 'role' => $admin->role->name,
        ]);

        return response()->json(['admin' => $this->present($admin)], 201);
    }

    public function update(UpdateAdminRequest $request, string $id)
    {
        $admin = $this->target($request, $id);
        $data = $request->validated();
        $ip = $request->ip();

        if (isset($data['role_id']) && $data['role_id'] !== $admin->role_id) {
            $from = $admin->role?->name;
            $admin->update(['role_id' => $data['role_id']]);
            $admin->load('role:id,name,is_owner');
            $this->audit->log($this->actor($request), 'admin_role_changed', $ip, 'super_admin', $admin->id, [
                'email' => $admin->email, 'from' => $from, 'to' => $admin->role->name,
            ]);
        }

        if (isset($data['disabled']) && $data['disabled'] !== (bool) $admin->disabled_at) {
            $admin->update(['disabled_at' => $data['disabled'] ? now() : null]);
            if ($data['disabled']) {
                $this->auth->revokeAllTokens($admin); // signed out immediately
            }
            $this->audit->log($this->actor($request), $data['disabled'] ? 'admin_disabled' : 'admin_enabled', $ip, 'super_admin', $admin->id, ['email' => $admin->email]);
        }

        return response()->json(['admin' => $this->present($admin)]);
    }

    public function resetMfa(Request $request, string $id)
    {
        $admin = $this->target($request, $id);

        $this->auth->resetMfa($admin);
        $this->audit->log($this->actor($request), 'admin_mfa_reset', $request->ip(), 'super_admin', $admin->id, ['email' => $admin->email]);

        return response()->json(['message' => 'MFA reset. They can set up a new authenticator at their next login.']);
    }

    protected function actor(Request $request): SuperAdmin
    {
        return $request->attributes->get('super_admin');
    }

    protected function target(Request $request, string $id): SuperAdmin
    {
        abort_if($id === $this->actor($request)->id, 422, 'You cannot do this to your own account.');

        return SuperAdmin::with('role:id,name,is_owner')->findOrFail($id);
    }

    protected function present(SuperAdmin $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role ? ['id' => $admin->role->id, 'name' => $admin->role->name, 'is_owner' => $admin->role->is_owner] : null,
            'mfa_enabled' => (bool) $admin->mfa_enabled_at,
            'last_login_at' => $admin->last_login_at,
            'disabled_at' => $admin->disabled_at,
        ];
    }
}
