<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CreateCheckoutRequest;
use App\Models\Company;
use App\Services\CreemService;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(protected CreemService $creem) {}

    /**
     * PRD Section 9 — exact plan/pricing list. Hardcoded here rather
     * than fetched live from Creem — PRD explicitly says "Plan limits
     * beyond the above should remain easy to change later," which this
     * satisfies (one array to edit) without adding a live-API round
     * trip to every pricing-page load.
     */
    public function plans(Request $request)
    {
        return response()->json(['plans' => [
            ['key' => 'free', 'name' => 'Free', 'monthly' => 0, 'yearly' => 0, 'jobs' => 1, 'users' => 1],
            ['key' => 'starter', 'name' => 'Starter', 'monthly' => 19, 'yearly' => 15, 'jobs' => null, 'users' => 3],
            ['key' => 'team', 'name' => 'Team', 'monthly' => 49, 'yearly' => 39, 'jobs' => null, 'users' => 15],
            ['key' => 'scale', 'name' => 'Scale', 'monthly' => 99, 'yearly' => 79, 'jobs' => null, 'users' => null],
        ]]);
    }

    /**
     * PRD Section 127 — Upgrade Flow. Owner only (PRD Section 68:
     * "Billing (Owner only)"). Returns a checkout_url for the frontend
     * to redirect to — we never touch card details ourselves.
     */
    public function checkout(CreateCheckoutRequest $request)
    {
        $user = $request->user();

        if ($user->role !== 'owner') {
            return response()->json(['message' => 'Only the Owner can manage billing.'], 403);
        }

        $connection = $user->getConnectionName();
        $company = Company::on($connection)->find($user->company_id);
        $data = $request->validated();

        $successUrl = config('services.frontend_url').'/settings/billing?checkout=success';

        try {
            $checkoutUrl = $this->creem->createCheckout($company, $data['plan'], $data['interval'], $successUrl);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['checkout_url' => $checkoutUrl]);
    }

    /**
     * PRD Section 11 — "Use Creem's subscription/customer portal where
     * possible." Lets an existing paying customer manage/cancel their
     * own subscription — we never build cancellation UI ourselves.
     */
    public function portal(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'owner') {
            return response()->json(['message' => 'Only the Owner can manage billing.'], 403);
        }

        $connection = $user->getConnectionName();
        $company = Company::on($connection)->find($user->company_id);

        if (!$company->creem_customer_id) {
            return response()->json(['message' => 'No active subscription found.'], 422);
        }

        try {
            $url = $this->creem->createBillingPortalSession($company->creem_customer_id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['portal_url' => $url]);
    }
}
