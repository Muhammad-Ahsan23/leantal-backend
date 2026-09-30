<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OAuthToken;
use App\Services\MicrosoftGraphSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MicrosoftGraphWebhookController extends Controller
{
    public function __construct(protected MicrosoftGraphSyncService $sync) {}

    /**
     * PRD Section 57 — the receiving half of Outlook email sync.
     *
     * Microsoft Graph does a VALIDATION HANDSHAKE the moment a
     * subscription is created (and on some periodic re-validations):
     * it sends a request with ?validationToken=<value>, and expects
     * that exact token echoed back as plain text within 10 seconds —
     * this has no equivalent in Gmail's Pub/Sub model.
     *
     * SECURITY: every notification also carries 'clientState', which
     * we set at subscription-creation time (see
     * MicrosoftSubscriptionService::registerSubscription()) — checked
     * against GOOGLE_PUBSUB... no, MICROSOFT_WEBHOOK_SECRET here, same
     * shared-secret pattern as the Gmail webhook.
     */
    public function handle(Request $request)
    {
        if ($request->has('validationToken')) {
            return response($request->query('validationToken'), 200)
                ->header('Content-Type', 'text/plain');
        }

        $notifications = $request->input('value', []);

        foreach ($notifications as $notification) {
            if (($notification['clientState'] ?? null) !== config('services.microsoft.webhook_secret')) {
                Log::warning('Microsoft webhook: invalid clientState on notification');
                continue;
            }

            $messageId = $notification['resourceData']['id'] ?? null;
            if (!$messageId) {
                continue;
            }

            // Graph notifications don't carry WHICH mailbox they're
            // for by email address the way Gmail's do — instead, the
            // subscription itself is per-mailbox, so we look up by
            // subscription_id.
            $subscriptionId = $notification['subscriptionId'] ?? null;
            if (!$subscriptionId) {
                continue;
            }

            foreach (['pgsql_us', 'pgsql_eu', 'pgsql_uk'] as $connection) {
                $state = \App\Models\MicrosoftSubscriptionState::on($connection)
                    ->where('subscription_id', $subscriptionId)
                    ->first();

                if ($state) {
                    $token = OAuthToken::on($connection)
                        ->where('user_id', $state->user_id)
                        ->where('provider', 'microsoft')
                        ->whereNull('disconnected_at')
                        ->first();

                    if ($token) {
                        try {
                            $this->sync->processMessage($token, $messageId, $connection);
                        } catch (\RuntimeException $e) {
                            Log::warning('Microsoft Graph sync failed', ['message' => $e->getMessage()]);
                        }
                    }
                    break;
                }
            }
        }

        // Graph expects a 202 Accepted for notification batches.
        return response()->json([], 202);
    }
}
