<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Limits what a Super Admin can do while impersonating a customer (tokens carry the 'impersonation' ability).
 *  - Always blocked, in any mode: actions that destroy the customer's account/credentials or spend their money.
 *  - 'read-only' mode (the default): everything except GET/HEAD/OPTIONS is blocked, and so is the bulk candidate export.
 * Leaving the session (exit-impersonation, logout) is always allowed.
 */
class RestrictImpersonation
{
    protected const ALWAYS_BLOCKED = ['api/company/delete', 'api/logout-all', 'api/billing/checkout', 'api/billing/portal', 'api/me/change-password'];
    protected const READ_ONLY_BLOCKED_READS = ['api/candidates/export'];
    protected const ALWAYS_ALLOWED = ['api/exit-impersonation', 'api/logout'];

    public function handle(Request $request, Closure $next)
    {
        // in_array, not can(): Sanctum's can() is true for ANY ability on a wildcard ['*'] token (every normal login).
        $abilities = (array) ($request->user()?->currentAccessToken()?->abilities ?? []);
        if (!in_array('impersonation', $abilities, true) || $request->is(...self::ALWAYS_ALLOWED)) {
            return $next($request);
        }

        $readOnly = in_array('read-only', $abilities, true);
        $isRead = $request->isMethod('get') || $request->isMethod('head') || $request->isMethod('options');

        if ($request->is(...self::ALWAYS_BLOCKED) || ($readOnly && (!$isRead || $request->is(...self::READ_ONLY_BLOCKED_READS)))) {
            return response()->json([
                'message' => $readOnly
                    ? 'This is a read-only support session — changes are not allowed.'
                    : 'This action is not allowed during an impersonation session.',
                'code' => 'impersonation_restricted',
            ], 403);
        }

        return $next($request);
    }
}
