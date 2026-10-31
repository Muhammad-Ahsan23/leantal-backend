<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\PublicJobFeedService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Public XML for job boards and search engines (no login). Short-lived cache so a busy crawler cannot hammer the DB. */
class PublicJobFeedController extends Controller
{
    public function __construct(protected PublicJobFeedService $feeds) {}

    /** GET /public/careers/{slug}/feed.xml[?source=jooble] */
    public function feed(Request $request, string $companySlug)
    {
        $company = $this->feeds->company($companySlug);
        if (!$company) {
            return response('Careers page not found.', 404);
        }
        $source = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $request->query('source', ''))) ?: null;
        $source = $source ? substr($source, 0, 30) : null;
        $xml = Cache::remember("job_feed:{$companySlug}:".($source ?? '-'), 600, fn () => $this->feeds->feedXml($company, $source));

        return $this->xml($xml);
    }

    /** GET /public/careers/{slug}/sitemap.xml */
    public function companySitemap(string $companySlug)
    {
        $company = $this->feeds->company($companySlug);
        if (!$company) {
            return response('Careers page not found.', 404);
        }

        return $this->xml(Cache::remember("job_sitemap:{$companySlug}", 600, fn () => $this->feeds->companySitemap($company)));
    }

    /** GET /public/sitemap.xml — all companies. */
    public function platformSitemap()
    {
        return $this->xml($this->feeds->platformSitemap());
    }

    protected function xml(string $body)
    {
        return response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=600']);
    }
}
