<?php

namespace App\Support;

use RuntimeException;

/**
 * The ONE place that builds links into the web app (password reset, user invitation, ...).
 *
 * PRD Section 15: everyone logs in at app.leantal.com, so every emailed link must point at the real
 * frontend (FRONTEND_URL in .env -> config('services.frontend_url')). There is deliberately NO
 * fallback: a silent "http://localhost:5173" default is how emails once went out with dead links.
 * If it is not configured we fail loudly instead.
 */
class FrontendUrl
{
    /**
     * @param  array<string,string>  $query  values are URL-encoded here, so '+' or '&' in an email
     *                                       address (john+test@acme.com) survives the round trip.
     *
     * @throws RuntimeException when FRONTEND_URL is missing or not an http(s) URL
     */
    public static function to(string $path, array $query = []): string
    {
        $base = rtrim((string) config('services.frontend_url'), '/');

        if ($base === '' || !preg_match('#^https?://[^/\s]+#i', $base)) {
            throw new RuntimeException('FRONTEND_URL is not configured, so a link into the app cannot be built.');
        }

        $url = $base.'/'.ltrim($path, '/');

        return $query ? $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : $url;
    }
}
