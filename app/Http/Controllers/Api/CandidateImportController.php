<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Candidates\ConfirmImportRequest;
use App\Http\Requests\Candidates\DetectCsvHeadersRequest;
use App\Http\Requests\Candidates\PreviewImportRequest;
use App\Models\Job;
use App\Services\CandidateImportService;

class CandidateImportController extends Controller
{
    public function __construct(protected CandidateImportService $import) {}

    /**
     * PRD Section 41 — "Column mapping screen." Step 1: upload the
     * file, get back its headers + a few sample data rows, so the
     * frontend can render a dropdown-per-column mapping UI with a
     * live sample value next to each — the user confirms visually
     * rather than counting column positions by hand.
     */
    public function headers(DetectCsvHeadersRequest $request)
    {
        try {
            $result = $this->import->detectHeaders($request->file('csv_file'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    /**
     * PRD Section 41 — "Preview rows" step. Takes the upload_id from
     * headers() (above) + the mapping the user chose — the file
     * itself is NOT re-uploaded here, it's already cached server-side.
     */
    public function preview(PreviewImportRequest $request)
    {
        $data = $request->validated();

        try {
            $result = $this->import->preview($data['upload_id'], $data['column_mapping']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    /**
     * PRD Section 41 — "Confirm import -> Success/error summary."
     */
    public function confirm(ConfirmImportRequest $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $data = $request->validated();

        $job = Job::on($connection)->find($data['job_id']);
        if (!$job) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        if (!$user->can('update', $job)) {
            return response()->json(['message' => 'You do not have permission to add candidates to this job.'], 403);
        }

        try {
            $summary = $this->import->confirm($data['batch_id'], $job, $user, $connection);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($summary);
    }
}
