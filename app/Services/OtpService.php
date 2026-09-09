<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OtpService
{
    protected const EXPIRY_MINUTES = 10;   // PRD Section 15
    protected const MAX_ATTEMPTS = 5;      // "Limit incorrect OTP attempts"

    /**
     * Generates a fresh 6-digit code, stores its hash (never the raw code),
     * and returns the raw code so the caller can email it.
     */
    public function generate(User $user, string $connection): string
    {
        $code = (string) random_int(100000, 999999);

        DB::connection($connection)->table('login_otps')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'otp_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            'created_at' => now(),
        ]);

        return $code;
    }

    /**
     * Verifies a submitted code against the user's most recent, unconsumed,
     * unexpired OTP. Returns true/false; increments the attempt counter on
     * every check so repeated guessing gets locked out (Section 15).
     */
    public function verify(User $user, string $connection, string $submittedCode): bool
    {
        $otp = DB::connection($connection)->table('login_otps')
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->first();

        if (!$otp) {
            return false; // no valid OTP outstanding — expired, already used, or never requested
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            return false; // locked out — caller should prompt the user to request a new code
        }

        DB::connection($connection)->table('login_otps')
            ->where('id', $otp->id)
            ->increment('attempts');

        if (!Hash::check($submittedCode, $otp->otp_hash)) {
            return false;
        }

        DB::connection($connection)->table('login_otps')
            ->where('id', $otp->id)
            ->update(['consumed_at' => now()]);

        return true;
    }
}
