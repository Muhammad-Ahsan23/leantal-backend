<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Step-up authentication for sensitive Super Admin actions: the session must have entered an authenticator code
 * recently (at login or via POST /super-admin/auth/step-up). Otherwise the frontend gets 'step_up_required',
 * asks for a code and repeats the request. Place AFTER admin.can so missing permission is reported first.
 */
class EnsureRecentMfa
{
    public function handle(Request $request, Closure $next)
    {
        $until = $request->attributes->get('super_admin')?->currentToken?->step_up_until;

        if (!$until || $until->isPast()) {
            return response()->json([
                'message' => 'Please confirm with your authenticator code to continue.',
                'code' => 'step_up_required',
            ], 403);
        }

        return $next($request);
    }
}
