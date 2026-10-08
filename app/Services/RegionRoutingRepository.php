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
     * Signup-only: claims an email for a company ONLY if nobody has it yet, and says whether it won.
     * recordUserEmail() above is updateOrInsert(), which silently re-points an existing email to a
     * different company — fine for an invite, but at signup that would let a new company steal
     * (and lock out) an account that already exists. email is the primary key, so this is atomic.
     */
    public function claimUserEmail(string $email, string $companyId, string $region): bool
    {
        return DB::connection('routing_db')->table('user_email_region_lookup')->insertOrIgnore([
            'email' => strtolower(trim($email)),
            'company_id' => $companyId,
            'region' => $region,
            'updated_at' => now(),
        ]) === 1;
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

    /**
     * Public careers page URLs identify the company by SLUG, not email —
     * same "resolve region before touching any regional DB" problem,
     * different lookup key. Returns both region and company_id so the
     * caller doesn't need a second round-trip.
     */
    public function findCompanyBySlug(string $slug): ?array
    {
        $row = DB::connection('routing_db')->table('company_region_lookup')
            ->where('company_slug', $slug)
            ->first();

        if (!$row) {
            return null;
        }

        return ['region' => $row->region, 'company_id' => $row->company_id];
    }

    /**
     * Billing webhooks (Creem) only carry company_id in their metadata
     * (set at checkout-creation time) — never a slug or email — so this
     * lookup exists on top of the slug/email ones above.
     */
    public function findRegionByCompanyId(string $companyId): ?string
    {
        $row = DB::connection('routing_db')->table('company_region_lookup')
            ->where('company_id', $companyId)
            ->first();

        return $row?->region;
    }
}
