<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityController extends Controller
{
    /**
     * PRD Section 68 — Settings > Activity tab: "Scoped activity log."
     * ASSUMPTION on what "scoped" means (PRD doesn't spell it out here):
     * Owner/HM see the full company-wide feed; Recruiters see only
     * activity THEY personally performed — same least-privilege pattern
     * used everywhere else in the PRD for Recruiter visibility.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        $query = DB::connection($connection)->table('activity')
            ->where('company_id', $user->company_id);

        if (!in_array($user->role, Roles::MANAGEMENT, true)) {
            $query->where('actor_id', $user->id);
        }

        if ($action = $request->query('action')) {
            $query->where('action', 'like', $action.'%');
        }
        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to);
        }

        $activity = $query->orderByDesc('created_at')->limit(200)->get();

        return response()->json(['activity' => $activity]);
    }
}
