<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Http;

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
        $response = Http::withHeaders(['x-api-key' => config('services.creem.api_key')])
            ->post($this->apiBase()."/v1/customers/{$creemCustomerId}/portal");

        if (!$response->successful()) {
            throw new \RuntimeException('Creem portal session creation failed: '.$response->body());
        }

        return $response->json('url');
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
