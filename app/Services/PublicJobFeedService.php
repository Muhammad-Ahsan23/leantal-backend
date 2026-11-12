<?php

namespace App\Services;

use App\Models\Job;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use XMLWriter;

/**
 * Builds the two public, crawler-facing documents for job boards and search engines:
 *  - an XML job feed per company (Indeed-style <source><job> format, accepted by most free job aggregators on request)
 *  - sitemaps (per company, and one for the whole platform) listing every published job
 * Only published jobs of companies that are neither suspended nor deleted are ever included.
 */
class PublicJobFeedService
{
    protected const MAX_SITEMAP_URLS = 45000;

    protected const JOB_TYPES = [
        'full_time' => 'fulltime',
        'part_time' => 'parttime',
        'contract' => 'contract',
        'temporary' => 'temporary',
        'internship' => 'internship',
    ];

    public function __construct(
        protected RegionRoutingRepository $routing,
        protected JobStructuredDataService $urls,
    ) {}

    /** Company row (name, website, country, slug...) or null when the slug is unknown, suspended or deleted. */
    public function company(string $slug): ?array
    {
        $found = $this->routing->findCompanyBySlug($slug);
        if (!$found) {
            return null;
        }
        $connection = RegionResolver::connectionFor($found['region']);
        $row = DB::connection($connection)->table('companies')->where('id', $found['company_id'])->first(['id', 'name', 'website', 'country_code', 'suspended_at', 'deleted_at']);
        if (!$row || $row->suspended_at || $row->deleted_at) {
            return null;
        }

        return ['connection' => $connection, 'id' => $row->id, 'name' => $row->name, 'website' => $row->website, 'country' => $row->country_code, 'slug' => $slug];
    }

    /** $source is appended as ?source=... to each job URL so applications can be attributed to the board. */
    public function feedXml(array $company, ?string $source = null): string
    {
        $jobs = Job::on($company['connection'])
            ->where('company_id', $company['id'])
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->get(['id', 'title', 'department', 'description', 'about_role', 'key_responsibilities', 'requirements', 'location', 'location_type', 'employment_type', 'published_at', 'updated_at']);

        $w = new XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement('source');
        $this->el($w, 'publisher', $company['name']);
        $this->el($w, 'publisherurl', $this->urls->careersPageUrl($company['slug']));
        $this->el($w, 'lastBuildDate', now()->utc()->format('D, d M Y H:i:s').' GMT');

        foreach ($jobs as $job) {
            $url = $this->urls->canonicalUrl($company['slug'], $job->id);
            if ($source) {
                $url .= '?source='.rawurlencode($source);
            }
            $w->startElement('job');
            $this->el($w, 'title', $job->title);
            $this->el($w, 'date', optional($job->published_at ?? $job->updated_at)->utc()->format('D, d M Y H:i:s').' GMT');
            $this->el($w, 'referencenumber', $job->id);
            $this->el($w, 'url', $url);
            $this->el($w, 'company', $company['name']);
            if ($job->location_type !== 'remote' && $job->location) {
                // Location is free text in LeanTal, so it goes into <city> as written; country comes from the company.
                $this->el($w, 'city', $job->location);
            }
            if (preg_match('/^[A-Za-z]{2}$/', (string) $company['country'])) {
                $this->el($w, 'country', strtoupper($company['country']));
            }
            $this->el($w, 'description', $job->fullDescription() ?: $job->title);
            if (isset(self::JOB_TYPES[$job->employment_type])) {
                $this->el($w, 'jobtype', self::JOB_TYPES[$job->employment_type]);
            }
            if ($job->department) {
                $this->el($w, 'category', $job->department);
            }
            if ($job->location_type === 'remote') {
                $this->el($w, 'remotetype', 'Fully Remote');
            }
            $w->endElement();
        }

        $w->endElement();
        $w->endDocument();

        return $w->outputMemory();
    }

    public function companySitemap(array $company): string
    {
        $entries = [['loc' => $this->urls->careersPageUrl($company['slug']), 'lastmod' => null]];
        $jobs = Job::on($company['connection'])->where('company_id', $company['id'])->where('status', 'published')->orderByDesc('published_at')->get(['id', 'updated_at']);
        foreach ($jobs as $job) {
            $entries[] = ['loc' => $this->urls->canonicalUrl($company['slug'], $job->id), 'lastmod' => $job->updated_at];
        }

        return $this->sitemapXml($entries);
    }

    /** Every published job of every active company, all regions. Cached for an hour; capped at the sitemap limit. */
    public function platformSitemap(): string
    {
        return Cache::remember('public_sitemap_all', 3600, function () {
            $entries = [];
            $lookup = DB::connection('routing_db')->table('company_region_lookup')->get(['company_id', 'company_slug', 'region'])->groupBy('region');

            foreach ($lookup as $region => $rows) {
                $connection = RegionResolver::connectionFor($region);
                $slugs = $rows->pluck('company_slug', 'company_id');
                $active = DB::connection($connection)->table('companies')->whereIn('id', $slugs->keys())->whereNull('suspended_at')->whereNull('deleted_at')->pluck('id');

                foreach ($active as $companyId) {
                    $slug = $slugs[$companyId];
                    $entries[] = ['loc' => $this->urls->careersPageUrl($slug), 'lastmod' => null];
                }
                Job::on($connection)->whereIn('company_id', $active)->where('status', 'published')->orderBy('id')
                    ->chunk(1000, function ($jobs) use (&$entries, $slugs) {
                        foreach ($jobs as $job) {
                            $entries[] = ['loc' => $this->urls->canonicalUrl($slugs[$job->company_id], $job->id), 'lastmod' => $job->updated_at];
                        }
                    });
            }

            return $this->sitemapXml(array_slice($entries, 0, self::MAX_SITEMAP_URLS));
        });
    }

    protected function sitemapXml(array $entries): string
    {
        $w = new XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement('urlset');
        $w->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        foreach ($entries as $e) {
            $w->startElement('url');
            $this->el($w, 'loc', $e['loc']);
            if ($e['lastmod']) {
                $this->el($w, 'lastmod', $e['lastmod']->utc()->toIso8601String());
            }
            $w->endElement();
        }
        $w->endElement();
        $w->endDocument();

        return $w->outputMemory();
    }

    /** Text element; characters that are not allowed in XML 1.0 are removed so one bad job never breaks the whole feed. */
    protected function el(XMLWriter $w, string $name, ?string $value): void
    {
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value) ?? '';
        $w->startElement($name);
        // CDATA keeps HTML descriptions readable; a literal "]]>" inside the text is split so it cannot end the section early.
        $w->writeRaw('<![CDATA['.str_replace(']]>', ']]]]><![CDATA[>', $clean).']]>');
        $w->endElement();
    }
}
