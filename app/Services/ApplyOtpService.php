<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * One-time email code that a candidate must enter before a public application is accepted.
 *
 * The code belongs to ONE email + ONE job, is stored only as a hash (cache, no table needed), expires after
 * EXPIRY_MINUTES, and stops working after MAX_ATTEMPTS wrong guesses or after one successful use.
 */
class ApplyOtpService
{
    public const EXPIRY_MINUTES = 10;
    public const MAX_ATTEMPTS = 3;

    protected function key(string $email, string $jobId): string
    {
        return 'apply-otp:'.sha1(strtolower(trim($email)).'|'.$jobId);
    }

    /** Creates a fresh 6-digit code (replacing any earlier one) and returns it so the caller can email it. */
    public function issue(string $email, string $jobId): string
    {
        $code = (string) random_int(100000, 999999);

        Cache::put($this->key($email, $jobId), [
            'hash' => Hash::make($code),
            'attempts' => 0,
        ], now()->addMinutes(self::EXPIRY_MINUTES));

        return $code;
    }

    /** True once, for the right code. Wrong guesses are counted; the third wrong one voids the code. */
    public function verify(string $email, string $jobId, string $code): string
    {
        $key = $this->key($email, $jobId);
        $entry = Cache::get($key);

        if (!$entry) {
            return 'missing'; // never requested, expired, already used, or voided
        }

        if (Hash::check(trim($code), $entry['hash'])) {
            Cache::forget($key);

            return 'ok';
        }

        $entry['attempts']++;
        if ($entry['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($key);

            return 'locked';
        }

        Cache::put($key, $entry, now()->addMinutes(self::EXPIRY_MINUTES));

        return 'wrong';
    }
}
