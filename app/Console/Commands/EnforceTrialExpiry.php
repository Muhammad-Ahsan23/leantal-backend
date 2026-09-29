<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use App\Services\RegionResolver;

class EnforceTrialExpiry extends Command
{
    protected $signature = 'trial:enforce';

    protected $description = 'PRD Section 10 — after the 72-hour trial ends without a subscription, mark the company read_only. Never deletes data.';

    /**
     * Runs across all 3 regional connections (US/EU/UK) — trials expire
     * independently per company regardless of which region its data
     * lives in.
     */
    public function handle(): int
    {
        $totalAffected = 0;

        foreach (['pgsql_us', 'pgsql_eu', 'pgsql_uk'] as $connection) {
            $affected = Company::on($connection)
                ->where('subscription_status', 'trialing')
                ->where('trial_end', '<', now())
                ->update(['subscription_status' => 'read_only']);

            $totalAffected += $affected;

            if ($affected > 0) {
                $this->info("{$connection}: {$affected} company(ies) moved to read_only.");
            }
        }

        $this->info("Done. Total: {$totalAffected}.");

        return self::SUCCESS;
    }
}
