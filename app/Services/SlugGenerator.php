<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class SlugGenerator
{
    /**
     * Careers-page slugs are global (jobs.leantal.com/{slug}) across ALL
     * regions, so uniqueness must be checked against routing_db — the one
     * place that has a record of every company regardless of which
     * regional database it actually lives in.
     */
    public static function forCompany(string $companyName): string
    {
        $base = Str::slug($companyName);
        $slug = $base;
        $suffix = 1;

        while (self::slugTaken($slug)) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    protected static function slugTaken(string $slug): bool
    {
        return DB::connection('routing_db')
            ->table('company_region_lookup')
            ->where('company_slug', $slug)
            ->exists();
    }
}
