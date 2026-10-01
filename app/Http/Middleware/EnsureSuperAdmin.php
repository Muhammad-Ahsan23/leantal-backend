<?php

namespace App\Http\Middleware;

use App\Services\SuperAdminAuthService;
use Closure;
use Illuminate\Http\Request;

/**
 * PRD Section 145 — dedicated, isolated authentication for Super
 * Admin routes. Deliberately NOT built on Sanctum's 'auth:sanctum'
 * guard (which resolves tenant Users) — a completely separate bearer
 * token namespace, so a leaked/forged customer token can never grant
 * Super Admin access and vice versa.
 */
class EnsureSuperAdmin
{
    public function __construct(protected SuperAdminAuthService $auth) {}

    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $admin = $this->auth->resolveToken($token);

        if (!$admin) {
            return response()->json(['message' => 'Session expired or invalid. Please log in again.'], 401);
        }

        // Available to every Super Admin controller via $request->superAdmin()
        $request->attributes->set('super_admin', $admin);

        return $next($request);
    }
}
