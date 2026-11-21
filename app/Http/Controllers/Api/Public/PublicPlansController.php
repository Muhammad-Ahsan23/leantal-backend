<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Company;

/**
 * Public (no login): what the sign-up page needs to let a visitor pick a plan — the sold plans straight from
 * Company::PLAN_CATALOG (the one place plans and prices live) and how long the free trial is.
 */
class PublicPlansController extends Controller
{
    public function __invoke()
    {
        $plans = array_map(fn (array $plan) => [
            'key' => $plan['key'],
            'name' => $plan['name'],
            'monthly' => $plan['monthly'],
            'yearly' => $plan['yearly'],
            'users' => $plan['users'],
            'jobs' => $plan['jobs'],
            'candidates' => $plan['candidates'] ?? null,
        ], Company::signupPlans());

        return response()
            ->json(['plans' => $plans, 'trial_days' => (int) config('billing.trial_days', 14)])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
