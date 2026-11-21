<?php

namespace App\Console\Commands;

use App\Mail\TrialEndingReminder;
use App\Models\Company;
use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendTrialReminders extends Command
{
    protected $signature = 'trial:remind';

    protected $description = 'Email each company Owner when the free trial has 3 days left, and again when it ends tomorrow. Runs daily; each reminder is sent once per company.';

    /** Days left (rounded UP, exactly like the in-app banner) on which a reminder goes out. */
    protected const REMIND_ON_DAYS_LEFT = [3, 1];

    public function handle(): int
    {
        $sent = 0;

        foreach (['pgsql_us', 'pgsql_eu', 'pgsql_uk'] as $connection) {
            $companies = Company::on($connection)
                ->where('subscription_status', 'trialing')
                ->whereNotNull('trial_end')
                ->where('trial_end', '>', now())
                ->where('trial_end', '<=', now()->addDays(3))
                ->get();

            foreach ($companies as $company) {
                // A trial that has only just started (e.g. a 1-day test trial) does not get a reminder straight away.
                if ($company->trial_start && $company->trial_start->gt(now()->subHours(6))) {
                    continue;
                }

                $daysLeft = (int) ceil(($company->trial_end->getTimestamp() - now()->getTimestamp()) / 86400);
                if (!in_array($daysLeft, self::REMIND_ON_DAYS_LEFT, true)) {
                    continue;
                }

                // Once per company and reminder, even if the command runs twice or the regional databases overlap.
                $key = "trial-reminder:{$company->id}:{$daysLeft}";
                if (!Cache::add($key, true, now()->addDays(10))) {
                    continue;
                }

                try {
                    $sent += $this->remindOwners($company, $connection, $daysLeft);
                } catch (\Throwable $e) {
                    Cache::forget($key); // try again on the next run
                    Log::error('Trial reminder failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);
                }
            }
        }

        $this->info("Done. Reminder emails sent: {$sent}.");

        return self::SUCCESS;
    }

    protected function remindOwners(Company $company, string $connection, int $daysLeft): int
    {
        $planName = collect(Company::PLAN_CATALOG)->firstWhere('key', $company->plan)['name'] ?? ucfirst((string) $company->plan);
        $billingUrl = FrontendUrl::to('/settings', ['tab' => 'billing']);

        $owners = User::on($connection)
            ->where('company_id', $company->id)
            ->where('role', 'owner')
            ->where('status', 'active')
            ->get();

        foreach ($owners as $owner) {
            Mail::to($owner->email)->send(new TrialEndingReminder(
                $owner->name,
                $company->name,
                $planName,
                $daysLeft,
                $company->trial_end->format('j M Y'),
                $billingUrl,
            ));
        }

        return $owners->count();
    }
}
