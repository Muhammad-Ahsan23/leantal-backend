<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OAuthToken;
use App\Services\GmailSyncService;
use App\Services\RegionResolver;
use App\Services\RegionRoutingRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GmailPushWebhookController extends Controller
{
    public function __construct(
        protected GmailSyncService $sync,
        protected RegionRoutingRepository $routing,
    ) {}

    /**
     * PRD Section 57 — the receiving half of email sync. Google Cloud
     * Pub/Sub calls this whenever a watched Gmail mailbox changes
     * (see GmailWatchService::registerWatch()).
     *
     * SECURITY: verified via a shared-secret query parameter set when
     * the Pub/Sub push subscription is created (?token=...), checked
     * against GOOGLE_PUBSUB_WEBHOOK_SECRET. This is simpler than full
     * OIDC-token verification (which Pub/Sub also supports) while still
     * preventing arbitrary internet requests from triggering a sync —
     * acceptable here since a forged call can only trigger a re-sync
     * of a mailbox we already trust, not exfiltrate or forge data.
     */
    public function handle(Request $request)
    {
        if ($request->query('token') !== config('services.google_calendar.pubsub_webhook_secret')) {
            Log::warning('Gmail push webhook: invalid or missing token');
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $encoded = $request->input('message.data');
        if (!$encoded) {
            return response()->json(['message' => 'Malformed Pub/Sub payload.'], 400);
        }

        $decoded = json_decode(base64_decode($encoded), true);
        $emailAddress = $decoded['emailAddress'] ?? null;

        if (!$emailAddress) {
            return response()->json(['message' => 'Malformed notification payload.'], 400);
        }

        // Pub/Sub identifies the mailbox by email address, not our
        // internal user_id — search across all 3 regions since we
        // don't know which one this connection lives in yet.
        foreach (['pgsql_us', 'pgsql_eu', 'pgsql_uk'] as $connection) {
            $token = OAuthToken::on($connection)
                ->where('provider', 'google')
                ->where('provider_email', $emailAddress)
                ->whereNull('disconnected_at')
                ->first();

            if ($token) {
                try {
                    $this->sync->syncNewMessages($token, $connection);
                } catch (\RuntimeException $e) {
                    Log::warning('Gmail sync failed', ['email' => $emailAddress, 'message' => $e->getMessage()]);
                }
                break;
            }
        }

        // Pub/Sub retries on any non-2xx response — always acknowledge
        // once we've attempted processing, to avoid endless redelivery
        // of the same notification.
        return response()->json(['message' => 'OK'], 200);
    }
}
