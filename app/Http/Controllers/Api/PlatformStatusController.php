<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PlatformStatusService;

class PlatformStatusController extends Controller
{
    /**
     * Public (no login): the app calls this to show the announcement banner and to learn when maintenance is over.
     * Cheap on purpose — it is served from a few seconds of cache.
     */
    public function show(PlatformStatusService $status)
    {
        return response()->json([
            'maintenance' => $status->maintenance(),
            'announcement' => $status->announcement(),
        ]);
    }
}
