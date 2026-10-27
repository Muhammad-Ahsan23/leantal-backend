<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminAnalyticsController extends Controller
{
    protected const REGIONS = ['us' => 'pgsql_us', 'eu' => 'pgsql_eu', 'uk' => 'pgsql_uk'];
    protected const LEFT_EVENTS = ['subscription.canceled', 'subscription.expired', 'subscription.unpaid'];

    /**
     * Growth numbers for the dashboard: signups per month and region, trial-to-paid conversion per signup month,
     * churn (distinct companies that lost a paid subscription, per month), estimated MRR and companies per region.
     * "Paid" means the company has a Creem subscription; manually granted plans are not revenue.
     */
    public function index(Request $request)
    {
        $months = (int) ($request->validate(['months' => 'nullable|integer|min:3|max:24'])['months'] ?? 12);
        $start = now()->utc()->startOfMonth()->subMonths($months - 1);
        $labels = collect(range(0, $months - 1))->map(fn ($i) => $start->copy()->addMonths($i)->format('Y-m'));
        $blank = fn () => $labels->mapWithKeys(fn ($m) => [$m => 0])->all();

        $signups = ['us' => $blank(), 'eu' => $blank(), 'uk' => $blank()];
        $converted = $blank();
        $regions = [];
        $byPlan = [];

        foreach (self::REGIONS as $region => $connection) {
            $companies = DB::connection($connection)->table('companies')->where('region', $region);

            $regions[] = ['region' => $region, 'companies' => (clone $companies)->count()];

            $cohorts = (clone $companies)->where('created_at', '>=', $start)
                ->selectRaw("to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM') as m, count(*) as signups, count(creem_subscription_id) as paid")
                ->groupBy('m')->get();
            foreach ($cohorts as $row) {
                $signups[$region][$row->m] = (int) $row->signups;
                $converted[$row->m] += (int) $row->paid;
            }

            // Revenue-bearing now: paying (or in payment retry) companies with a real Creem subscription.
            (clone $companies)->whereIn('subscription_status', ['active', 'past_due'])->whereNotNull('creem_subscription_id')
                ->selectRaw('plan, count(*) as n')->groupBy('plan')->get()
                ->each(function ($r) use (&$byPlan) { $byPlan[$r->plan] = ($byPlan[$r->plan] ?? 0) + (int) $r->n; });
        }

        $churnRows = DB::connection('routing_db')->table('billing_webhook_events')
            ->whereIn('event_type', self::LEFT_EVENTS)->where('created_at', '>=', $start)
            ->selectRaw("to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM') as m, count(distinct payload->'object'->'metadata'->>'company_id') as n")
            ->groupBy('m')->pluck('n', 'm');

        $prices = collect(Company::PLAN_CATALOG)->pluck('monthly', 'key');
        $mrrByPlan = collect($byPlan)->map(fn ($n, $plan) => ['plan' => $plan, 'companies' => $n, 'monthly' => $n * ($prices[$plan] ?? 0)])->values();

        return response()->json([
            'signups' => $labels->map(fn ($m) => ['month' => $m, 'us' => $signups['us'][$m], 'eu' => $signups['eu'][$m], 'uk' => $signups['uk'][$m],
                'total' => $signups['us'][$m] + $signups['eu'][$m] + $signups['uk'][$m]])->values(),
            'conversion' => $labels->map(function ($m) use ($signups, $converted) {
                $n = $signups['us'][$m] + $signups['eu'][$m] + $signups['uk'][$m];

                return ['month' => $m, 'signups' => $n, 'paid' => $converted[$m], 'rate' => $n ? round($converted[$m] / $n * 100, 1) : null];
            })->values(),
            'churn' => $labels->map(fn ($m) => ['month' => $m, 'companies' => (int) ($churnRows[$m] ?? 0)])->values(),
            'mrr' => ['estimate' => $mrrByPlan->sum('monthly'), 'by_plan' => $mrrByPlan,
                'note' => 'Estimate from list prices (monthly billing). Customers on yearly billing pay up to ~20% less per month.'],
            'regions' => $regions,
        ]);
    }
}
