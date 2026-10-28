<?php

namespace App\Http\Middleware;

use App\Services\PlatformStatusService;
use Closure;
use Illuminate\Http\Request;

class EnforceMaintenanceMode
{
    /**
     * Never blocked: the Super Admin panel (it must be able to switch maintenance off), the public status endpoint
     * the app polls to learn that maintenance is over, and the provider webhooks (Creem / Gmail / Microsoft), which
     * would otherwise lose events while we are down.
     */
    protected const EXEMPT_PATHS = ['api/super-admin/*', 'api/platform-status', 'api/webhooks/*'];

    public function __construct(protected PlatformStatusService $status) {}

    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('OPTIONS') || $request->is(...self::EXEMPT_PATHS)) {
            return $next($request);
        }

        $maintenance = $this->status->maintenance();
        if (!$maintenance['active']) {
            return $next($request);
        }

        $retryAfter = $maintenance['ends_at'] ? max(60, now()->diffInSeconds($maintenance['ends_at'], false)) : 300;

        return response()->json([
            'message' => $maintenance['message'],
            'code' => 'maintenance',
            'ends_at' => $maintenance['ends_at'],
        ], 503)->header('Retry-After', (string) (int) $retryAfter);
    }
}
