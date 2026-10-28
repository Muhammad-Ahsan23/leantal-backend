<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\PlatformStatusService;
use App\Services\SuperAdminAuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SuperAdminPlatformController extends Controller
{
    public function __construct(
        protected PlatformStatusService $status,
        protected SuperAdminAuditService $audit,
    ) {}

    /** What customers see right now: {maintenance: {active, message, ends_at}, announcement: {message, level, expires_at}|null}. */
    public function show()
    {
        return response()->json([
            'maintenance' => $this->status->maintenance(),
            'announcement' => $this->status->announcement(),
        ]);
    }

    /** One announcement banner for all customers, replacing the previous one. */
    public function publishAnnouncement(Request $request)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:300'],
            'level' => ['required', 'in:'.implode(',', PlatformStatusService::LEVELS)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $value = [
            'active' => true,
            'message' => trim($data['message']),
            'level' => $data['level'],
            'expires_at' => isset($data['expires_at']) ? Carbon::parse($data['expires_at'])->utc()->toIso8601String() : null,
        ];
        $this->status->put(PlatformStatusService::ANNOUNCEMENT, $value);

        $this->audit->log($request->attributes->get('super_admin'), 'announcement_published', $request->ip(), null, null, [
            'message' => $value['message'], 'level' => $value['level'], 'expires_at' => $value['expires_at'],
        ]);

        return response()->json(['announcement' => $this->status->announcement()]);
    }

    public function clearAnnouncement(Request $request)
    {
        if ($this->status->announcement()) {
            $this->status->put(PlatformStatusService::ANNOUNCEMENT, ['active' => false]);
            $this->audit->log($request->attributes->get('super_admin'), 'announcement_cleared', $request->ip());
        }

        return response()->json(['announcement' => null]);
    }

    /**
     * Customers get "503 maintenance" on every API call until it ends. An end time is REQUIRED (at most 24 hours
     * ahead) so a forgotten switch can never keep the platform down: maintenance switches itself off at that time.
     * The Super Admin panel keeps working throughout (see EnforceMaintenanceMode).
     */
    public function enableMaintenance(Request $request)
    {
        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:300'],
            'ends_at' => ['required', 'date', 'after:now', 'before_or_equal:+24 hours'],
        ]);

        $value = [
            'active' => true,
            'message' => isset($data['message']) ? trim($data['message']) : null,
            'ends_at' => Carbon::parse($data['ends_at'])->utc()->toIso8601String(),
        ];
        $this->status->put(PlatformStatusService::MAINTENANCE, $value);

        $this->audit->log($request->attributes->get('super_admin'), 'maintenance_enabled', $request->ip(), null, null, [
            'message' => $value['message'], 'ends_at' => $value['ends_at'],
        ]);

        return response()->json(['maintenance' => $this->status->maintenance()]);
    }

    /** Switching off needs no fresh authenticator code: ending an outage must never be slowed down. */
    public function disableMaintenance(Request $request)
    {
        if ($this->status->maintenance()['active']) {
            $this->status->put(PlatformStatusService::MAINTENANCE, ['active' => false]);
            $this->audit->log($request->attributes->get('super_admin'), 'maintenance_disabled', $request->ip());
        }

        return response()->json(['maintenance' => $this->status->maintenance()]);
    }
}
