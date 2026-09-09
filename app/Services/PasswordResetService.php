<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordResetService
{
    protected const EXPIRY_MINUTES = 60;

    /**
     * Generates a fresh reset token, stores its hash, and returns the raw
     * token so the caller can put it in the emailed reset link.
     */
    public function generate(User $user, string $connection): string
    {
        $token = Str::random(64);

        DB::connection($connection)->table('password_reset_tokens')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'token_hash' => Hash::make($token),
            'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            'created_at' => now(),
        ]);

        return $token;
    }

    /**
     * Verifies a submitted token against the user's most recent, unused,
     * unexpired reset token. Marks it used on success so it can't be
     * replayed.
     */
    public function verifyAndConsume(User $user, string $connection, string $submittedToken): bool
    {
        $reset = DB::connection($connection)->table('password_reset_tokens')
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->first();

        if (!$reset || !Hash::check($submittedToken, $reset->token_hash)) {
            return false;
        }

        DB::connection($connection)->table('password_reset_tokens')
            ->where('id', $reset->id)
            ->update(['used_at' => now()]);

        return true;
    }
}
