<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SuperAdmin;
use App\Models\SuperAdminAuditLog;
use App\Services\SuperAdminAuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SuperAdminAuditController extends Controller
{
    private const PER_PAGE = 50;

    /**
     * PRD Section 159 — viewing side of the Super Admin audit trail. Entries are written through
     * SuperAdminAuditService::log() and are append-only (database trigger, see migration 000008).
     */
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $logs = $this->query($filters)->with('superAdmin:id,name,email')->paginate(self::PER_PAGE);

        return response()->json([
            'audit_log' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
            'filter_options' => [
                'admins' => SuperAdmin::orderBy('name')->get(['id', 'name', 'email']),
                'actions' => SuperAdminAuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            ],
        ]);
    }

    /** Streams the filtered log as CSV (no row cap, constant memory). The export itself is audited. */
    public function export(Request $request, SuperAdminAuditService $audit): StreamedResponse
    {
        $filters = $this->filters($request);
        $audit->log($request->attributes->get('super_admin'), 'audit_log_exported', $request->ip(), null, null, ['filters' => $filters]);

        return response()->streamDownload(function () use ($filters) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Time (UTC)', 'Admin', 'Admin email', 'Action', 'Target type', 'Target ID', 'IP address', 'Details']);

            $this->query($filters)->with('superAdmin:id,name,email')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $log) {
                    fputcsv($out, array_map([$this, 'safeCell'], [
                        $log->created_at?->utc()->format('Y-m-d H:i:s'),
                        $log->superAdmin?->name,
                        $log->superAdmin?->email,
                        $log->action,
                        $log->target_type,
                        $log->target_id,
                        $log->ip_address,
                        $log->metadata ? json_encode($log->metadata, JSON_UNESCAPED_UNICODE) : '',
                    ]));
                }
            });
            fclose($out);
        }, 'audit-log-'.now()->utc()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'super_admin_id' => 'nullable|uuid',
            'action' => 'nullable|string|max:100',
            'target_type' => 'nullable|string|max:100',
            'target_id' => 'nullable|string|max:100',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);
    }

    private function query(array $f): Builder
    {
        return SuperAdminAuditLog::query()
            ->when($f['super_admin_id'] ?? null, fn ($q, $v) => $q->where('super_admin_id', $v))
            ->when($f['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($f['target_type'] ?? null, fn ($q, $v) => $q->where('target_type', $v))
            ->when($f['target_id'] ?? null, fn ($q, $v) => $q->where('target_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', \Carbon\Carbon::parse($v)->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', \Carbon\Carbon::parse($v)->endOfDay()))
            ->orderByDesc('created_at')->orderByDesc('id');
    }

    /** Stops spreadsheet formula injection: cells starting with = + - @ are prefixed with an apostrophe. */
    private function safeCell(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'".$v : $v;
    }
}
