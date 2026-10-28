<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\RegionHealthService;

class SuperAdminRegionHealthController extends Controller
{
    public function __construct(protected RegionHealthService $health) {}

    /** Fast: reachable?, latency, company and active-user counts per region. */
    public function index()
    {
        return response()->json(['regions' => $this->health->summary()]);
    }

    /** Slower: cross-checks the routing tables against every region. Called on demand. */
    public function consistency()
    {
        $regions = $this->health->consistency();

        return response()->json([
            'regions' => $regions,
            'ok' => collect($regions)->every(fn ($r) => $r['ok']),
            'checked_at' => now()->toIso8601String(),
        ]);
    }
}
