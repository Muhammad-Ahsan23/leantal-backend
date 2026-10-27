<?php

namespace App\Http\Controllers\Api\SuperAdmin\Concerns;

use App\Services\RegionResolver;
use Illuminate\Support\Facades\DB;

trait ResolvesCompany
{
    /** The regional connection a company lives on, or null when the company does not exist. */
    protected function connectionFor(string $companyId): ?string
    {
        $region = DB::connection('routing_db')->table('company_region_lookup')->where('company_id', $companyId)->value('region');

        return $region ? RegionResolver::connectionFor($region) : null;
    }
}
