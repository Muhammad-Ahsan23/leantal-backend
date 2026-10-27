<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\ImpersonateUserRequest;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\RegionResolver;
use App\Services\SuperAdminAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminImpersonationController extends Controller
{
    protected const ACCESS_TOKEN_MINUTES = 15; // same lifetime as a normal customer access token

    public function __construct(protected SuperAdminAuditService $audit, protected NotificationService $notifications) {}

    /**
     * PRD Section 80 — "Super Admin can Log in as user for customer
     * support... All impersonation sessions record Admin ID, Target
     * User ID, start time, end time, and reason in audit logs."
     *
     * Issues a REAL Sanctum token for the TARGET user (not some
     * separate shadow-session mechanism) — this is deliberate: the
     * impersonated session should behave EXACTLY like that user's own
     * session for every existing customer-facing endpoint, with zero
     * special-casing needed elsewhere in the app. The ability
     * ['impersonation'] tags it so the frontend can detect and show
     * PRD's required sticky banner, and so ImpersonationController::
     * exit() (customer-side) can find its way back to this audit entry.
     */
    public function start(ImpersonateUserRequest $request, string $companyId, string $userId)
    {
        $region = DB::connection('routing_db')->table('company_region_lookup')
            ->where('company_id', $companyId)
            ->value('region');

        if (!$region) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        $admin = $request->attributes->get('super_admin');
        $data = $request->validated();
        $full = ($data['mode'] ?? 'read_only') === 'full';

        if ($full && !$admin->hasPermission('companies.impersonate_full')) {
            return response()->json(['message' => 'You do not have permission to impersonate with edit access.'], 403);
        }

        $connection = RegionResolver::connectionFor($region);

        $targetUser = User::on($connection)->where('company_id', $companyId)->find($userId);
        if (!$targetUser) {
            return response()->json(['message' => 'User not found in that company.'], 404);
        }

        // 'read-only' is enforced by RestrictImpersonation; without it the session can edit (full mode).
        $tokenResult = $targetUser->createToken('impersonation', $full ? ['impersonation'] : ['impersonation', 'read-only'], now()->addMinutes(self::ACCESS_TOKEN_MINUTES));
        $tokenResult->accessToken->forceFill(['region' => $region])->save();

        // token_id is how ImpersonationController::exit() (customer-side,
        // running under the impersonation token itself) finds its way
        // back to THIS specific audit entry to record the end time.
        $this->audit->log($admin, 'impersonate_start', $request->ip(), 'user', $userId, [
            'reason' => $data['reason'],
            'ticket' => $data['ticket'],
            'mode' => $full ? 'full' : 'read_only',
            'company_id' => $companyId,
            'token_id' => $tokenResult->accessToken->id,
            'started_at' => now()->toIso8601String(),
        ]);

        // Transparency: the company's owner(s) always see that LeanTal support accessed the account (mandatory notification).
        User::on($connection)->where('company_id', $companyId)->where('role', 'owner')->get()->each(fn ($owner) => $this->notifications->notify(
            $owner,
            'support_access',
            'LeanTal support accessed your account for '.$targetUser->name.' ('.($full ? 'with edit access' : 'read-only').'), support ticket '.$data['ticket'].'.',
            $connection,
        ));

        return response()->json([
            'access_token' => $tokenResult->plainTextToken,
            'expires_in' => self::ACCESS_TOKEN_MINUTES * 60,
            'mode' => $full ? 'full' : 'read_only',
            'impersonating' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
                'role' => $targetUser->role,
                'company_id' => $targetUser->company_id,
            ],
        ]);
    }
}
