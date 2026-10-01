<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SuperAdminAuditLog;
use Illuminate\Http\Request;

class SuperAdminAuditController extends Controller
{
    /**
     * PRD Section 159 — "Log all Super Admin actions: logins, logouts,
     * user impersonations, tenant suspensions, trial extensions,
     * notifications, and feature flag changes." This is the VIEWING
     * side — every action that generates an entry does so via
     * SuperAdminAuditService::log(), called from each relevant
     * controller as that feature is built.
     */
    public function index(Request $request)
    {
        $query = SuperAdminAuditLog::with('superAdmin:id,name,email')->orderByDesc('created_at');

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }
        if ($targetType = $request->query('target_type')) {
            $query->where('target_type', $targetType);
        }
        if ($superAdminId = $request->query('super_admin_id')) {
            $query->where('super_admin_id', $superAdminId);
        }

        $logs = $query->limit(100)->get();

        return response()->json(['audit_log' => $logs]);
    }
}
