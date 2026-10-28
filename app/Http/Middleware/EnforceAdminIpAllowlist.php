<?php

namespace App\Http\Middleware;

use App\Services\AdminIpAllowlistService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Wraps every /api/super-admin route (login included): when the allowlist is on, other IPs get 403 ip_not_allowed. */
class EnforceAdminIpAllowlist
{
    public function __construct(protected AdminIpAllowlistService $allowlist) {}

    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('OPTIONS')) {
            return $next($request); // CORS preflight
        }

        $ip = $this->allowlist->clientIp($request);

        if (!$this->allowlist->allows($ip)) {
            Log::warning("Super Admin request blocked by IP allowlist: {$ip} {$request->method()} /{$request->path()}");

            return response()->json([
                'message' => 'Access to the admin panel is not allowed from this network.',
                'code' => 'ip_not_allowed',
            ], 403);
        }

        return $next($request);
    }
}
