<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CreemService
{
    /**
     * PRD Section 11 — "Use Creem as the Merchant of Record... Do not
     * build a full payment system." We only ever create a checkout
     * session and react to webhooks — never touch card details.
     * Auto-detects sandbox vs live from the API key prefix, matching
     * Creem's own documented convention (creem_test_... = sandbox).
     */
    protected function apiBase(): string
    {
        $key = config('services.creem.api_key', '');

        return str_starts_with($key, 'creem_test_')
            ? 'https://test-api.creem.io'
            : 'https://api.creem.io';
    }

    /**
     * @throws \RuntimeException if plan/interval doesn't map to a configured product
     */
    public function createCheckout(Company $company, string $plan, string $interval, string $successUrl): string
    {
        $productKey = "{$plan}_{$interval}"; // e.g. "starter_monthly"
        $productId = config("services.creem.products.{$productKey}");

        if (!$productId) {
            throw new \RuntimeException("No Creem product configured for '{$productKey}'.");
        }

        $response = Http::withHeaders(['x-api-key' => config('services.creem.api_key')])
            ->post($this->apiBase().'/v1/checkouts', [
                'product_id' => $productId,
                'success_url' => $successUrl,
                'customer' => ['email' => null], // Creem prompts for email at checkout if omitted
                'metadata' => [
                    // How the webhook handler knows WHICH company to
                    // update — Creem echoes metadata back on every event.
                    'company_id' => $company->id,
                    'plan' => $plan,
                    'interval' => $interval,
                ],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Creem checkout creation failed: '.$response->body());
        }

        return $response->json('checkout_url');
    }

    /**
     * PRD Section 11 — "Use Creem's subscription/customer portal where
     * possible." Lets an existing subscriber manage/cancel their own
     * subscription without us building any billing UI ourselves.
     */
    public function createBillingPortalSession(string $creemCustomerId): string
    {
        // Creem docs: POST /v1/customers/billing  { "customer_id": "cust_..." }  ->  { "customer_portal_link": "https://..." }
        $response = Http::withHeaders(['x-api-key' => config('services.creem.api_key')])
            ->post($this->apiBase().'/v1/customers/billing', [
                'customer_id' => $creemCustomerId,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Creem portal session creation failed: '.$response->body());
        }

        $link = $response->json('customer_portal_link');
        if (!$link) {
            throw new \RuntimeException('Creem did not return a portal link.');
        }

        return $link;
    }

    /**
     * Plan tier order — used to decide upgrade vs downgrade, which
     * determines the correct proration mode (see changePlan() docblock).
     */
    protected const PLAN_TIERS = ['free' => 0, 'starter' => 1, 'team' => 2, 'scale' => 3];

    /**
     * CONFIRMED (docs.creem.io/api-reference/endpoint/upgrade-subscription,
     * fetched 2026-09-26) — changing an EXISTING subscriber's plan must
     * use Creem's dedicated upgrade endpoint, never a new checkout (a
     * new checkout would create a SECOND, duplicate subscription and
     * double-bill the customer).
     *
     * Proration mode matters and is NOT symmetric:
     * - Upgrades: 'proration-charge-immediately' — charges the price
     *   difference now, change takes effect immediately.
     * - Downgrades: 'proration-none' — MUST be used, not
     *   'proration-charge-immediately'. A real-world, documented bug
     *   (Creem returns 400 'subscription_concurrent_change' and refuses
     *   the change) occurs when a downgrade's owed refund exceeds what
     *   can be refunded against the single most recent charge — which
     *   is common once a customer has upgraded even once before. Using
     *   'proration-none' avoids this entirely: the plan changes
     *   immediately, the lower price simply takes effect at the next
     *   billing date, nothing is refunded.
     *
     * @throws \RuntimeException on failure or an unrecognized plan
     */
    public function changePlan(string $subscriptionId, string $currentPlan, string $newPlan, string $interval): array
    {
        $productKey = "{$newPlan}_{$interval}";
        $newProductId = config("services.creem.products.{$productKey}");

        if (!$newProductId) {
            throw new \RuntimeException("No Creem product configured for '{$productKey}'.");
        }

        $currentTier = self::PLAN_TIERS[$currentPlan] ?? 0;
        $newTier = self::PLAN_TIERS[$newPlan] ?? 0;
        $isUpgrade = $newTier >= $currentTier;

        $response = Http::withHeaders(['x-api-key' => config('services.creem.api_key')])
            ->post($this->apiBase()."/v1/subscriptions/{$subscriptionId}/upgrade", [
                'product_id' => $newProductId,
                'update_behavior' => $isUpgrade ? 'proration-charge-immediately' : 'proration-none',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Creem plan change failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Cancels a subscription IMMEDIATELY (no further charges). Used when a
     * company is permanently deleted. Verified against Creem's API reference
     * (docs.creem.io): POST /v1/subscriptions/{id}/cancel with {"mode":"immediate"};
     * 404 = Creem no longer has it. If the cancel call fails we double-check the
     * subscription's real status, so one that is ALREADY cancelled (e.g. the
     * customer cancelled it earlier in the portal) never blocks the deletion.
     *
     * @throws \RuntimeException (user-safe message) if it could not be cancelled
     */
    public function cancelSubscription(string $subscriptionId): void
    {
        $headers = ['x-api-key' => config('services.creem.api_key')];

        $response = Http::withHeaders($headers)
            ->post($this->apiBase()."/v1/subscriptions/{$subscriptionId}/cancel", ['mode' => 'immediate']);

        if ($response->successful() || $response->status() === 404) {
            return;
        }

        $status = Http::withHeaders($headers)
            ->get($this->apiBase().'/v1/subscriptions', ['subscription_id' => $subscriptionId]);

        if ($status->successful() && $status->json('status') === 'canceled') {
            return;
        }

        Log::warning('Creem subscription cancel failed during company deletion', [
            'subscription_id' => $subscriptionId,
            'http_status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \RuntimeException(
            "We couldn't cancel your subscription with our payment provider, so nothing was deleted. "
            .'Please try again in a few minutes or contact support.'
        );
    }

    /**
     * PRD Section 125 — webhooks "must be authenticated". ASSUMPTION:
     * HMAC-SHA256 over the raw request body, compared against a
     * 'creem-signature' header — this is the standard pattern most
     * Merchant-of-Record providers use (matches Stripe/similar), but
     * VERIFY against Creem's actual docs/a real test webhook once one
     * arrives — if the header name or algorithm differs, this needs a
     * one-line adjustment, not a redesign.
     */
    public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader): bool
    {
        if (!$signatureHeader) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawPayload, config('services.creem.webhook_secret', ''));

        return hash_equals($expected, $signatureHeader);
    }

    /**
     * Reverse-maps a Creem product_id back to our plan name (e.g.
     * "starter") — a webhook payload only carries product_id, not the
     * key we chose when creating the checkout.
     */
    public function planFromProductId(string $productId): ?string
    {
        $products = config('services.creem.products', []);

        foreach ($products as $key => $id) {
            if ($id === $productId) {
                return explode('_', $key)[0]; // "starter_monthly" -> "starter"
            }
        }

        return null;
    }
}
