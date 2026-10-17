<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnsureCompanyIsNotSuspended
{
    /**
     * PRD Section 119 — "Suspension halts user login and job application intake."
     *
     * Login and the public careers page already refuse a suspended company, and suspending
     * revokes every token the company's users hold. This is the safety net behind both: any
     * authenticated request from a suspended company is refused, whatever token it carries and
     * whatever the HTTP method (reads too, unlike the read-only middleware).
     *
     * It answers 401 (not 403) on purpose: the frontend treats 401 as "session over", tries a
     * refresh, the refresh is refused for a suspended company, and the user lands on the login
     * page, which then shows the suspension message.
     *
     * Impersonation tokens are exempt: a Super Admin looking into a suspended company to support
     * it (PRD Sections 80 / 117) is not "user login", and is audited separately.
     */
    protected const EXEMPT_PATHS = [
        'api/logout',
        'api/logout-all',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) {
            return $next($request); // auth:sanctum deals with unauthenticated requests
        }

        if ($request->is(...self::EXEMPT_PATHS)) {
            return $next($request);
        }

        // NOT $token->can('impersonation'): Sanctum's can() is true for every ability on a wildcard ['*']
        // token, which is what ordinary logins get. Only a token issued FOR impersonation has this exact ability.
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        if ($token && in_array('impersonation', (array) ($token->abilities ?? []), true)) {
            return $next($request);
        }

        $suspendedAt = DB::connection($user->getConnectionName())
            ->table('companies')
            ->where('id', $user->company_id)
            ->value('suspended_at');

        if ($suspendedAt) {
            return response()->json([
                'message' => 'This account has been suspended. Please contact support.',
                'code' => 'company_suspended',
            ], 401);
        }

        return $next($request);
    }
}
