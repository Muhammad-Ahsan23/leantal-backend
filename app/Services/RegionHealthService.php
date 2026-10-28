<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read-only health of the three regional databases and of the routing tables that point customers at them.
 * Nothing here writes: it only reports, so a Super Admin can see a problem before a customer does.
 */
class RegionHealthService
{
    /** region => connection name */
    public const REGIONS = ['us' => 'pgsql_us', 'eu' => 'pgsql_eu', 'uk' => 'pgsql_uk'];

    protected const CHUNK = 1000;

    protected const SAMPLES = 10;

    /** Round-trip time in milliseconds, or null when the database cannot be reached. */
    public function ping(string $connection): ?int
    {
        try {
            $start = microtime(true);
            DB::connection($connection)->select('select 1');

            return (int) round((microtime(true) - $start) * 1000);
        } catch (Throwable $e) {
            Log::warning("Region database {$connection} unreachable: ".$e->getMessage());

            return null;
        }
    }

    /** Per region: reachable?, latency, companies and active users. The error text is logged, never returned. */
    public function summary(): array
    {
        $rows = [];
        foreach (self::REGIONS as $region => $connection) {
            $latency = $this->ping($connection);
            $row = ['region' => $region, 'status' => $latency === null ? 'down' : 'ok', 'latency_ms' => $latency, 'companies' => null, 'active_users' => null];

            if ($latency !== null) {
                $db = DB::connection($connection);
                $row['companies'] = $db->table('companies')->where('region', $region)->count();
                $row['active_users'] = $db->table('users')->where('status', 'active')
                    ->whereIn('company_id', fn ($q) => $q->select('id')->from('companies')->where('region', $region))->count();
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Do the routing tables agree with the regional databases? Three checks per region:
     *  - companies_not_routable: a company exists but the routing table lacks it or names another region (its careers page and logins would fail)
     *  - orphan_lookups: the routing table points at a company that no longer exists in that region
     *  - users_without_login_route: an active user whose email is missing from the login routing table, or points elsewhere (they cannot log in)
     * Counts are exact; `samples` lists at most 10 examples. Walks the data in chunks, so run it on demand, not on every page load.
     */
    public function consistency(): array
    {
        $routing = DB::connection('routing_db');
        $result = [];

        foreach (self::REGIONS as $region => $connection) {
            if ($this->ping($connection) === null) {
                $result[] = ['region' => $region, 'status' => 'down', 'ok' => false, 'checks' => []];

                continue;
            }
            $db = DB::connection($connection);

            $notRoutable = $this->newCheck('companies_not_routable', 'Companies missing from, or wrongly listed in, the routing table');
            $db->table('companies')->where('region', $region)->select('id')->chunkById(self::CHUNK, function ($companies) use ($routing, $region, &$notRoutable) {
                $routed = $routing->table('company_region_lookup')->whereIn('company_id', $companies->pluck('id'))->pluck('region', 'company_id');
                foreach ($companies as $company) {
                    if (($routed[$company->id] ?? null) !== $region) {
                        $this->add($notRoutable, $company->id);
                    }
                }
            });

            $orphans = $this->newCheck('orphan_lookups', 'Routing entries whose company no longer exists');
            $routing->table('company_region_lookup')->where('region', $region)->select('company_id')->chunkById(self::CHUNK, function ($lookups) use ($db, &$orphans) {
                $existing = array_flip($db->table('companies')->whereIn('id', $lookups->pluck('company_id'))->pluck('id')->all());
                foreach ($lookups as $lookup) {
                    if (!isset($existing[$lookup->company_id])) {
                        $this->add($orphans, $lookup->company_id);
                    }
                }
            }, 'company_id');

            $noLogin = $this->newCheck('users_without_login_route', 'Active users who cannot log in (email not routed to their company)');
            $db->table('users')->join('companies', 'companies.id', '=', 'users.company_id')
                ->where('companies.region', $region)->where('users.status', 'active')
                ->select('users.id', 'users.email', 'users.company_id')
                ->chunkById(self::CHUNK, function ($users) use ($routing, $region, &$noLogin) {
                    $routed = $routing->table('user_email_region_lookup')
                        ->whereIn('email', $users->map(fn ($u) => strtolower(trim($u->email)))->all())->get()->keyBy('email');
                    foreach ($users as $user) {
                        $row = $routed[strtolower(trim($user->email))] ?? null;
                        if (!$row || $row->company_id !== $user->company_id || $row->region !== $region) {
                            $this->add($noLogin, $user->email);
                        }
                    }
                }, 'users.id', 'id');

            $checks = [$notRoutable, $orphans, $noLogin];
            $result[] = ['region' => $region, 'status' => 'ok', 'ok' => collect($checks)->sum('count') === 0, 'checks' => $checks];
        }

        return $result;
    }

    protected function newCheck(string $key, string $label): array
    {
        return ['key' => $key, 'label' => $label, 'count' => 0, 'samples' => []];
    }

    protected function add(array &$check, string $sample): void
    {
        $check['count']++;
        if (count($check['samples']) < self::SAMPLES) {
            $check['samples'][] = $sample;
        }
    }
}
