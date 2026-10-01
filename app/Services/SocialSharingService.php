<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Job;

/**
 * PRD Section 52 — "After publishing a job, provide Share Job buttons:
 * LinkedIn, X, Threads. Prefill with share text... Editable before
 * posting."
 *
 * CONFIRMED PLATFORM LIMITATION (verified against LinkedIn's current
 * documentation, 2026): LinkedIn's share-offsite endpoint accepts ONLY
 * a url parameter — custom text pre-fill was deprecated in 2018
 * (specifically to prevent spam) and is not technically possible via
 * any public, supported LinkedIn URL. LinkedIn instead pulls its
 * preview text from the shared page's own Open Graph meta tags
 * (og:title / og:description) — so PRD's "prefill with share text" is
 * satisfied for LinkedIn only in the sense that the JOB PAGE ITSELF
 * should carry correct OG tags (a FRONTEND responsibility, using the
 * job title/description this API already returns) — not via a share
 * URL parameter, because LinkedIn has no such parameter anymore.
 * X and Threads, by contrast, DO support real text pre-fill and are
 * implemented fully as PRD describes.
 */
class SocialSharingService
{
    public function __construct(protected JobStructuredDataService $structuredData) {}

    /**
     * @return array{linkedin: string, x: string, threads: string, share_text: string, linkedin_note: string}
     */
    public function buildShareLinks(Job $job, Company $company): array
    {
        $jobUrl = $this->structuredData->canonicalUrl($company->slug, $job->id);
        $shareText = "We're hiring a {$job->title}! Apply here: {$jobUrl}";

        return [
            'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?url='.urlencode($jobUrl),
            'x' => 'https://twitter.com/intent/tweet?'.http_build_query([
                'text' => $shareText,
            ]),
            'threads' => 'https://www.threads.net/intent/post?'.http_build_query([
                'text' => $shareText,
            ]),
            // Returned so the frontend can show/let the user EDIT this
            // exact text before it's inserted into the X/Threads intent
            // URL (PRD: "Editable before posting") — doesn't apply to
            // the LinkedIn link itself (see class docblock), but is
            // still useful there as the suggested caption to paste
            // manually into LinkedIn's own commentary box.
            'share_text' => $shareText,
            'linkedin_note' => 'LinkedIn no longer supports pre-filled share text (platform limitation, removed 2018) — only the URL is passed. Ensure the job page itself has correct Open Graph tags so LinkedIn shows a good preview automatically.',
        ];
    }
}
