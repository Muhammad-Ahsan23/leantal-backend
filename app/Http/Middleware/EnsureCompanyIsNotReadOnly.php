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
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('get') || $request->isMethod('head')) {
            return $next($request);
        }

        $user = $request->user();
        if (!$user) {
            return $next($request); // let auth:sanctum handle unauthenticated requests
        }

        $connection = $user->getConnectionName();
        $company = Company::on($connection)->find($user->company_id);

        if ($company && $company->subscription_status === 'read_only') {
            return response()->json([
                'message' => 'Your trial has ended. Subscribe to continue using LeanTal.',
            ], 403);
        }

        return $next($request);
    }
}
