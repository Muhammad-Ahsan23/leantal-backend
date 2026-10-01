<?php

namespace App\Services;

use App\Models\SuperAdmin;
use App\Models\SuperAdminAuditLog;

/**
 * PRD Section 159 — "Log all Super Admin actions: logins, logouts,
 * user impersonations, tenant suspensions, trial extensions,
 * notifications, and feature flag changes." One reusable entry point
 * so every future Super Admin controller logs consistently rather
 * than each reinventing the insert.
 */
class SuperAdminAuditService
{
    public function log(SuperAdmin $admin, string $action, ?string $ipAddress, ?string $targetType = null, ?string $targetId = null, array $metadata = []): void
    {
        SuperAdminAuditLog::create([
            'super_admin_id' => $admin->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata' => $metadata,
            'ip_address' => $ipAddress,
        ]);
    }
}
