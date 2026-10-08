<?php

namespace App\Services;

use App\Models\Job;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * PRD Section 41 — "CSV import: Upload CSV -> Column mapping screen ->
 * Preview rows -> Confirm import -> Success/error summary."
 *
 * UPDATED DESIGN — the "Column mapping screen" now has a proper
 * backend-driven step: detectHeaders() reads the file's actual header
 * row + a few sample data rows, so the frontend can render a mapping
 * UI with visual confirmation per column (dropdown + live sample
 * value) — the user never has to count column positions by hand,
 * which was the earlier design's real weak point (a miscounted index
 * silently mapped the wrong data into the wrong field, with nothing
 * to catch it).
 *
 * The three steps now are:
 * 1. detectHeaders() — upload the file ONCE, get headers + samples
 *    back, file's raw content is cached under an upload_id.
 * 2. preview() — takes that upload_id (not a fresh file) + the
 *    mapping the user chose, validates every row.
 * 3. confirm() — unchanged, takes the batch_id from preview().
 */
class CandidateImportService
{
    protected const CACHE_TTL_MINUTES = 15;

    protected const SAMPLE_ROW_COUNT = 3;

    /**
     * The only fields CSV import actually understands — kept here as
     * the single source of truth, returned to the frontend so its
     * mapping-dropdown always matches exactly what the backend can use
     * (no risk of the two drifting out of sync).
     */
    protected const AVAILABLE_FIELDS = [
        ['key' => 'first_name', 'label' => 'First Name', 'required' => true],
        ['key' => 'last_name', 'label' => 'Last Name', 'required' => false],
        ['key' => 'email', 'label' => 'Email', 'required' => true],
        ['key' => 'phone', 'label' => 'Phone', 'required' => false],
        ['key' => 'current_title', 'label' => 'Current Title', 'required' => false],
        ['key' => 'current_company', 'label' => 'Current Company', 'required' => false],
        ['key' => 'linkedin_url', 'label' => 'LinkedIn URL', 'required' => false],
    ];

    public function __construct(protected CandidateService $candidates) {}

    /**
     * PRD Section 41 — "Column mapping screen." Reads the file ONCE
     * here; its raw content is cached (not the PHP UploadedFile object
     * itself, which can't survive past this request) so preview() can
     * re-read it later without asking the user to upload again.
     *
     * @return array{upload_id: string, headers: array, sample_rows: array, available_fields: array}
     * @throws \RuntimeException if the file is empty or unreadable
     */
    public function detectHeaders(UploadedFile $csvFile): array
    {
        $content = file_get_contents($csvFile->getRealPath());
        $allRows = $this->parseCsvContent($content);

        if (empty($allRows)) {
            throw new \RuntimeException('The CSV file appears to be empty.');
        }

        $headers = $allRows[0];
        $sampleRows = array_slice($allRows, 1, self::SAMPLE_ROW_COUNT);

        $uploadId = (string) Str::uuid();
        Cache::put("candidate_import_upload:{$uploadId}", $content, now()->addMinutes(self::CACHE_TTL_MINUTES));

        return [
            'upload_id' => $uploadId,
            'headers' => $headers,
            'sample_rows' => $sampleRows,
            'available_fields' => self::AVAILABLE_FIELDS,
        ];
    }

    /**
     * PRD Section 43 — "Validate email. Detect duplicate candidates...
     * Show invalid rows with downloadable error report. Do not
     * silently lose data." Every row ends up in EITHER valid_rows or
     * invalid_rows — never dropped without a reason.
     *
     * @param array<string, int> $columnMapping LeanTal field name => CSV column index (0-based), e.g. ['first_name' => 0, 'email' => 2]
     * @return array{batch_id: string, total: int, valid_count: int, invalid_count: int, valid_preview: array, invalid_rows: array}
     * @throws \RuntimeException if the upload_id has expired or doesn't exist
     */
    public function preview(string $uploadId, array $columnMapping): array
    {
        $content = Cache::get("candidate_import_upload:{$uploadId}");

        if ($content === null) {
            throw new \RuntimeException('This file upload has expired (15-minute limit). Please upload the CSV again.');
        }

        $allRows = $this->parseCsvContent($content);
        $rows = array_slice($allRows, 1); // skip header row — already shown in detectHeaders()

        $validRows = [];
        $invalidRows = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +1 for 0-index, +1 for header row
            $data = $this->mapRow($row, $columnMapping);

            $error = $this->validateRow($data);
            if ($error) {
                $invalidRows[] = ['row' => $rowNumber, 'data' => $data, 'reason' => $error];
                continue;
            }

            $validRows[] = $data;
        }

