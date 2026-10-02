<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\PlatformBroadcastRequest;
use App\Services\NotificationService;
use App\Services\RegionResolver;
use App\Services\SuperAdminAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminNotificationController extends Controller
{
    protected const REGIONS = ['us' => 'pgsql_us', 'eu' => 'pgsql_eu', 'uk' => 'pgsql_uk'];

    public function __construct(
        protected NotificationService $notifications,
        protected SuperAdminAuditService $audit,
    ) {}

    /**
     * PRD Section 81 — "Broadcast platform announcements, maintenance
     * notices, or security alerts to: All customers / Selected
     * companies / Selected users." Reuses NotificationService::
     * broadcast() (built for the Owner's company-level broadcast) —
     * this controller's job is purely figuring out WHICH companies/
     * regions to call it against for each targeting mode.
     */
    public function broadcast(PlatformBroadcastRequest $request)
    {
        $data = $request->validated();
        $totalSent = 0;

        if ($data['target'] === 'all') {
            foreach (self::REGIONS as $connection) {
                $companyIds = DB::connection($connection)->table('companies')->pluck('id');
                foreach ($companyIds as $companyId) {
                    $totalSent += $this->notifications->broadcast($companyId, $data['message'], [], $connection);
                }
            }
        } elseif ($data['target'] === 'companies') {
            foreach ($data['company_ids'] as $companyId) {
                $region = DB::connection('routing_db')->table('company_region_lookup')->where('company_id', $companyId)->value('region');
                if (!$region) {
                    continue; // silently skip an unresolvable company_id rather than failing the whole broadcast
                }
                $connection = RegionResolver::connectionFor($region);
                $totalSent += $this->notifications->broadcast($companyId, $data['message'], [], $connection);
            }
        } else { // target === 'users'
            // Group by company_id first — broadcast() takes one
            // company + its user_ids per call, so users targeting
            // different companies need separate calls.
            $byCompany = collect($data['users'])->groupBy('company_id');

            foreach ($byCompany as $companyId => $entries) {
                $region = DB::connection('routing_db')->table('company_region_lookup')->where('company_id', $companyId)->value('region');
                if (!$region) {
                    continue;
                }
                $connection = RegionResolver::connectionFor($region);
                $userIds = $entries->pluck('user_id')->all();
                $totalSent += $this->notifications->broadcast($companyId, $data['message'], $userIds, $connection);
            }
        }

        $admin = $request->attributes->get('super_admin');
        $this->audit->log($admin, 'platform_notification_sent', $request->ip(), null, null, [
            'target' => $data['target'],
            'message' => $data['message'],
            'recipients_notified' => $totalSent,
        ]);

        return response()->json(['message' => "Notification sent to {$totalSent} user(s)."]);
    }
}
