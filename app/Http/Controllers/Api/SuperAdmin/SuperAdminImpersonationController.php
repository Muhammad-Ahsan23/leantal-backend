<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\ImpersonateUserRequest;
use App\Models\User;
use App\Services\RegionResolver;
use App\Services\SuperAdminAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminImpersonationController extends Controller
{
    protected const ACCESS_TOKEN_MINUTES = 15; // same lifetime as a normal customer access token

    public function __construct(protected SuperAdminAuditService $audit) {}

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

        $connection = RegionResolver::connectionFor($region);

        $targetUser = User::on($connection)->where('company_id', $companyId)->find($userId);
        if (!$targetUser) {
            return response()->json(['message' => 'User not found in that company.'], 404);
        }

        $tokenResult = $targetUser->createToken('impersonation', ['impersonation'], now()->addMinutes(self::ACCESS_TOKEN_MINUTES));
        $tokenResult->accessToken->forceFill(['region' => $region])->save();

        $admin = $request->attributes->get('super_admin');
        $reason = $request->validated()['reason'];

        // token_id is how ImpersonationController::exit() (customer-side,
        // running under the impersonation token itself) finds its way
        // back to THIS specific audit entry to record the end time.
        $this->audit->log($admin, 'impersonate_start', $request->ip(), 'user', $userId, [
            'reason' => $reason,
            'company_id' => $companyId,
            'token_id' => $tokenResult->accessToken->id,
            'started_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'access_token' => $tokenResult->plainTextToken,
            'expires_in' => self::ACCESS_TOKEN_MINUTES * 60,
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
