<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PRD Section 65 — one place that writes an Activity Log row ("Sarah published Backend Engineer").
 * Logging must never break the action it describes, so a failed insert is only reported to the log.
 */
class ActivityLogger
{
    public static function log(string $connection, string $companyId, ?string $actorId, string $action, string $objectType, string $objectId, array $metadata = []): void
    {
        try {
            DB::connection($connection)->table('activity')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'actor_id' => $actorId,
                'action' => $action,
                'object_type' => $objectType,
                'object_id' => $objectId,
                'metadata' => json_encode($metadata),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Activity log write failed', ['action' => $action, 'message' => $e->getMessage()]);
        }
    }
}
