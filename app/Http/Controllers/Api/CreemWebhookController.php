<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\CreemService;
use App\Services\RegionResolver;
use App\Services\RegionRoutingRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PRD Section 125 — "authenticated, idempotent, logged, and retry-safe."
 *
 * Event names, payload shapes, and retry policy below are taken directly
 * from Creem's official docs (docs.creem.io/code/webhooks, fetched
 * 2026-09-25) — not guessed from PRD wording. PRD Section 125 names
 * generic events ("subscription.created", "payment.success") that don't
 * match Creem's actual API; this controller uses Creem's real event
 * names throughout.
 *
 * Retry policy (Creem's own docs): on any non-200 response, Creem
 * retries at 30s, 5min, 30min, and 6hr — 5 attempts total, never after
 * 24 hours. The same event can be delivered more than once even without
 * an error on our end, so idempotency (via billing_webhook_events) is
 * mandatory, not optional.
 */
class CreemWebhookController extends Controller
{
    /**
     * Events that mean "this subscription is paying and should have
     * full access." checkout.completed fires once at initial purchase;
     * subscription.active/paid confirm and renew it — handling all
     * three is redundant-safe (idempotent update), not double-charging.
     */
    protected const EVENTS_ACTIVATE = [
        'checkout.completed',
        'subscription.active',
        'subscription.paid',
    ];

    /** Terminal, merchant/customer-initiated cancellation. */
    protected const EVENT_CANCELED = 'subscription.canceled';

    /** Period ended with no successful payment — PRD's read_only state. */
    protected const EVENT_EXPIRED = 'subscription.expired';

    /**
     * Events we deliberately only LOG for now rather than act on — each
     * would need a subscription_status value or business rule PRD never
     * specified (e.g. what UI shows during "scheduled to cancel" or
     * "payment retrying"). Recorded in billing_webhook_events regardless,
     * so nothing is lost — just not yet wired to a state change. Extend
     * this when the client defines the desired behavior for each.
     */
    protected const EVENTS_LOGGED_ONLY = [
        'subscription.scheduled_cancel',
        'subscription.past_due',
        'subscription.unpaid',
        'subscription.trialing',
        'subscription.paused',
        'subscription.update',
        'refund.created',
        'dispute.created',
    ];

    public function __construct(
        protected CreemService $creem,
        protected RegionRoutingRepository $routing,
    ) {}

    public function handle(Request $request)
    {
        $rawPayload = $request->getContent();
        $signature = $request->header('creem-signature');

        if (!$this->creem->verifyWebhookSignature($rawPayload, $signature)) {
            Log::warning('Creem webhook: invalid signature');
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($rawPayload, true);
        $eventId = $payload['id'] ?? null;
        $eventType = $payload['eventType'] ?? null;
        $object = $payload['object'] ?? null;

        if (!$eventId || !$eventType || !$object) {
            Log::warning('Creem webhook: malformed payload', ['event_id' => $eventId, 'event_type' => $eventType]);
            return response()->json(['message' => 'Malformed payload.'], 400);
        }

        if ($this->alreadyProcessed($eventId)) {
            return response()->json(['message' => 'Already processed.'], 200);
        }

        $companyId = $object['metadata']['company_id'] ?? null;

        // PRIVACY: a payload carries the customer's email/name. When the company has
        // been permanently deleted, the cancellation event OUR OWN deletion triggers
        // arrives AFTER its data is gone — storing it would bring that personal data
        // straight back. For an unknown company only the event id/type are kept
        // (that is all idempotency needs); the payload is redacted.
        $companyGone = $companyId && $this->routing->findRegionByCompanyId($companyId) === null;
        $this->logEvent($eventId, $eventType, $companyGone ? ['redacted' => 'company no longer exists'] : $payload);

        if (!$companyId) {
            Log::warning('Creem webhook: no company_id in metadata', ['event_id' => $eventId, 'event_type' => $eventType]);
        } elseif (in_array($eventType, self::EVENTS_ACTIVATE, true)) {
            $this->activateSubscription($companyId, $object);
        } elseif ($eventType === self::EVENT_CANCELED) {
            $this->updateCompany($companyId, ['subscription_status' => 'cancelled']);
        } elseif ($eventType === self::EVENT_EXPIRED) {
            // PRD Section 129 — never delete data, just lock access.
            $this->updateCompany($companyId, ['subscription_status' => 'read_only']);
        } elseif (in_array($eventType, self::EVENTS_LOGGED_ONLY, true)) {
            Log::info('Creem webhook: event logged, no state change wired yet', [
                'event_id' => $eventId, 'event_type' => $eventType, 'company_id' => $companyId,
            ]);
        } else {
            Log::info('Creem webhook: unrecognized event type', ['event_id' => $eventId, 'event_type' => $eventType]);
        }

        $this->markProcessed($eventId);

        return response()->json(['message' => 'OK'], 200);
    }

    protected function alreadyProcessed(string $eventId): bool
    {
        return DB::connection('routing_db')->table('billing_webhook_events')
            ->where('event_id', $eventId)
            ->exists();
    }

    protected function logEvent(string $eventId, string $eventType, array $payload): void
    {
        DB::connection('routing_db')->table('billing_webhook_events')->insert([
            'id' => (string) Str::uuid(),
            'event_id' => $eventId,
            'event_type' => $eventType,
            'payload' => json_encode($payload),
            'created_at' => now(),
        ]);
    }

    protected function markProcessed(string $eventId): void
    {
        DB::connection('routing_db')->table('billing_webhook_events')
            ->where('event_id', $eventId)
            ->update(['processed_at' => now()]);
    }

    /**
     * CONFIRMED against Creem's official sample payloads (docs.creem.io/
     * code/webhooks): for checkout.completed, $object['object'] ===
     * 'checkout' and the subscription is nested at $object['subscription'].
     * For every subscription.* event, $object['object'] === 'subscription'
     * and $object IS the subscription — its id is $object['id'] directly.
     * This discriminator (Creem's own "object" type field) is used rather
     * than branching on event name, since Creem could add new event types
     * with either shape in the future.
     */
    protected function activateSubscription(string $companyId, array $object): void
    {
        $plan = $object['metadata']['plan'] ?? null;
        $customerId = $object['customer']['id'] ?? null;

        $subscriptionId = ($object['object'] ?? null) === 'subscription'
            ? ($object['id'] ?? null)
            : ($object['subscription']['id'] ?? null);

        $this->updateCompany($companyId, array_filter([
            'subscription_status' => 'active',
            'plan' => $plan,
            'creem_customer_id' => $customerId,
            'creem_subscription_id' => $subscriptionId,
        ]));
    }

    protected function updateCompany(string $companyId, array $data): void
    {
        $region = $this->routing->findRegionByCompanyId($companyId);
        if (!$region) {
            Log::warning('Creem webhook: unknown company_id', ['company_id' => $companyId]);
            return;
        }

        $connection = RegionResolver::connectionFor($region);
        $company = Company::on($connection)->find($companyId);

        if (!$company) {
            Log::warning('Creem webhook: company_id resolved to a region but no matching row', ['company_id' => $companyId, 'region' => $region]);
            return;
        }

        $company->update($data);
    }
}
