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

    /**
     * Creem fires this when the subscription's product changes, whoever changed it (our Billing page, the customer
     * portal, or the Creem dashboard). It carries the CURRENT product, so it keeps the plan in step.
     */
    protected const EVENT_UPDATED = 'subscription.update';

    /** Terminal, merchant/customer-initiated cancellation. */
    protected const EVENT_CANCELED = 'subscription.canceled';

    /** Period ended with no successful payment — PRD's read_only state. */
    protected const EVENT_EXPIRED = 'subscription.expired';

    /**
     * A renewal charge failed and Creem is retrying. The company keeps FULL access for the grace
     * period (config billing.past_due_grace_days) and the Owner sees a banner to fix the card.
     */
    protected const EVENT_PAST_DUE = 'subscription.past_due';

    /** Retries are exhausted — nothing has been paid, so the company becomes read_only. */
    protected const EVENT_UNPAID = 'subscription.unpaid';

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
        'subscription.trialing',
        'subscription.paused',
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
        } elseif ($eventType === self::EVENT_UPDATED) {
            $this->syncPlanFromProduct($companyId, $object);
        } elseif ($eventType === self::EVENT_CANCELED) {
            $this->endSubscription($companyId, $object, 'cancelled');
        } elseif ($eventType === self::EVENT_PAST_DUE) {
            // Only an ACTIVE (or already past-due) subscription can slip into past_due. A late or
            // out-of-order event must never undo a cancellation or reopen a read_only company.
            $this->transition($companyId, ['active', 'past_due'], function (Company $company) {
                return [
                    'subscription_status' => 'past_due',
                    // keep the ORIGINAL date on repeated failures: the grace period doesn't restart
                    'past_due_since' => $company->past_due_since ?? now(),
                ];
            });
        } elseif ($eventType === self::EVENT_UNPAID) {
            $this->transition($companyId, ['active', 'past_due'], fn () => [
                'subscription_status' => 'read_only',
                'past_due_since' => null,
            ]);
        } elseif ($eventType === self::EVENT_EXPIRED) {
            // PRD Section 129 — never delete data, just lock access.
            $this->endSubscription($companyId, $object, 'read_only');
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
        // The product on the event is the plan the customer is on NOW. metadata.plan only records the plan picked at the
        // first checkout, so after any upgrade it is stale; it stays as a fallback for events without a product.
        $plan = $this->planFromObject($object) ?? ($object['metadata']['plan'] ?? null);
        $customerId = $object['customer']['id'] ?? null;

        $subscriptionId = ($object['object'] ?? null) === 'subscription'
            ? ($object['id'] ?? null)
            : ($object['subscription']['id'] ?? null);

        $this->updateCompany($companyId, array_filter([
            'subscription_status' => 'active',
            'plan' => $plan,
            // Product first; metadata.interval (set at the first checkout) only when the event has no known product.
            'billing_interval' => $this->intervalFromObject($object) ?? ($object['metadata']['interval'] ?? null),
            'creem_customer_id' => $customerId,
            'creem_subscription_id' => $subscriptionId,
        ]) + [
            // A successful payment ends any grace period. (Added after array_filter, which drops nulls.)
            'past_due_since' => null,
        ]);
    }

    /**
     * The plan key for the product on a Creem event, or null when the event has no product or it is not one of ours.
     * Creem sends the product as an object (object.product.id); a plain id string is accepted too.
     */
    protected function planFromObject(array $object): ?string
    {
        $productId = $this->productIdFromObject($object);

        return $productId !== null ? $this->creem->planFromProductId($productId) : null;
    }

    /** "monthly" or "yearly" for the product on a Creem event, or null when unknown. */
    protected function intervalFromObject(array $object): ?string
    {
        $productId = $this->productIdFromObject($object);

        return $productId !== null ? $this->creem->intervalFromProductId($productId) : null;
    }

    protected function productIdFromObject(array $object): ?string
    {
        $product = $object['product'] ?? ($object['subscription']['product'] ?? null);
        $productId = is_array($product) ? ($product['id'] ?? null) : $product;

        return is_string($productId) && $productId !== '' ? $productId : null;
    }

    /**
     * subscription.update: move the company to the plan of the event's product, so a change made in the customer portal or
     * the Creem dashboard reaches the app. Only a live subscription is touched; the status is never changed here.
     */
    protected function syncPlanFromProduct(string $companyId, array $object): void
    {
        $plan = $this->planFromObject($object);

        if ($plan === null) {
            Log::info('Creem webhook: subscription.update has no known product, plan left as is', ['company_id' => $companyId]);
            return;
        }

        $interval = $this->intervalFromObject($object);

        $this->transition($companyId, ['active', 'past_due'], fn () => array_filter(['plan' => $plan, 'billing_interval' => $interval]));
    }

    /**
     * Applies a status change only when the company is currently in one of $fromStatuses. $changes
     * receives the Company and returns the columns to write.
     */
    protected function transition(string $companyId, array $fromStatuses, callable $changes): void
    {
        $region = $this->routing->findRegionByCompanyId($companyId);
        if (!$region) {
            Log::warning('Creem webhook: unknown company_id', ['company_id' => $companyId]);
            return;
        }

        $company = Company::on(RegionResolver::connectionFor($region))->find($companyId);
        if (!$company) {
            return;
        }

        if (!in_array($company->subscription_status, $fromStatuses, true)) {
            Log::info('Creem webhook: status change ignored for current state', [
                'company_id' => $companyId, 'current' => $company->subscription_status,
            ]);
            return;
        }

        $company->update($changes($company));
    }

    /**
     * subscription.canceled / subscription.expired: lock the company - but only when the event is about the
     * subscription the company is on NOW. A late event for an OLD subscription (the customer cancelled, then
     * subscribed again) must not lock a company that is paying today.
     */
    protected function endSubscription(string $companyId, array $object, string $status): void
    {
        $eventSubscriptionId = ($object['object'] ?? null) === 'subscription'
            ? ($object['id'] ?? null)
            : ($object['subscription']['id'] ?? null);

        $region = $this->routing->findRegionByCompanyId($companyId);
        $company = $region ? Company::on(RegionResolver::connectionFor($region))->find($companyId) : null;

        if ($company && $eventSubscriptionId && $company->creem_subscription_id && $company->creem_subscription_id !== $eventSubscriptionId) {
            Log::info('Creem webhook: end-of-subscription event ignored, it is for an older subscription', [
                'company_id' => $companyId, 'event_subscription' => $eventSubscriptionId, 'current' => $company->creem_subscription_id,
            ]);
            return;
        }

        $this->updateCompany($companyId, ['subscription_status' => $status]);
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
