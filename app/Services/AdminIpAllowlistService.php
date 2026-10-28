<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Optional "Super Admin panel only from these IPs" switch, stored in platform_settings (key admin_ip_allowlist).
 *
 * Safety rules, because a wrong list can lock every admin out:
 *  - it can only be switched on when the caller's own IP is on the list (and the list is not empty);
 *  - the middleware FAILS OPEN — an unreadable or empty list never blocks anyone;
 *  - the server operator can always switch it off with `php artisan super-admin:allowlist-off`.
 */
class AdminIpAllowlistService
{
    public const KEY = 'admin_ip_allowlist';

    public const MAX_ENTRIES = 50;

    public function __construct(protected PlatformStatusService $settings) {}

    /** @return array{enabled: bool, entries: array<int, array{cidr: string, label: string}>} */
    public function config(): array
    {
        $raw = $this->settings->get(self::KEY);

        return ['enabled' => !empty($raw['enabled']), 'entries' => array_values($raw['entries'] ?? [])];
    }

    /**
     * The caller's address. Behind a proxy that overwrites a trusted header (e.g. Cloudflare's CF-Connecting-IP), set
     * ADMIN_IP_HEADER to that header name; otherwise the direct connection address is used. Never set it when the
     * server can also be reached without that proxy — the header could then be forged.
     */
    public function clientIp(Request $request): string
    {
        $header = config('admin.ip_header');
        $value = $header ? trim((string) $request->headers->get($header)) : '';

        return ($value !== '' && filter_var($value, FILTER_VALIDATE_IP)) ? $value : (string) $request->ip();
    }

    /** True when the allowlist is off, unreadable, empty, or the address is covered by an entry. */
    public function allows(string $ip): bool
    {
        try {
            $config = $this->config();
            if (!$config['enabled'] || !$config['entries']) {
                return true;
            }

            return IpUtils::checkIp($ip, array_column($config['entries'], 'cidr'));
        } catch (Throwable $e) {
            Log::warning('Admin IP allowlist could not be checked: '.$e->getMessage());

            return true;
        }
    }

    /**
     * Cleans the submitted list: trims, validates every address / CIDR range, drops duplicates.
     *
     * @param  array<int, array{cidr?: mixed, label?: mixed}>  $entries
     * @return array<int, array{cidr: string, label: string}>
     *
     * @throws InvalidArgumentException with a message fit to show the admin
     */
    public function normalize(array $entries): array
    {
        if (count($entries) > self::MAX_ENTRIES) {
            throw new InvalidArgumentException('At most '.self::MAX_ENTRIES.' entries are allowed.');
        }

        $clean = [];
        foreach ($entries as $entry) {
            $cidr = strtolower(trim((string) ($entry['cidr'] ?? '')));
            if (!$this->validCidr($cidr)) {
                throw new InvalidArgumentException("\"{$cidr}\" is not a valid IP address or CIDR range.");
            }
            $clean[$cidr] ??= ['cidr' => $cidr, 'label' => mb_substr(trim((string) ($entry['label'] ?? '')), 0, 60)];
        }

        return array_values($clean);
    }

    /** Stores the list. Refuses to enable one that would lock the caller (or everyone) out. */
    public function save(bool $enabled, array $entries, string $callerIp): void
    {
        $entries = $this->normalize($entries);

        if ($enabled) {
            if (!$entries) {
                throw new InvalidArgumentException('Add at least one IP address before turning the allowlist on.');
            }
            if (!IpUtils::checkIp($callerIp, array_column($entries, 'cidr'))) {
                throw new LockOutException("Your current IP ({$callerIp}) is not on the list. Add it first, otherwise you would lock yourself out.");
            }
        }

        $this->settings->put(self::KEY, ['enabled' => $enabled, 'entries' => $entries]);
    }

    /** Break-glass: switch the restriction off, keep the entries. */
    public function disable(): void
    {
        $this->settings->put(self::KEY, ['enabled' => false, 'entries' => $this->config()['entries']]);
    }

    protected function validCidr(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        if (!str_contains($value, '/')) {
            return (bool) filter_var($value, FILTER_VALIDATE_IP);
        }

        [$ip, $prefix] = explode('/', $value, 2);
        if (!ctype_digit($prefix) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return (int) $prefix <= (str_contains($ip, ':') ? 128 : 32);
    }
}
