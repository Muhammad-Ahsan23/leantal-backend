<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class ResumeStorageService
{
    /**
     * PRD Section 39 — resume upload (storage only, NOT parsing — that's
     * Section 40, currently paused pending the client's AI/no-AI
     * decision). Shared between the internal "Add Candidate" flow
     * (CandidateService) and the public application flow
     * (PublicApplicationService) — both need identical region-aware
     * storage logic, so it lives here once.
     *
     * Returns null if no file was submitted (resume is optional — PRD
     * doesn't explicitly mandate it; flagged as an assumption).
     */
    public function store(?UploadedFile $file, string $region): ?array
    {
        if (!$file) {
            return null;
        }

        $disk = RegionResolver::storageDiskFor($region);
        $path = $file->store('resumes', $disk);

        if ($path === false) {
            // With config/filesystems.php's 'throw' => true, this branch
            // should now be unreachable (a real exception fires instead,
            // with the actual R2/S3 error message) — kept as a defensive
            // fallback in case 'throw' ever gets flipped back to false.
            throw new \RuntimeException('Resume upload failed. Please check storage configuration.');
        }

        return [
            'resume_disk' => $disk,
            'resume_path' => $path,
            'resume_original_name' => $file->getClientOriginalName(),
        ];
    }
}
