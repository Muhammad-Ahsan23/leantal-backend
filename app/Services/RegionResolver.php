<?php

namespace App\Services;

class RegionResolver
{
    // EU member country codes (ISO 3166-1 alpha-2), UK excluded (separate region)
    protected const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
        'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK',
        'SI', 'ES', 'SE', 'IS', 'LI', 'NO',
    ];

    protected const UK_COUNTRIES = ['GB'];

    /**
     * Resolve a company's data region from its country code.
     * Client-confirmed rule: EU -> eu, UK -> uk (separate from EU),
     * US + rest of world -> us.
     */
    public static function resolve(string $countryCode): string
    {
        $countryCode = strtoupper($countryCode);

        if (in_array($countryCode, self::UK_COUNTRIES, true)) {
            return 'uk';
        }

        if (in_array($countryCode, self::EU_COUNTRIES, true)) {
            return 'eu';
        }

        return 'us';
    }

    /** Region code -> Laravel DB connection name (config/database.php). */
    public static function connectionFor(string $region): string
    {
        return match ($region) {
            'eu' => 'pgsql_eu',
            'uk' => 'pgsql_uk',
            default => 'pgsql_us',
        };
    }

    /**
     * Reverse of connectionFor() — needed wherever code only has the
     * active DB connection name (e.g. $user->getConnectionName()) but
     * needs the region code itself (e.g. to pick a storage disk).
     */
    public static function regionForConnection(string $connection): string
    {
        return match ($connection) {
            'pgsql_eu' => 'eu',
            'pgsql_uk' => 'uk',
            default => 'us',
        };
    }

    /**
     * Region code -> Laravel filesystem disk name (config/filesystems.php).
     * Storage is a 2-way split (client-confirmed): R2 has no dedicated UK
     * jurisdiction, so UK files share the EU bucket. The DATABASE stays
     * fully separate per region — only file storage is shared.
     */
    public static function storageDiskFor(string $region): string
    {
        return match ($region) {
            'eu', 'uk' => 'r2_eu',
            default => 'r2_us',
        };
    }
}
