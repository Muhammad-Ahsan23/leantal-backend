<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Usage: ->middleware('admin.can:companies.suspend'). Runs after EnsureSuperAdmin, which resolves the admin. */
class EnsureAdminCan
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        if (!$request->attributes->get('super_admin')->hasPermission($permission)) {
            return response()->json(['message' => 'You do not have permission to do this.'], 403);
        }

        return $next($request);
    }
}
