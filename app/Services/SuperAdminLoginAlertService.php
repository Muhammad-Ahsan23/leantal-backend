<?php

namespace App\Services;

use App\Mail\SuperAdminNewSignIn;
use App\Models\SuperAdmin;
use App\Models\SuperAdminAuditLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails an admin when their account signs in from an IP it has never signed in from before (judged by the audit
 * log's earlier 'login' entries). Call BEFORE the new login is written to the audit log. The mail is sent after the
 * HTTP response, and a mail failure is only logged — it can never stop someone signing in.
 */
class SuperAdminLoginAlertService
{
    public function onLogin(SuperAdmin $admin, ?string $ip, ?string $userAgent): void
    {
        try {
            if (!$ip || !$this->isNewIp($admin, $ip)) {
                return;
            }

            $when = now()->utc()->format('Y-m-d H:i').' UTC';
            dispatch(fn () => $this->send($admin, $ip, (string) $userAgent, $when))->afterResponse();
        } catch (Throwable $e) {
            Log::warning('Sign-in alert could not be queued: '.$e->getMessage());
        }
    }

    public function isNewIp(SuperAdmin $admin, string $ip): bool
    {
        return !SuperAdminAuditLog::where('super_admin_id', $admin->id)->where('action', 'login')->where('ip_address', $ip)->exists();
    }

    public function send(SuperAdmin $admin, string $ip, string $userAgent, string $when): void
    {
        try {
            Mail::to($admin->email)->send(new SuperAdminNewSignIn($admin->name, $ip, mb_substr($userAgent, 0, 200) ?: 'Unknown', $when));
        } catch (Throwable $e) {
            Log::warning("Sign-in alert email to {$admin->email} failed: ".$e->getMessage());
        }
    }
}
