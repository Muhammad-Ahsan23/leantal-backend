<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The two platform-wide switches kept in admin_db.platform_settings: maintenance mode and the announcement banner.
 *
 * Read on every customer API request (maintenance middleware), so the whole table is cached for a few seconds and
 * the cache is dropped whenever a Super Admin changes a setting. It FAILS OPEN: if the settings cannot be read the
 * platform is treated as "not in maintenance, no announcement" — a broken settings read must never take the app down.
 */
class PlatformStatusService
{
    public const MAINTENANCE = 'maintenance';

    public const ANNOUNCEMENT = 'announcement';

    public const LEVELS = ['info', 'warning', 'critical'];

    public const DEFAULT_MAINTENANCE_MESSAGE = 'The platform is undergoing maintenance. Please try again shortly.';

    protected const CACHE_KEY = 'platform_settings.v1';

    protected const CACHE_SECONDS = 15;

    /** Maintenance that is on AND has not passed its end time: {active, message, ends_at}. */
    public function maintenance(): array
    {
        $raw = $this->settings()[self::MAINTENANCE] ?? [];
        $endsAt = $raw['ends_at'] ?? null;
        $active = !empty($raw['active']) && (!$endsAt || Carbon::parse($endsAt)->isFuture());

        return $active
            ? ['active' => true, 'message' => $raw['message'] ?: self::DEFAULT_MAINTENANCE_MESSAGE, 'ends_at' => $endsAt]
            : ['active' => false, 'message' => null, 'ends_at' => null];
    }

    /** The announcement shown to customers right now, or null: {message, level, expires_at}. */
    public function announcement(): ?array
    {
        $raw = $this->settings()[self::ANNOUNCEMENT] ?? [];
        $expiresAt = $raw['expires_at'] ?? null;

        if (empty($raw['active']) || empty($raw['message']) || ($expiresAt && Carbon::parse($expiresAt)->isPast())) {
            return null;
        }

        return ['message' => $raw['message'], 'level' => $raw['level'] ?? 'info', 'expires_at' => $expiresAt];
    }

    /** Any other setting stored in platform_settings (e.g. the admin IP allowlist), cached like the rest; [] when unset. */
    public function get(string $key): array
    {
        return $this->settings()[$key] ?? [];
    }

    public function put(string $key, array $value): void
    {
        DB::connection('admin_db')->table('platform_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value), 'updated_at' => now()]
        );

        $this->forget();
    }

    public function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // the short cache expires by itself
        }
    }

    /** @return array<string, array> */
    protected function settings(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => $this->load());
        } catch (Throwable $e) {
            Log::warning('Platform settings cache unavailable: '.$e->getMessage());

            return [];
        }
    }

    /** An unreadable table is cached as "nothing set" too, so a broken database is not hammered on every request. */
    protected function load(): array
    {
        try {
            return DB::connection('admin_db')->table('platform_settings')->pluck('value', 'key')
                ->map(fn ($json) => json_decode($json, true) ?: [])->all();
        } catch (Throwable $e) {
            Log::warning('Platform settings could not be read: '.$e->getMessage());

            return [];
        }
    }
}
