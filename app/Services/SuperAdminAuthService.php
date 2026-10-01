<?php

namespace App\Services;

use App\Models\SuperAdmin;
use App\Models\SuperAdminToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * PRD Section 145 — "dedicated authentication, mandatory MFA,
 * aggressive session timeouts." Completely separate from the
 * customer-facing Sanctum setup — own token table, own (short)
 * expiry, own MFA requirement baked directly into login itself.
 *
 * DESIGN NOTE: every sensitive action here re-verifies the password
 * (rather than issuing an intermediate "setup token" between steps) —
 * simpler, fully stateless, and avoids a whole class of "half-
 * authenticated" token bugs. For a low-frequency, highly-privileged
 * flow like this, asking for the password twice during enrollment is
 * a reasonable, deliberate trade-off, not an oversight.
 */
class SuperAdminAuthService
{
    protected const TOKEN_LIFETIME_MINUTES = 30;

    public function __construct(protected TotpService $totp) {}

    /**
     * @throws \RuntimeException on invalid credentials
     */
    protected function verifyPassword(string $email, string $password): SuperAdmin
    {
        $admin = SuperAdmin::where('email', strtolower(trim($email)))->first();

        if (!$admin || !Hash::check($password, $admin->password_hash)) {
            throw new \RuntimeException('Invalid credentials.');
        }

        return $admin;
    }

    /**
     * First-time setup only. Generates and PERSISTS a new secret
     * immediately (overwriting any previous unconfirmed one) — nothing
     * is active yet until confirmEnrollment() succeeds.
     *
     * @throws \RuntimeException on invalid credentials
     */
    public function startMfaEnrollment(string $email, string $password): array
    {
        $admin = $this->verifyPassword($email, $password);

        $secret = $this->totp->generateSecret();
        $admin->update(['mfa_secret' => $secret]);

        return [
            'secret' => $secret,
            'provisioning_uri' => $this->totp->provisioningUri($secret, $admin->email),
        ];
    }

    /**
     * Proves the admin actually scanned the code correctly before MFA
     * is marked active. Issues a session token immediately on success
     * — no need to make them log in a third time right after setup.
     *
     * @throws \RuntimeException on invalid credentials or wrong code
     */
    public function confirmMfaEnrollment(string $email, string $password, string $code): string
    {
        $admin = $this->verifyPassword($email, $password);

        if (!$admin->mfa_secret) {
            throw new \RuntimeException('Call the enrollment endpoint first.');
        }

        if (!$this->totp->verify($admin->mfa_secret, $code)) {
            throw new \RuntimeException('Invalid authentication code.');
        }

        $admin->update(['mfa_enabled_at' => now(), 'last_login_at' => now()]);

        return $this->issueToken($admin);
    }

    /**
     * Normal login, post-enrollment — both factors in ONE request.
     *
     * @throws \RuntimeException on invalid credentials, missing enrollment, or wrong code
     */
    public function login(string $email, string $password, string $code): string
    {
        $admin = $this->verifyPassword($email, $password);

        if (!$admin->mfa_secret || !$admin->mfa_enabled_at) {
            throw new \RuntimeException('MFA is not yet set up on this account. Complete enrollment first.');
        }

        if (!$this->totp->verify($admin->mfa_secret, $code)) {
            throw new \RuntimeException('Invalid authentication code.');
        }

        $admin->update(['last_login_at' => now()]);

        return $this->issueToken($admin);
    }

    protected function issueToken(SuperAdmin $admin): string
    {
        $rawToken = Str::random(64);

        SuperAdminToken::create([
            'super_admin_id' => $admin->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addMinutes(self::TOKEN_LIFETIME_MINUTES),
        ]);

        return $rawToken;
    }

    public function resolveToken(string $rawToken): ?SuperAdmin
    {
        $tokenRecord = SuperAdminToken::where('token_hash', hash('sha256', $rawToken))
            ->where('expires_at', '>', now())
            ->first();

        if (!$tokenRecord) {
            return null;
        }

        return SuperAdmin::find($tokenRecord->super_admin_id);
    }

    public function revokeToken(string $rawToken): void
    {
        SuperAdminToken::where('token_hash', hash('sha256', $rawToken))->delete();
    }
}
