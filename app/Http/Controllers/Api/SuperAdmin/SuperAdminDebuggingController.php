<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SystemEvent;
use App\Services\EmailService;
use App\Services\InterviewService;
use App\Services\RegionResolver;
use App\Services\SuperAdminAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminDebuggingController extends Controller
{
    public function __construct(
        protected EmailService $emails,
        protected InterviewService $interviews,
        protected SuperAdminAuditService $audit,
    ) {}

    /**
     * PRD Section 82 — "View failed email dispatches, calendar sync
     * errors, webhook failures, and Indexing API notifications."
     */
    public function index(Request $request)
    {
        $query = SystemEvent::orderByDesc('created_at');

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        $showResolved = $request->boolean('include_resolved');
        if (!$showResolved) {
            $query->whereNull('resolved_at');
        }

        $events = $query->limit(100)->get();

        // PRD Section 82 — webhook failures specifically. billing_webhook_events
        // already tracks this (Creem) separately from system_events —
        // merged in here so the Debugging view shows ALL failure types
        // from one endpoint, matching the PRD's single list.
        $webhookFailures = collect();
        if (!$category || $category === 'webhook') {
            $webhookFailures = DB::connection('routing_db')->table('billing_webhook_events')
                ->whereNull('processed_at')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(['id', 'event_type', 'created_at'])
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'category' => 'webhook',
                    'message' => "Unprocessed Creem webhook: {$row->event_type}",
                    'created_at' => $row->created_at,
                    'resolved_at' => null,
                    'retryable' => false, // Creem itself retries undelivered webhooks — we don't trigger that
                ]);
        }

        return response()->json([
            'events' => $events,
            'webhook_failures' => $webhookFailures,
        ]);
    }

    /**
     * PRD Section 82 — "safe retry triggers." Dispatches to the
     * correct service's retry method based on category — never an
     * arbitrary operation, always a known, specific re-attempt.
     */
    public function retry(Request $request, string $eventId)
    {
        $event = SystemEvent::find($eventId);
        if (!$event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        if ($event->resolved_at) {
            return response()->json(['message' => 'This event was already resolved.'], 422);
        }

        $connection = RegionResolver::connectionFor($event->region);
        $companyModel = \App\Models\Company::on($connection)->find($event->company_id);

        try {
            match ($event->category) {
                'email' => $this->emails->retryFailedEmail($event->context, $companyModel->name ?? '', $connection),
                'calendar_sync' => $this->interviews->retrySync($event->context['interview_id'], $event->context['operation'], $connection),
                default => throw new \RuntimeException("Retry is not supported for category '{$event->category}'."),
            };
        } catch (\RuntimeException $e) {
            return response()->json(['message' => "Retry failed: {$e->getMessage()}"], 422);
        }

        $event->update(['resolved_at' => now()]);

        $admin = $request->attributes->get('super_admin');
        $this->audit->log($admin, 'system_event_retried', $request->ip(), $event->category, $eventId);

        return response()->json(['message' => 'Retry succeeded.']);
    }
}
