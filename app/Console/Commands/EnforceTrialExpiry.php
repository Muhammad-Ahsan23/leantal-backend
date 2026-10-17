<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use App\Services\RegionResolver;

class EnforceTrialExpiry extends Command
{
    protected $signature = 'trial:enforce';

    protected $description = 'PRD Sections 10 / 120 — mark a company read_only when its trial ends unpaid, or when a failed-payment grace period runs out. Never deletes data.';

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

            // A failed renewal payment gets billing.past_due_grace_days of full access; when they run
            // out without a successful payment the company is read_only too (nothing is deleted).
            $graceDays = (int) config('billing.past_due_grace_days', 7);
            $lapsed = Company::on($connection)
                ->where('subscription_status', 'past_due')
                ->where('past_due_since', '<', now()->subDays($graceDays))
                ->update(['subscription_status' => 'read_only', 'past_due_since' => null]);

            if ($lapsed > 0) {
                $this->info("{$connection}: {$lapsed} past-due company(ies) moved to read_only after the grace period.");
            }

            $totalAffected += $affected + $lapsed;

            if ($affected > 0) {
                $this->info("{$connection}: {$affected} company(ies) moved to read_only.");
            }
        }

        $this->info("Done. Total: {$totalAffected}.");

        return self::SUCCESS;
    }
}
