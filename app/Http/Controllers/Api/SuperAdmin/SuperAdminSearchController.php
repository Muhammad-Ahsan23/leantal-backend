<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminSearchController extends Controller
{
    protected const REGIONS = ['us' => 'pgsql_us', 'eu' => 'pgsql_eu', 'uk' => 'pgsql_uk'];

    /**
     * PRD Section 158 — "Search platform data by Company Name, Owner
     * Email, User Email, Candidate Email, or Job Title." One query
     * term checked against all 5 fields, across all 3 regions.
     */
    public function index(Request $request)
    {
        $request->validate(['q' => ['required', 'string', 'min:2']]);
        $term = $request->query('q');
        $like = "%{$term}%";

        $results = ['companies' => collect(), 'users' => collect(), 'candidates' => collect(), 'jobs' => collect()];

        foreach (self::REGIONS as $region => $connection) {
            $companies = DB::connection($connection)->table('companies')
                ->where('name', 'ilike', $like)
                ->limit(10)
                ->get(['id', 'name', 'plan', 'subscription_status']);
            foreach ($companies as $c) {
                $c->region = $region;
                $results['companies']->push($c);
            }

            $users = DB::connection($connection)->table('users')
                ->where('email', 'ilike', $like)
                ->limit(10)
                ->get(['id', 'name', 'email', 'role', 'company_id']);
            foreach ($users as $u) {
                $u->region = $region;
                $results['users']->push($u);
            }

            $candidates = DB::connection($connection)->table('candidates')
                ->where('email', 'ilike', $like)
                ->whereNull('deleted_at')
                ->limit(10)
                ->get(['id', 'name', 'email', 'company_id']);
            foreach ($candidates as $c) {
                $c->region = $region;
                $results['candidates']->push($c);
            }

            $jobs = DB::connection($connection)->table('jobs')
                ->where('title', 'ilike', $like)
                ->limit(10)
                ->get(['id', 'title', 'status', 'company_id']);
            foreach ($jobs as $j) {
                $j->region = $region;
                $results['jobs']->push($j);
            }
        }

        return response()->json($results);
    }
}
