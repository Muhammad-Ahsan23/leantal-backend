<?php

namespace App\Services;

use App\Models\Job;

class JobStructuredDataService
{
    // Our employment_type values -> Google's exact schema.org enum
    protected const EMPLOYMENT_TYPE_MAP = [
        'full_time' => 'FULL_TIME',
        'part_time' => 'PART_TIME',
        'contract' => 'CONTRACTOR',
        'temporary' => 'TEMPORARY',
        'internship' => 'INTERN',
    ];

    /**
     * PRD Section 50 — builds Google's JobPosting JSON-LD structured
     * data (schema.org/JobPosting) so the frontend can embed it directly
     * in a <script type="application/ld+json"> tag on the public job
     * page — this is what makes a job eligible for Google for Jobs
     * search results. ASSUMPTION: canonicalUrl is built from FRONTEND_URL
     * env var + a guessed route pattern, since this backend doesn't know
     * the frontend's actual routing — confirm/adjust once the frontend
     * repo's real public job URL structure is known.
     */
    public function build(Job $job, string $companySlug, string $companyName): array
    {
        $data = [
            '@context' => 'https://schema.org/',
            '@type' => 'JobPosting',
            'title' => $job->title,
            'description' => $job->description ?? $job->title,
            'datePosted' => optional($job->published_at)->toDateString(),
            'employmentType' => self::EMPLOYMENT_TYPE_MAP[$job->employment_type] ?? 'OTHER',
            'hiringOrganization' => [
                '@type' => 'Organization',
                'name' => $companyName,
            ],
            'identifier' => [
                '@type' => 'PropertyValue',
                'name' => $companyName,
                'value' => $job->id,
            ],
            'url' => $this->canonicalUrl($companySlug, $job->id),
        ];

        if ($job->location_type === 'remote') {
            $data['jobLocationType'] = 'TELECOMMUTE';
            $data['applicantLocationRequirements'] = [
                '@type' => 'Country',
                'name' => $job->location ?: 'Worldwide',
            ];
        } elseif ($job->location) {
            $data['jobLocation'] = [
                '@type' => 'Place',
                'address' => [
                    '@type' => 'PostalAddress',
                    'addressLocality' => $job->location,
                ],
            ];
        }

        if ($job->compensation_enabled && $job->compensation_value) {
            $data['baseSalary'] = [
                '@type' => 'MonetaryAmount',
                'value' => [
                    '@type' => 'QuantitativeValue',
                    'value' => $job->compensation_value,
                ],
            ];
        }

        return $data;
    }

    public function canonicalUrl(string $companySlug, string $jobId): string
    {
        $base = rtrim(config('services.frontend_url', env('FRONTEND_URL', '')), '/');

        return "{$base}/careers/{$companySlug}/jobs/{$jobId}";
    }
}
