<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class RegionRoutingRepository
{
    /**
     * Records the company -> region mapping so the careers page and login
     * flow can find the right regional database before querying it.
     */
    public function recordCompany(string $companyId, string $slug, string $region): void
    {
        DB::connection('routing_db')->table('company_region_lookup')->insert([
            'company_id' => $companyId,
            'company_slug' => $slug,
            'region' => $region,
            'created_at' => now(),
        ]);
    }

    /**
     * Records the email -> region mapping. Needed because the login flow
     * (PRD Section 15) only asks for email + password, with no company
     * selector — the system must resolve region from email alone.
     */
    public function recordUserEmail(string $email, string $companyId, string $region): void
    {
        DB::connection('routing_db')->table('user_email_region_lookup')->updateOrInsert(
            ['email' => strtolower(trim($email))],
            ['company_id' => $companyId, 'region' => $region, 'updated_at' => now()]
        );
    }

    /**
     * Login (PRD Section 15) only collects email + password — no company
     * selector — so this is how the system finds which regional database
     * to check credentials against, before it knows anything else.
     */
    public function findRegionByEmail(string $email): ?string
    {
        $row = DB::connection('routing_db')->table('user_email_region_lookup')
            ->where('email', strtolower(trim($email)))
            ->first();

        return $row?->region;
    }
}
