<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RefreshTokenService
{
    protected const REFRESH_EXPIRY_DAYS = 30; // PRD Section 15 — 30-day session

    /** `sessions.revoked_reason` of a session ended by a newer login of the same user. */
    public const REPLACED = 'replaced';

    /**
     * Issues a fresh refresh token and records it in the `sessions` table
     * (region-specific — lives alongside the user it belongs to). The
     * region is embedded as a prefix on the returned string ("us.xxxxx")
     * so a later refresh call can find the right regional DB without
     * needing the user's email — refresh tokens are opaque random strings,
     * there's no other way to route them.
     */
    public function generate(User $user, string $region, string $connection, ?string $userAgent, ?string $ip): string
    {
        $raw = Str::random(64);

        DB::connection($connection)->table('sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => hash('sha256', $raw), // fast hash — token is already high-entropy random, no need for Argon2id here
            'user_agent' => $userAgent,
            'ip_address' => $ip,
            'expires_at' => now()->addDays(self::REFRESH_EXPIRY_DAYS),
            'created_at' => now(),
        ]);

        return "{$region}.{$raw}";
    }

    /**
     * Validates + ROTATES a refresh token: the presented token is revoked
     * and a brand new one is issued in the same call. Returns null on any
     * failure (not found, expired, already revoked).
     *
     * Reuse detection: if a token that was already rotated (revoked_at
     * already set) gets presented again, that's a strong signal it was
     * stolen and the legitimate client already rotated past it — every
     * session for that user is immediately revoked as a precaution.
     */
    public function rotate(string $refreshToken, ?string $userAgent, ?string $ip): ?array
    {
        [$region, $raw] = $this->parse($refreshToken);
        if (!$region) {
            return null;
        }

        $connection = RegionResolver::connectionFor($region);
        $tokenHash = hash('sha256', $raw);

        $session = DB::connection($connection)->table('sessions')
            ->where('refresh_token_hash', $tokenHash)
            ->first();

        if (!$session) {
            return null;
        }

        if ($session->revoked_at !== null) {
            // Signed out because the same account logged in on another device (one device at a time): the
            // newer login is legitimate, so this is NOT a stolen token and must not end the new session.
            if (($session->revoked_reason ?? null) === self::REPLACED) {
                return null;
            }

            $this->revokeAllForUser($connection, $session->user_id);
            return null;
        }

        if (now()->greaterThan($session->expires_at)) {
            return null;
        }

        $user = User::on($connection)->find($session->user_id);
        if (!$user || $user->status !== 'active') {
            return null;
        }

        // PRD Section 119 — a suspended company can't mint new access tokens from an old refresh token.
        $suspended = DB::connection($connection)->table('companies')
            ->where('id', $user->company_id)->whereNotNull('suspended_at')->exists();
        if ($suspended) {
            $this->revokeAllForUser($connection, $user->id);
            return null;
        }

        DB::connection($connection)->table('sessions')
            ->where('id', $session->id)
            ->update(['revoked_at' => now()]);

        $newRefreshToken = $this->generate($user, $region, $connection, $userAgent, $ip);

        return ['user' => $user, 'connection' => $connection, 'region' => $region, 'refresh_token' => $newRefreshToken];
    }

    public function revoke(string $refreshToken): void
    {
        [$region, $raw] = $this->parse($refreshToken);
        if (!$region) {
            return;
        }

        DB::connection(RegionResolver::connectionFor($region))->table('sessions')
            ->where('refresh_token_hash', hash('sha256', $raw))
            ->update(['revoked_at' => now()]);
    }

    /**
     * One device at a time: ends every active session of the user, marked as "replaced" so the old device is told
     * why it was signed out (and so its next refresh is not mistaken for token theft).
     *
     * Falls back to deleting the rows if `revoked_reason` has not been added to this database yet, so a login
     * can never fail because the SQL / migration was not run.
     */
    public function revokeOtherSessions(string $connection, string $userId): void
    {
        $query = DB::connection($connection)->table('sessions')
            ->where('user_id', $userId)
            ->whereNull('revoked_at');

        if (Schema::connection($connection)->hasColumn('sessions', 'revoked_reason')) {
            $query->update(['revoked_at' => now(), 'revoked_reason' => self::REPLACED]);
        } else {
            $query->delete();
        }
    }

    /** True when this refresh token belongs to a session that a newer login of the same user ended. */
    public function wasReplaced(?string $refreshToken): bool
    {
        if (!$refreshToken) {
            return false;
        }

        [$region, $raw] = $this->parse($refreshToken);
        if (!$region) {
            return false;
        }

        $session = DB::connection(RegionResolver::connectionFor($region))->table('sessions')
            ->where('refresh_token_hash', hash('sha256', $raw))
            ->first();

        return $session !== null && $session->revoked_at !== null && ($session->revoked_reason ?? null) === self::REPLACED;
    }

    public function revokeAllForUser(string $connection, string $userId): void
    {
        DB::connection($connection)->table('sessions')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    protected function parse(string $token): array
    {
        if (!str_contains($token, '.')) {
            return [null, null];
        }

        [$region, $raw] = explode('.', $token, 2);

        if (!in_array($region, ['us', 'eu', 'uk'], true)) {
            return [null, null];
        }

        return [$region, $raw];
    }
}
