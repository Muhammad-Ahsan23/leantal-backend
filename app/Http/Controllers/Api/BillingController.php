<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CreateCheckoutRequest;
use App\Models\Company;
use App\Models\Job;
use App\Models\User;
use App\Services\CreemService;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    protected const PLAN_TIERS = ['free' => 0, 'starter' => 1, 'team' => 2, 'scale' => 3];

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
     * PRD Section 127 — Upgrade Flow. Owner only.
     *
     * Routes to ONE of two genuinely different Creem operations
     * depending on whether the company already has a live subscription:
     * - No active subscription yet -> a NEW checkout session.
     * - ALREADY subscribed -> CreemService::changePlan() against the
     *   EXISTING subscription (a second checkout here would open a
     *   duplicate subscription and double-bill the customer).
     *
     * PRD Section 128 — "Block downgrades if current usage exceeds
     * lower plan limits." Checked ONLY when genuinely downgrading
     * (new tier < current tier) — upgrades never need this check since
     * usage can only be LOWER than a higher plan's limits by
     * definition of why they're upgrading.
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

        $hasActiveSubscription = $company->subscription_status === 'active' && $company->creem_subscription_id;

        $currentTier = self::PLAN_TIERS[$company->plan] ?? 0;
        $newTier = self::PLAN_TIERS[$data['plan']] ?? 0;
        $isDowngrade = $hasActiveSubscription && $newTier < $currentTier;

        if ($isDowngrade) {
            $error = $this->checkDowngradeLimits($company, $data['plan'], $connection);
            if ($error) {
                return response()->json(['message' => $error], 422);
            }
        }

        try {
            if ($hasActiveSubscription) {
                if ($data['plan'] === $company->plan) {
                    return response()->json(['message' => 'You are already on this plan. Change the interval or pick a different plan.'], 422);
                }

                $result = $this->creem->changePlan(
                    $company->creem_subscription_id,
                    $company->plan,
                    $data['plan'],
                    $data['interval']
                );

                $company->update(['plan' => $data['plan']]);

                return response()->json(['message' => 'Plan changed successfully.', 'subscription' => $result]);
            }

            $successUrl = config('services.frontend_url').'/settings/billing?checkout=success';
            $checkoutUrl = $this->creem->createCheckout($company, $data['plan'], $data['interval'], $successUrl);

            return response()->json(['checkout_url' => $checkoutUrl]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    /**
     * PRD Section 128's own example format: "prevent downgrading from
     * 10 users to a 3-user plan until excess users are removed." Checks
     * BOTH limit types the Company model defines (seats AND jobs) —
     * the PRD's example only names seats, but the stated PRINCIPLE
     * ("current usage exceeds lower plan limits") applies symmetrically
     * to whichever limit the target plan actually constrains.
     *
     * @return string|null an error message if blocked, null if the downgrade is allowed
     */
    protected function checkDowngradeLimits(Company $company, string $newPlan, string $connection): ?string
    {
        // Simulate what the Company model's own limit methods would
        // return FOR the target plan, without actually changing
        // $company->plan yet — these methods key off ->plan internally,
        // so a throwaway in-memory clone avoids any risk of a partial
        // write if something below throws.
        $simulated = $company->replicate();
        $simulated->plan = $newPlan;

        $newSeatLimit = $simulated->seatLimit();
        if ($newSeatLimit !== null) {
            $activeUsers = User::on($connection)->where('company_id', $company->id)->where('status', 'active')->count();
            if ($activeUsers > $newSeatLimit) {
                return "Cannot downgrade to this plan — you currently have {$activeUsers} active users, which exceeds the {$newSeatLimit}-user limit. Remove or deactivate excess users first.";
            }
        }

        $newJobLimit = $simulated->jobLimit();
        if ($newJobLimit !== null) {
            $activeJobs = Job::on($connection)->where('company_id', $company->id)->whereIn('status', ['draft', 'published', 'paused'])->count();
            if ($activeJobs > $newJobLimit) {
                return "Cannot downgrade to this plan — you currently have {$activeJobs} active jobs, which exceeds the {$newJobLimit}-job limit. Close or archive excess jobs first.";
            }
        }

        return null;
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
