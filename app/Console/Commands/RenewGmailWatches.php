<?php

namespace App\Console\Commands;

use App\Models\GmailWatchState;
use App\Models\OAuthToken;
use App\Services\GmailWatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RenewGmailWatches extends Command
{
    protected $signature = 'gmail:renew-watches';

    protected $description = 'PRD Section 57 — Gmail watch registrations expire after 7 days; renew any expiring within 24 hours.';

    public function handle(GmailWatchService $watchService): int
    {
        $totalRenewed = 0;

        foreach (['pgsql_us', 'pgsql_eu', 'pgsql_uk'] as $connection) {
            $expiring = GmailWatchState::on($connection)
                ->where('watch_expires_at', '<', now()->addDay())
                ->get();

            foreach ($expiring as $watchState) {
                $token = OAuthToken::on($connection)
                    ->where('user_id', $watchState->user_id)
                    ->where('provider', 'google')
                    ->whereNull('disconnected_at')
                    ->first();

                if (!$token) {
                    continue; // user disconnected Google since watch was registered — nothing to renew
                }

                try {
                    $watchService->renewWatch($token, $connection);
                    $totalRenewed++;
                } catch (\RuntimeException $e) {
                    Log::warning('Gmail watch renewal failed', ['user_id' => $watchState->user_id, 'message' => $e->getMessage()]);
                }
            }
        }

        $this->info("Renewed {$totalRenewed} Gmail watch(es).");

        return self::SUCCESS;
    }
}
