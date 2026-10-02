<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SystemEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SuperAdminSystemHealthController extends Controller
{
    /**
     * PRD Section 157 — "Monitor application errors, failed jobs, sync
     * issues, Creem webhooks, queue health."
     */
    public function index(Request $request)
    {
        $unresolvedByCategory = SystemEvent::whereNull('resolved_at')
            ->selectRaw('category, count(*) as count')
            ->groupBy('category')
            ->pluck('count', 'category');

        $unprocessedWebhooks = DB::connection('routing_db')->table('billing_webhook_events')
            ->whereNull('processed_at')
            ->count();

        // "Queue health" — this project runs synchronously (no
        // background job queue in use), so Laravel's own failed_jobs
        // table (standard, auto-created by `queue:failed-table`) is
        // checked defensively and will simply read as empty/inactive
        // rather than throwing if queues are never actually used.
        $failedJobs = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->count()
            : 0;

        return response()->json([
            'sync_issues' => [
                'email' => $unresolvedByCategory['email'] ?? 0,
                'calendar_sync' => $unresolvedByCategory['calendar_sync'] ?? 0,
                'indexing' => $unresolvedByCategory['indexing'] ?? 0,
            ],
            'webhooks' => ['unprocessed' => $unprocessedWebhooks],
            'queue' => [
                'failed_jobs' => $failedJobs,
                'note' => 'This project processes work synchronously — no background queue is currently in active use.',
            ],
        ]);
    }
}
