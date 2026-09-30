<?php

namespace App\Console\Commands;

use App\Models\MicrosoftSubscriptionState;
use App\Models\OAuthToken;
use App\Services\MicrosoftSubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RenewMicrosoftSubscriptions extends Command
{
    protected $signature = 'microsoft:renew-subscriptions';

    protected $description = 'PRD Section 57 — Microsoft Graph subscriptions expire after ~3 days (much sooner than Gmail watches); renew any expiring within 12 hours.';

    public function handle(MicrosoftSubscriptionService $subscriptionService): int
    {
        $totalRenewed = 0;

        foreach (['pgsql_us', 'pgsql_eu', 'pgsql_uk'] as $connection) {
            $expiring = MicrosoftSubscriptionState::on($connection)
                ->where('expires_at', '<', now()->addHours(12))
                ->get();

            foreach ($expiring as $state) {
                $token = OAuthToken::on($connection)
                    ->where('user_id', $state->user_id)
                    ->where('provider', 'microsoft')
                    ->whereNull('disconnected_at')
                    ->first();

                if (!$token) {
                    continue; // user disconnected Microsoft since subscription was registered
                }

                try {
                    $subscriptionService->renewSubscription($token, $state);
                    $totalRenewed++;
                } catch (\RuntimeException $e) {
                    Log::warning('Microsoft subscription renewal failed', ['user_id' => $state->user_id, 'message' => $e->getMessage()]);
                }
            }
        }

        $this->info("Renewed {$totalRenewed} Microsoft subscription(s).");

        return self::SUCCESS;
    }
}
