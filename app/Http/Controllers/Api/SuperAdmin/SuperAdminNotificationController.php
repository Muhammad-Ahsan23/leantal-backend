<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\PlatformBroadcastRequest;
use App\Models\SuperAdminAuditLog;
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
            foreach (self::REGIONS as $region => $connection) {
                // Only this region's own companies: while the connections share one database, an unscoped
                // loop would notify every user once per connection (three times).
                $companyIds = DB::connection($connection)->table('companies')->where('region', $region)->pluck('id');
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
            // Who it was aimed at, so the history can show it (company ids / number of users picked).
            'company_ids' => $data['target'] === 'companies' ? $data['company_ids'] : null,
            'users_targeted' => $data['target'] === 'users' ? count($data['users']) : null,
        ]);

        return response()->json(['message' => "Notification sent to {$totalSent} user(s)."]);
    }

    /**
     * Every broadcast ever sent, newest first. Read from the audit log (which already records each one and cannot be
     * edited), so there is no second copy to keep in step. 20 per page.
     */
    public function history()
    {
        $page = SuperAdminAuditLog::with('superAdmin:id,name')
            ->where('action', 'platform_notification_sent')->latest('created_at')->latest('id')->paginate(20); // id (time-ordered uuid) breaks ties inside the same second

        return response()->json([
            'broadcasts' => $page->getCollection()->map(fn (SuperAdminAuditLog $log) => [
                'id' => $log->id,
                'sent_at' => $log->created_at,
                'sent_by' => $log->superAdmin?->name,
                'target' => $log->metadata['target'] ?? null,
                'message' => $log->metadata['message'] ?? '',
                'recipients' => $log->metadata['recipients_notified'] ?? 0,
                'company_ids' => $log->metadata['company_ids'] ?? null,
                'users_targeted' => $log->metadata['users_targeted'] ?? null,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }
}
