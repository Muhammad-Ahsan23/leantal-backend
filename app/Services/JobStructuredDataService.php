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
     * PRD Section 50 — builds Google's JobPosting JSON-LD (schema.org/JobPosting) so the public job page can embed it
     * in a <script type="application/ld+json"> tag; this is what makes a job eligible for Google for Jobs.
     *
     * $countryCode (the company's ISO country) fills the required addressCountry of the job location; $website is
     * sent as hiringOrganization.sameAs. Both are optional so older callers keep working.
     *
     * baseSalary is deliberately NOT emitted: Google requires a currency and a numeric value, and LeanTal stores
     * compensation as free text without a currency. A half-filled baseSalary makes the whole posting invalid, which
     * is worse than leaving it out.
     */
    public function build(Job $job, string $companySlug, string $companyName, ?string $countryCode = null, ?string $website = null): array
    {
        $data = [
            '@context' => 'https://schema.org/',
            '@type' => 'JobPosting',
            'title' => $job->title,
            'description' => $this->htmlDescription($job->description ?: $job->title),
            'datePosted' => optional($job->published_at)->toDateString(),
            'employmentType' => self::EMPLOYMENT_TYPE_MAP[$job->employment_type] ?? 'OTHER',
            'hiringOrganization' => array_filter([
                '@type' => 'Organization',
                'name' => $companyName,
                'sameAs' => $website,
            ]),
            'identifier' => [
                '@type' => 'PropertyValue',
                'name' => $companyName,
                'value' => $job->id,
            ],
            'directApply' => true, // candidates apply on the LeanTal page itself
            'url' => $this->canonicalUrl($companySlug, $job->id),
        ];

        $country = $this->countryCode($countryCode);

        if ($job->location_type === 'remote') {
            $data['jobLocationType'] = 'TELECOMMUTE';
            $data['applicantLocationRequirements'] = [
                '@type' => 'Country',
                'name' => $job->location ?: 'Worldwide',
            ];
        } elseif ($job->location) {
            $data['jobLocation'] = [
                '@type' => 'Place',
                'address' => array_filter([
                    '@type' => 'PostalAddress',
                    'addressLocality' => $job->location,
                    'addressCountry' => $country,
                ]),
            ];
        }

        return $data;
    }

    /** The public page of one job. Uses CAREERS_URL (jobs.leantal.com) when configured, else the in-app route. */
    public function canonicalUrl(string $companySlug, string $jobId): string
    {
        return $this->careersBase($companySlug).'/jobs/'.$jobId;
    }

    /** The company's public careers page. */
    public function careersPageUrl(string $companySlug): string
    {
        return $this->careersBase($companySlug);
    }

    protected function careersBase(string $companySlug): string
    {
        $careers = rtrim((string) config('services.careers_url'), '/');
        if ($careers !== '') {
            return "{$careers}/{$companySlug}";
        }

        $base = rtrim((string) config('services.frontend_url', env('FRONTEND_URL', '')), '/');

        return "{$base}/careers/{$companySlug}";
    }

    /** Google wants HTML in description; plain text (blank-line paragraphs) is turned into simple <p> blocks. */
    protected function htmlDescription(string $text): string
    {
        if (preg_match('/<\s*(p|br|ul|ol|li|h[1-6]|div|strong|b|em)\b/i', $text)) {
            return $text;
        }
        $paragraphs = preg_split('/\R{2,}/', trim($text)) ?: [];

        return implode('', array_map(fn ($p) => '<p>'.nl2br(e(trim($p))).'</p>', array_filter($paragraphs, fn ($p) => trim($p) !== '')));
    }

    protected function countryCode(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        return preg_match('/^[A-Z]{2}$/', $code) ? $code : null;
    }
}
