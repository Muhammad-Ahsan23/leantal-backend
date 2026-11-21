<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;

class EnsureCompanyIsNotReadOnly
{
    /**
     * PRD Section 10 — after trial expiry, users "can still log in, view
     * existing information... but cannot perform normal editing/
     * creation actions." Applied broadly (whole authenticated API), but
     * ONLY blocks non-GET/HEAD requests — reads always pass through
     * untouched, so this is safe to attach without restructuring routes.
     *
     * BUG FIX (confirmed via code review — a frontend-side Claude
     * session flagged this as a suspected issue without access to this
     * file, and it was right): /logout and /logout-all are POST routes
     * with no exemption, so a read_only company would get a 403
     * "trial has ended" response INSTEAD OF logging out — trapping the
     * user in their own session. Logging out must always be allowed
     * regardless of billing/suspension state; it's not an "editing/
     * creation action" PRD Section 10 is talking about blocking.
     */
    protected const EXEMPT_PATHS = [
        'api/logout',
        'api/logout-all',
        // A company whose trial ended must still be able to erase its own data
        // (PRD Section 72 — erasure) — and to pay (PRD Section 10).
        'api/company/delete',
        'api/billing/checkout',
        'api/billing/portal',
    ];

    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('get') || $request->isMethod('head')) {
            return $next($request);
        }

        if ($request->is(...self::EXEMPT_PATHS)) {
            return $next($request);
        }

        $user = $request->user();
        if (!$user) {
            return $next($request); // let auth:sanctum handle unauthenticated requests
        }

        $connection = $user->getConnectionName();
        $company = Company::on($connection)->find($user->company_id);

        if ($company) {
            // 'cancelled' = Creem ended the subscription; a trial past its end date is locked even if the hourly
            // trial:enforce has not flipped the status yet (no free hour after day 14).
            $trialOver = $company->subscription_status === 'trialing' && $company->trial_end && $company->trial_end->isPast();

            if (in_array($company->subscription_status, ['read_only', 'cancelled'], true) || $trialOver) {
                return response()->json([
                    'message' => $company->subscription_status === 'cancelled'
                        ? 'Your subscription has ended. Subscribe to continue using LeanTal.'
                        : 'Your trial has ended. Subscribe to continue using LeanTal.',
                ], 403);
            }
        }

        return $next($request);
    }
}
