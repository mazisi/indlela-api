<?php

namespace App\Services\SiteAudit;

/**
 * How the crawler gets pages. HttpPageFetcher fetches raw HTML with Laravel's
 * Http client (no JavaScript); a renderer (e.g. Browsershot) can implement
 * this later for sites that build their content in the browser.
 */
interface PageFetcher
{
    /**
     * Fetch each URL once, without following redirects, a few at a time.
     *
     * @param  array<int|string, string>  $urls
     * @return array<int|string, FetchResult> keyed like $urls
     */
    public function fetch(array $urls, string $method = 'GET'): array;

    /**
     * The final status of each URL, following redirects: HEAD first, GET
     * when HEAD fails or is refused. 0 = no response.
     *
     * @param  array<int|string, string>  $urls
     * @return array<int|string, int> keyed like $urls
     */
    public function statuses(array $urls): array;
}
