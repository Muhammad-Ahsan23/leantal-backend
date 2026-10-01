<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\RegionResolver;
use App\Services\SuperAdminAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminCompanyController extends Controller
{
    protected const REGIONS = ['us' => 'pgsql_us', 'eu' => 'pgsql_eu', 'uk' => 'pgsql_uk'];

    public function __construct(protected SuperAdminAuditService $audit) {}

    /**
     * PRD Section 78 — "Search, Company name, Owner & Owner Email,
     * Region, Plan & Status, Trial end date, Created date & User
     * count." Queries all 3 regions and merges — companies are
     * physically split by region, there's no single cross-region table.
     */
    public function index(Request $request)
    {
        $search = $request->query('search');
        $results = collect();

        foreach (self::REGIONS as $region => $connection) {
            $query = DB::connection($connection)->table('companies as c')
                ->leftJoin('users as owner', function ($join) {
                    $join->on('owner.company_id', '=', 'c.id')->where('owner.role', '=', 'owner');
                })
                ->select(
                    'c.id', 'c.name', 'c.plan', 'c.subscription_status', 'c.trial_end',
                    'c.created_at', 'c.suspended_at',
                    'owner.name as owner_name', 'owner.email as owner_email'
                )
                ->selectRaw("'{$region}' as region");

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('c.name', 'ilike', "%{$search}%")
                        ->orWhere('owner.email', 'ilike', "%{$search}%");
                });
            }

            $companies = $query->get();

            // User counts — one grouped query per region rather than
            // N+1 per company.
            $userCounts = DB::connection($connection)->table('users')
                ->select('company_id', DB::raw('count(*) as cnt'))
                ->whereIn('company_id', $companies->pluck('id'))
                ->groupBy('company_id')
                ->pluck('cnt', 'company_id');

            foreach ($companies as $company) {
                $company->user_count = $userCounts[$company->id] ?? 0;
                $results->push($company);
            }
        }

        return response()->json(['companies' => $results->sortByDesc('created_at')->values()]);
    }

    /**
     * PRD Section 79 — full company detail: profile, subscription,
     * users list, usage counts. company_id alone doesn't say which
     * region to query (unlike tenant-side requests, where the LOGGED-
     * IN user's own region is already known) — resolved via
     * routing_db's company_region_lookup, same pattern as the Creem
     * webhook handler uses.
     */
    public function show(Request $request, string $companyId)
    {
        $region = DB::connection('routing_db')->table('company_region_lookup')
            ->where('company_id', $companyId)
            ->value('region');

        if (!$region) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        $connection = RegionResolver::connectionFor($region);

        $company = DB::connection($connection)->table('companies')->where('id', $companyId)->first();
        if (!$company) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        $users = DB::connection($connection)->table('users')
            ->where('company_id', $companyId)
            ->get(['id', 'name', 'email', 'role', 'status', 'last_login_at']);

        $jobsCount = DB::connection($connection)->table('jobs')
            ->where('company_id', $companyId)
            ->whereIn('status', ['draft', 'published', 'paused'])
            ->count();

        $candidatesCount = DB::connection($connection)->table('candidates')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->count();

        return response()->json([
            'company' => $company,
            'region' => $region,
            'users' => $users,
            'usage' => [
                'active_jobs' => $jobsCount,
                'candidates' => $candidatesCount,
                'users' => $users->count(),
            ],
        ]);
    }

    /**
     * PRD Section 118/119 — "Super Admin can suspend/reactivate
     * tenants... Suspension halts user login and job application
     * intake while keeping all historical data intact." Enforcement
     * lives in LoginController and PublicCareersController — this
     * just sets the flag those checks read.
     */
    public function suspend(Request $request, string $companyId)
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        [$connection, $region] = $this->resolveConnection($companyId);
        if (!$connection) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        DB::connection($connection)->table('companies')->where('id', $companyId)->update([
            'suspended_at' => now(),
            'suspended_reason' => $request->input('reason'),
        ]);

        $admin = $request->attributes->get('super_admin');
        $this->audit->log($admin, 'company_suspended', $request->ip(), 'company', $companyId, ['reason' => $request->input('reason')]);

        return response()->json(['message' => 'Company suspended.']);
    }

    public function reactivate(Request $request, string $companyId)
    {
        [$connection, $region] = $this->resolveConnection($companyId);
        if (!$connection) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        DB::connection($connection)->table('companies')->where('id', $companyId)->update([
            'suspended_at' => null,
            'suspended_reason' => null,
        ]);

        $admin = $request->attributes->get('super_admin');
        $this->audit->log($admin, 'company_reactivated', $request->ip(), 'company', $companyId);

        return response()->json(['message' => 'Company reactivated.']);
    }

    protected function resolveConnection(string $companyId): array
    {
        $region = DB::connection('routing_db')->table('company_region_lookup')
            ->where('company_id', $companyId)
            ->value('region');

        if (!$region) {
            return [null, null];
        }

        return [RegionResolver::connectionFor($region), $region];
    }
}