        $batchId = (string) Str::uuid();
        Cache::put("candidate_import_batch:{$batchId}", $validRows, now()->addMinutes(self::CACHE_TTL_MINUTES));

        return [
            'batch_id' => $batchId,
            'total' => count($rows),
            'valid_count' => count($validRows),
            'invalid_count' => count($invalidRows),
            // Full data for EVERY invalid row — this IS the "downloadable
            // error report" PRD asks for; the frontend can offer it as a
            // CSV download directly from this JSON.
            'invalid_rows' => $invalidRows,
            'valid_preview' => array_slice($validRows, 0, 10),
        ];
    }

    /**
     * @return array{created: int, skipped_duplicates: int, errors: array}
     * @throws \RuntimeException if the batch has expired or doesn't exist
     */
    public function confirm(string $batchId, ?Job $job, User $actor, string $connection): array
    {
        $validRows = Cache::get("candidate_import_batch:{$batchId}");

        if ($validRows === null) {
            throw new \RuntimeException('This import preview has expired. Please re-upload and preview again.');
        }

        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach ($validRows as $row) {
            $data = [
                'name' => trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? '')),
                'email' => $row['email'],
                'phone' => $row['phone'] ?? null,
                'current_title' => $row['current_title'] ?? null,
                'current_company' => $row['current_company'] ?? null,
                'linkedin_url' => $row['linkedin_url'] ?? null,
            ];

            try {
                if ($job) {
                    $this->candidates->addToJob($data, $job, $actor, $connection);
                } else {
                    // No job chosen: candidate only (PRD Section 32). An email that already exists in
                    // this company is skipped and reported, same as a duplicate application below.
                    $this->candidates->createWithoutJob($data, $actor, $connection);
                }
                $created++;
            } catch (\RuntimeException $e) {
                // With a job: "already has an active application for this job" (PRD Section 135).
                // Without one: "A candidate with this email already exists." (Sections 43/133).
                $skipped++;
                $errors[] = ['email' => $row['email'], 'reason' => $e->getMessage()];
            }
        }

        Cache::forget("candidate_import_batch:{$batchId}");

        return ['created' => $created, 'skipped_duplicates' => $skipped, 'errors' => $errors];
    }

    /**
     * Parses from a raw CSV STRING (not a file path) — shared by both
     * detectHeaders() (reading the just-uploaded file) and preview()
     * (re-reading the cached content). Uses a real stream + fgetcsv()
     * rather than naive string-splitting on newlines, so quoted fields
     * containing commas or literal newlines are still parsed correctly.
     */
    protected function parseCsvContent(string $content): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    protected function mapRow(array $row, array $columnMapping): array
    {
        $data = [];
        foreach ($columnMapping as $field => $columnIndex) {
            $data[$field] = isset($row[$columnIndex]) ? trim($row[$columnIndex]) : null;
        }

        return $data;
    }

    /**
     * PRD Section 43 — "Validate email." first_name is required to
     * form a meaningful candidate name — email is required as the
     * dedup key itself.
     */
    protected function validateRow(array $data): ?string
    {
        if (empty($data['email'])) {
            return 'Email is required.';
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return "Invalid email format: {$data['email']}";
        }

        if (empty($data['first_name'])) {
            return 'First name is required.';
        }

        return null;
    }
}
