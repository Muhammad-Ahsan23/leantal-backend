<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Replaces Cache::tags() (which only 'redis'/'memcached' support) with a
 * driver-agnostic pattern that works on ANY cache store — including
 * Hostinger's plain 'database'/'file' drivers, which don't support
 * tagging at all and throw "This cache store does not support tagging."
 *
 * How it works: every cache KEY for a scope (e.g. "a company's jobs
 * list") includes a version number. To "invalidate everything in that
 * scope" we just bump the version — old cached entries under the old
 * version number become permanently unreachable (and expire via their
 * own TTL eventually), which has the same practical effect as a tag
 * flush, without needing tag support.
 */
class CacheVersion
{
    public static function get(string $scope): int
    {
        return (int) Cache::get("cache_version:{$scope}", 1);
    }

    public static function bump(string $scope): void
    {
        Cache::forever("cache_version:{$scope}", self::get($scope) + 1);
    }
}
