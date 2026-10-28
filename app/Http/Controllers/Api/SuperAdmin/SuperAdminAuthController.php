<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\ConfirmMfaEnrollmentRequest;
use App\Http\Requests\SuperAdmin\StartMfaEnrollmentRequest;
use App\Http\Requests\SuperAdmin\SuperAdminLoginRequest;
use App\Models\SuperAdmin;
use App\Services\AdminIpAllowlistService;
use App\Services\SuperAdminAuditService;
use App\Services\SuperAdminLoginAlertService;
use App\Services\SuperAdminAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class SuperAdminAuthController extends Controller
{
    /**
     * Brute-force protection (PRD Section 145 — the most privileged account). Failed attempts are counted
     * across login, enrollment and confirmation together, so splitting guesses over the three endpoints
     * does not help. Three limits apply at once:
     *   - 5 failures per email + IP, 10 per email (any IP), 30 per IP — each within 15 minutes.
     */
    protected const DECAY_SECONDS = 900;
    protected const MAX_PER_EMAIL_AND_IP = 5;
    protected const MAX_PER_EMAIL = 10;
    protected const MAX_PER_IP = 30;

    public function __construct(
        protected SuperAdminAuthService $auth,
        protected SuperAdminAuditService $audit,
        protected SuperAdminLoginAlertService $loginAlerts,
        protected AdminIpAllowlistService $ips,
    ) {}

    /**
     * PRD Section 145 — mandatory MFA. First-time setup: verifies the
     * password, generates a secret, returns a provisioning URI for the
     * frontend to render as a QR code. Nothing is "active" yet —
     * confirmEnrollment() below proves the admin actually captured it.
     * Refused once MFA is active (see SuperAdminAuthService::assertNotEnrolled).
     */
    public function startMfaEnrollment(StartMfaEnrollmentRequest $request)
    {
        $data = $request->validated();

        if ($blocked = $this->lockedOut($request, $data['email'])) {
            return $blocked;
        }

        try {
            $result = $this->auth->startMfaEnrollment($data['email'], $data['password']);
        } catch (\RuntimeException $e) {
            return $this->failed($request, $data['email'], 'mfa_enroll_failed', $e);
        }

        $this->clearFailures($request, $data['email']);

        return response()->json($result);
    }

    public function confirmMfaEnrollment(ConfirmMfaEnrollmentRequest $request)
    {
        $data = $request->validated();

        if ($blocked = $this->lockedOut($request, $data['email'])) {
            return $blocked;
        }

        try {
            $token = $this->auth->confirmMfaEnrollment($data['email'], $data['password'], $data['code']);
        } catch (\RuntimeException $e) {
            return $this->failed($request, $data['email'], 'mfa_confirm_failed', $e);
        }

        $this->clearFailures($request, $data['email']);

        $admin = SuperAdmin::where('email', strtolower(trim($data['email'])))->first();
        $ip = $this->ips->clientIp($request); // the real visitor address (also behind a trusted proxy)
        $this->audit->log($admin, 'mfa_enrolled', $ip);
        $this->loginAlerts->onLogin($admin, $ip, $request->userAgent());
        $this->audit->log($admin, 'login', $ip);

        return response()->json(['token' => $token]);
    }

    public function login(SuperAdminLoginRequest $request)
    {
        $data = $request->validated();

        if ($blocked = $this->lockedOut($request, $data['email'])) {
            return $blocked;
        }

        try {
            $token = $this->auth->login($data['email'], $data['password'], $data['code']);
        } catch (\RuntimeException $e) {
            return $this->failed($request, $data['email'], 'login_failed', $e);
        }

        $this->clearFailures($request, $data['email']);

        $admin = SuperAdmin::where('email', strtolower(trim($data['email'])))->first();
        $ip = $this->ips->clientIp($request); // the real visitor address (also behind a trusted proxy)
        $this->loginAlerts->onLogin($admin, $ip, $request->userAgent()); // must run before this login is audited
        $this->audit->log($admin, 'login', $ip);

        return response()->json(['token' => $token]);
    }

    public function logout(Request $request)
    {
        $admin = $request->attributes->get('super_admin');
        $token = $request->bearerToken();

        $this->audit->log($admin, 'logout', $request->ip());
        $this->auth->revokeToken($token);

        return response()->json(['message' => 'Logged out.']);
    }

    /** Step-up: re-enter an authenticator code to unlock sensitive actions for a few minutes (EnsureRecentMfa). */
    public function stepUp(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $admin = $request->attributes->get('super_admin');
        $key = 'sa-step-up:'.$admin->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_EMAIL_AND_IP)) {
            $wait = RateLimiter::availableIn($key);

            return response()->json(['message' => 'Too many failed attempts. Please try again in '.max(1, (int) ceil($wait / 60)).' minute(s).'], 429)
                ->header('Retry-After', (string) $wait);
        }

        try {
            $this->auth->stepUp($admin, $data['code']);
        } catch (\RuntimeException $e) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            $this->audit->log($admin, 'step_up_failed', $request->ip());

            return response()->json(['message' => $e->getMessage()], 422);
        }

        RateLimiter::clear($key);
        $this->audit->log($admin, 'step_up_verified', $request->ip());

        return response()->json(['message' => 'Verified.', 'valid_until' => $admin->currentToken->step_up_until->toIso8601String()]);
    }

    /** The signed-in admin and what they are allowed to do — the frontend builds its menu and buttons from this. */
    public function me(Request $request)
    {
        $admin = $request->attributes->get('super_admin');

        return response()->json([
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role ? ['id' => $admin->role->id, 'name' => $admin->role->name, 'is_owner' => $admin->role->is_owner] : null,
            ],
            'permissions' => $admin->permissions(),
        ]);
    }

    /** @return array<string, int> rate-limiter key => max failures */
    protected function limits(Request $request, string $email): array
    {
        $email = strtolower(trim($email));

        return [
            'sa-auth:'.$email.'|'.$request->ip() => self::MAX_PER_EMAIL_AND_IP,
            'sa-auth-email:'.$email => self::MAX_PER_EMAIL,
            'sa-auth-ip:'.$request->ip() => self::MAX_PER_IP,
        ];
    }

    /** A 429 if any limit is used up, otherwise null. */
    protected function lockedOut(Request $request, string $email): ?JsonResponse
    {
        foreach ($this->limits($request, $email) as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = RateLimiter::availableIn($key);

                return response()->json([
                    'message' => 'Too many failed attempts. Please try again in '.max(1, (int) ceil($wait / 60)).' minute(s).',
                ], 429)->header('Retry-After', (string) $wait);
            }
        }

        return null;
    }

    /** Counts the failure, writes it to the audit log when the account exists, and answers 422. */
    protected function failed(Request $request, string $email, string $action, \RuntimeException $e): JsonResponse
    {
        foreach (array_keys($this->limits($request, $email)) as $key) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
        }

        if ($admin = SuperAdmin::where('email', strtolower(trim($email)))->first()) {
            $this->audit->log($admin, $action, $request->ip());
        }

        return response()->json(['message' => $e->getMessage()], 422);
    }

    protected function clearFailures(Request $request, string $email): void
    {
        foreach (array_keys($this->limits($request, $email)) as $key) {
            RateLimiter::clear($key);
        }
    }
}
