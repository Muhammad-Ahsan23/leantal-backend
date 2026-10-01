<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SuperAdmin;
use App\Models\SuperAdminAuditLog;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    /**
     * PRD Section 80 — "[Exit Impersonation]" button in the sticky
     * banner. Runs under the IMPERSONATION token itself (auth:sanctum,
     * not the super-admin guard) — this is a customer-side endpoint
     * because the frontend is currently operating as the impersonated
     * user. Deliberately logs a NEW 'impersonate_end' entry rather than
     * editing the original 'impersonate_start' row — audit logs stay
     * append-only (a compromised admin session can't retroactively
     * rewrite its own trail); "start time, end time" per PRD is fully
     * captured by each entry's own created_at.
     */
    public function exit(Request $request)
    {
        $token = $request->user()->currentAccessToken();

        if (!$token || !$token->can('impersonation')) {
            return response()->json(['message' => 'This session is not an impersonation session.'], 422);
        }

        $startEntry = SuperAdminAuditLog::where('action', 'impersonate_start')
            ->whereJsonContains('metadata->token_id', $token->id)
            ->first();

        if ($startEntry) {
            $admin = SuperAdmin::find($startEntry->super_admin_id);
            $startedAt = $startEntry->metadata['started_at'] ?? null;

            SuperAdminAuditLog::create([
                'super_admin_id' => $startEntry->super_admin_id,
                'action' => 'impersonate_end',
                'target_type' => 'user',
                'target_id' => $startEntry->target_id,
                'metadata' => [
                    'token_id' => $token->id,
                    'started_at' => $startedAt,
                    'duration_seconds' => $startedAt ? now()->diffInSeconds($startedAt) : null,
                ],
                'ip_address' => $request->ip(),
            ]);
        }

        $token->delete();

        return response()->json(['message' => 'Impersonation ended.']);
    }
}
