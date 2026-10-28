<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SystemEvent;
use App\Services\PlatformStatusService;
use App\Services\RegionHealthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Things a Super Admin should look at, worked out live from data that already exists (no alert table, nothing to
 * keep in sync). Each alert says how serious it is and where to go.
 */
class SuperAdminAlertsController extends Controller
{
    /** A billing event still unprocessed after this long means the webhook handler is failing. */
    protected const STUCK_WEBHOOK_MINUTES = 15;

    protected const TRIAL_WARNING_DAYS = 3;

    protected const ORDER = ['critical' => 0, 'warning' => 1, 'info' => 2];

    public function __construct(protected RegionHealthService $health, protected PlatformStatusService $platform) {}

    public function index()
    {
        $alerts = [];
        $pastDue = 0;
        $trialsEnding = 0;

        foreach (RegionHealthService::REGIONS as $region => $connection) {
            if ($this->health->ping($connection) === null) {
                $alerts[] = $this->alert('region_down_'.$region, 'critical', 'Region '.strtoupper($region).' database is unreachable',
                    'Customers in this region cannot log in or use the app.', 1, '/super-admin/system-health');

                continue;
            }
            $companies = DB::connection($connection)->table('companies')->where('region', $region);
            $pastDue += (clone $companies)->where('subscription_status', 'past_due')->count();
            $trialsEnding += (clone $companies)->where('subscription_status', 'trialing')
                ->whereBetween('trial_end', [now(), now()->addDays(self::TRIAL_WARNING_DAYS)])->count();
        }

        $stuck = DB::connection('routing_db')->table('billing_webhook_events')->whereNull('processed_at')
            ->where('created_at', '<', now()->subMinutes(self::STUCK_WEBHOOK_MINUTES))->count();
        if ($stuck > 0) {
            $alerts[] = $this->alert('webhooks_stuck', 'critical', 'Billing events are not being processed',
                "{$stuck} Creem event(s) have been waiting for more than ".self::STUCK_WEBHOOK_MINUTES.' minutes.', $stuck, '/super-admin/system-health');
        }

        $events = SystemEvent::whereNull('resolved_at')->where('created_at', '>=', now()->subDay())
            ->selectRaw('category, count(*) as total')->groupBy('category')->pluck('total', 'category');
        if ($events->sum() > 0) {
            $alerts[] = $this->alert('system_events', 'warning', 'Unresolved failures in the last 24 hours',
                $events->map(fn ($n, $category) => "{$category}: {$n}")->implode(', '), (int) $events->sum(), '/super-admin/debugging');
        }

        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        if ($failedJobs > 0) {
            $alerts[] = $this->alert('failed_jobs', 'warning', 'Failed background jobs', "{$failedJobs} job(s) failed.", $failedJobs, '/super-admin/system-health');
        }

        if ($pastDue > 0) {
            $alerts[] = $this->alert('past_due', 'warning', 'Companies with a failed payment', "{$pastDue} company(ies) are past due.", $pastDue, '/super-admin/companies');
        }

        $maintenance = $this->platform->maintenance();
        if ($maintenance['active']) {
            $alerts[] = $this->alert('maintenance', 'warning', 'Maintenance mode is ON', 'Customers see a maintenance message until '.$maintenance['ends_at'].'.', 1, '/super-admin/platform');
        }

        if ($trialsEnding > 0) {
            $alerts[] = $this->alert('trials_ending', 'info', 'Trials ending soon', "{$trialsEnding} trial(s) end within ".self::TRIAL_WARNING_DAYS.' days.', $trialsEnding, '/super-admin/companies');
        }

        usort($alerts, fn ($a, $b) => self::ORDER[$a['severity']] <=> self::ORDER[$b['severity']]);

        return response()->json([
            'alerts' => $alerts,
            'counts' => collect(array_keys(self::ORDER))->mapWithKeys(fn ($s) => [$s => collect($alerts)->where('severity', $s)->count()])->all(),
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    protected function alert(string $key, string $severity, string $title, string $detail, int $count, string $link): array
    {
        return compact('key', 'severity', 'title', 'detail', 'count', 'link');
    }
}
