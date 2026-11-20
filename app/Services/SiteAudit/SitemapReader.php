<?php

namespace App\Services\SiteAudit;

use DOMDocument;
use DOMXPath;

/**
 * Finds a site's XML sitemaps and lists their page URLs: the robots.txt
 * Sitemap: lines, else /sitemap.xml, /sitemap_index.xml (Yoast, Rank Math)
 * and /wp-sitemap.xml (WordPress core). Sitemap indexes are followed;
 * gzipped files are unpacked. Capped by `audit.max_sitemaps` files and
 * `audit.max_sitemap_urls` URLs; only URLs of the audited site are kept.
 */
class SitemapReader
{
    private const FALLBACKS = ['/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml'];

    public function __construct(private readonly PageFetcher $fetcher) {}

    /**
     * @return array{found: bool, sitemaps: list<string>, urls: array<string, string>} urls: hash => normalised URL
     */
    public function read(string $origin, string $siteHost, RobotsTxt $robots): array
    {
        $maxFiles = (int) config('indlela.audit.max_sitemaps');
        $maxUrls = (int) config('indlela.audit.max_sitemap_urls');

        $declared = array_values(array_filter(array_map(fn (string $u) => CrawlUrl::normalise($u), $robots->sitemaps)));
        $queue = $declared ?: array_map(fn (string $path) => $origin.$path, self::FALLBACKS);
        $tryAll = $declared === [];

        $read = [];
        $urls = [];
        $found = [];

        while ($queue !== [] && count($read) < $maxFiles && count($urls) < $maxUrls) {
            $sitemap = array_shift($queue);
            if (isset($read[$sitemap])) {
                continue;
            }
            $read[$sitemap] = true;

            $entries = $this->entries($sitemap);
            if ($entries === null) {
                continue;
            }
            $found[] = $sitemap;
            [$isIndex, $locs] = $entries;

            foreach ($locs as $loc) {
                $url = CrawlUrl::normalise($loc);
                if ($url === null) {
                    continue;
                }
                if ($isIndex) {
                    // Image and video sitemaps list media files, not pages.
                    if (! preg_match('#(image|video)[-_]?sitemap#i', $url)) {
                        $queue[] = $url;
                    }
                } elseif (CrawlUrl::isSameSite($url, $siteHost) && count($urls) < $maxUrls) {
                    $urls[CrawlUrl::hash($url)] = $url;
                }
            }

            // Fallbacks: the first that works is the site's sitemap (the others usually redirect to it).
            if ($tryAll) {
                $queue = array_values(array_filter($queue, fn (string $u) => ! in_array(parse_url($u, PHP_URL_PATH), self::FALLBACKS, true)));
                $tryAll = false;
            }
        }

        return ['found' => $found !== [], 'sitemaps' => $found, 'urls' => $urls];
    }

    /**
     * [is index, <loc> values], or null when the URL isn't a readable sitemap.
     * Follows up to 3 redirects (e.g. Rank Math's /sitemap.xml → /sitemap_index.xml).
     *
     * @return array{0: bool, 1: list<string>}|null
     */
    private function entries(string $url): ?array
    {
        $result = null;
        for ($hop = 0; $hop <= 3; $hop++) {
            $result = $this->fetcher->fetch([$url])[0];
            if (! $result->isRedirect()) {
                break;
            }
            $url = CrawlUrl::normalise((string) $result->header('location'), $url) ?? $url;
        }

        if ($result === null || $result->status !== 200 || $result->body === null) {
            return null;
        }

        $body = $result->body;
        if (str_starts_with($body, "\x1f\x8b")) {
            $body = @gzdecode($body) ?: '';
        }
        if (! str_contains($body, '<urlset') && ! str_contains($body, '<sitemapindex')) {
            return null;
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($body, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $ok || $doc->documentElement === null) {
            return null;
        }

        $locs = [];
        foreach ((new DOMXPath($doc))->query("//*[local-name()='loc']") as $node) {
            $locs[] = trim($node->textContent);
        }

        return [$doc->documentElement->localName === 'sitemapindex', $locs];
    }
}
