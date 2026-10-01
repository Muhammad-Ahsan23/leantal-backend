<?php

namespace App\Services;

/**
 * PRD Section 145 — "Mandatory MFA" for Super Admin accounts. Pure-PHP
 * implementation of TOTP (RFC 6238, the standard behind Google
 * Authenticator / Authy / 1Password etc.) — no external service,
 * no API key, no per-user cost. The math is public specification,
 * not something that needs a third-party provider.
 */
class TotpService
{
    protected const PERIOD = 30; // seconds per code, per RFC 6238's own default
    protected const DIGITS = 6;

    /**
     * 160-bit (20-byte) secret, base32-encoded — the standard size
     * every authenticator app expects.
     */
    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /**
     * otpauth:// URI — the frontend renders this as a QR code (any
     * client-side QR library) for the admin to scan during enrollment.
     * We never need to generate the QR image itself server-side.
     */
    public function provisioningUri(string $secret, string $email, string $issuer = 'LeanTal'): string
    {
        $label = rawurlencode("{$issuer}:{$email}");
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);

        return "otpauth://totp/{$label}?{$params}";
    }

    /**
     * Verifies a 6-digit code against the secret. Checks the current
     * time-step AND one step on either side (±30s) to tolerate normal
     * clock drift between the admin's phone and our server — this is
     * standard, expected TOTP practice, not a security weakening.
     */
    public function verify(string $secret, string $code): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $currentStep = (int) floor(time() / self::PERIOD);

        foreach ([-1, 0, 1] as $drift) {
            if (hash_equals($this->generateCode($secret, $currentStep + $drift), $code)) {
                return true;
            }
        }

        return false;
    }

    protected function generateCode(string $secret, int $timeStep): string
    {
        $secretBytes = $this->base32Decode($secret);
        $timeBytes = pack('N*', 0, $timeStep); // 8-byte big-endian counter

        $hash = hash_hmac('sha1', $timeBytes, $secretBytes, true);

        $offset = ord($hash[19]) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad((string) ($truncated % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    protected function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binaryString = '';
        foreach (str_split($data) as $byte) {
            $binaryString .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($binaryString, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= $alphabet[bindec($chunk)];
        }

        return $output;
    }

    protected function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = strtoupper(rtrim($data, '='));

        $binaryString = '';
        foreach (str_split($data) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                continue;
            }
            $binaryString .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($binaryString, 8) as $byte) {
            if (strlen($byte) === 8) {
                $output .= chr(bindec($byte));
            }
        }

        return $output;
    }
}
