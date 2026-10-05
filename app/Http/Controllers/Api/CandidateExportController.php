<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CandidateExportService;
use Illuminate\Http\Request;

class CandidateExportController extends Controller
{
    public function __construct(protected CandidateExportService $export) {}

    /**
     * BUG FIX (audit finding) — PRD Section 42 is explicit: "Users
     * WITH PERMISSION (Owner) can export candidate data to CSV." The
     * previous version only required authentication, relying on
     * visibleTo() row-scoping alone — that's correct for WHAT rows
     * appear, but missed that the ENDPOINT itself is Owner-only per
     * Section 42's own parenthetical. Section 101's "permission-
     * controlled" phrasing is this same rule restated more vaguely —
     * Section 42 is the one that actually names the role.
     */
    public function export(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'owner') {
            return response()->json(['message' => 'Only the Owner can export candidate data.'], 403);
        }

        $connection = $user->getConnectionName();

        $csv = $this->export->export($user, $connection);
        $filename = 'candidates-export-'.now()->format('Y-m-d').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
