<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Admin and role management is never delegated: only the Owner role may use these routes. */
class EnsureAdminIsOwner
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->attributes->get('super_admin')->isOwner()) {
            return response()->json(['message' => 'Only an Owner can manage admins and roles.'], 403);
        }

        return $next($request);
    }
}
