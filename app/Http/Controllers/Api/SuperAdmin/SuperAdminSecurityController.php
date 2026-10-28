<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\AdminIpAllowlistService;
use App\Services\LockOutException;
use App\Services\SuperAdminAuditService;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Owner-only security settings: the admin IP allowlist. */
class SuperAdminSecurityController extends Controller
{
    public function __construct(
        protected AdminIpAllowlistService $allowlist,
        protected SuperAdminAuditService $audit,
    ) {}

    public function show(Request $request)
    {
        return response()->json($this->payload($request));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'entries' => ['present', 'array', 'max:'.AdminIpAllowlistService::MAX_ENTRIES],
            'entries.*.cidr' => ['required', 'string', 'max:64'],
            'entries.*.label' => ['nullable', 'string', 'max:60'],
        ]);

        $ip = $this->allowlist->clientIp($request);

        try {
            $this->allowlist->save((bool) $data['enabled'], $data['entries'], $ip);
        } catch (LockOutException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'would_lock_out'], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $saved = $this->allowlist->config();
        $this->audit->log($request->attributes->get('super_admin'), 'ip_allowlist_updated', $request->ip(), null, null, [
            'enabled' => $saved['enabled'],
            'entries' => array_column($saved['entries'], 'cidr'),
        ]);

        return response()->json($this->payload($request));
    }

    protected function payload(Request $request): array
    {
        return $this->allowlist->config() + [
            'current_ip' => $this->allowlist->clientIp($request),
            // Without a real mailer ("log"/"array") sign-in alert emails are only written to the log file.
            'mail_configured' => !in_array(config('mail.default'), ['log', 'array', 'null'], true),
            'max_entries' => AdminIpAllowlistService::MAX_ENTRIES,
        ];
    }
}
