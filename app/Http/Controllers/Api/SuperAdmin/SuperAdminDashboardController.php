<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminDashboardController extends Controller
{
    protected const REGIONS = ['pgsql_us', 'pgsql_eu', 'pgsql_uk'];

    /**
     * PRD Section 77 — "Operational focus (not MRR/ARR analytics):
     * Total, active, trial, expired, read-only, and suspended
     * companies. Total users and recent signups. System errors,
     * integration failures, and webhook retry queues."
     *
     * Queries all 3 regional databases and sums — company data is
     * physically split by region, so there is no single table to
     * COUNT(*) against.
     *
     * SCOPE NOTE: the company/user counts below are fully built. The
     * "system errors / integration failures / webhook retry queue"
     * portion is intentionally a lightweight placeholder for this
     * Foundation pass (counts unprocessed Creem webhook events as a
     * proxy) — PRD Section 82's full Debugging view (failed emails,
     * calendar sync errors, etc.) is planned for a later phase, not
     * silently skipped.
     */
    public function index(Request $request)
    {
        $totals = ['total' => 0, 'active' => 0, 'trialing' => 0, 'read_only' => 0, 'cancelled' => 0, 'suspended' => 0];
        $totalUsers = 0;
        $recentSignups = 0;

        foreach (self::REGIONS as $connection) {
            $companies = DB::connection($connection)->table('companies')->get(['subscription_status', 'suspended_at', 'created_at']);

            $totals['total'] += $companies->count();
            $totals['active'] += $companies->where('subscription_status', 'active')->count();
            $totals['trialing'] += $companies->where('subscription_status', 'trialing')->count();
            $totals['read_only'] += $companies->where('subscription_status', 'read_only')->count();
            $totals['cancelled'] += $companies->where('subscription_status', 'cancelled')->count();
            $totals['suspended'] += $companies->whereNotNull('suspended_at')->count();

            $totalUsers += DB::connection($connection)->table('users')->count();
            $recentSignups += DB::connection($connection)->table('companies')->where('created_at', '>=', now()->subDays(7))->count();
        }

        // Best-effort proxy for "integration failures" until Section 82's
        // full Debugging view is built — counts Creem webhook events
        // that were logged but never successfully processed.
        $unprocessedWebhooks = DB::connection('routing_db')->table('billing_webhook_events')
            ->whereNull('processed_at')
            ->count();

        return response()->json([
            'companies' => $totals,
            'users' => ['total' => $totalUsers, 'recent_signups_7d' => $recentSignups],
            'system_health' => [
                'unprocessed_webhook_events' => $unprocessedWebhooks,
                'note' => 'Full error/sync-failure tracking (PRD Section 82) is planned for a later phase.',
            ],
        ]);
    }
}
