<?php

namespace App\Services\RankTracking;

/**
 * Reads one DataForSEO "advanced" Google organic result for a site.
 *
 * - The site is matched by host: the item's host without `www.` equals the
 *   site's domain or is a subdomain of it (blog.example.co.za counts for
 *   example.co.za; the apex doesn't count for a site that is a subdomain).
 * - position = the best organic `rank_group` of the site's URLs within the
 *   depth (organic results only: ads, snippets and packs don't count);
 *   url = that item's URL; matching_urls = every distinct site URL among the
 *   organic results with its best position (two or more = cannibalisation).
 * - serp_features: whether each feature is on the page, and for the featured
 *   snippet, map pack (local_pack) and AI overview whether the site is in it
 *   (any URL or domain inside the element, e.g. an AI overview's references).
 *   AI overviews come as a top-level `ai_overview` item or nested in a
 *   knowledge graph (`knowledge_graph_ai_overview_item`).
 * - organic_domains: every organic result's host by position (index i =
 *   position i + 1, bare host, null where the depth has no organic item;
 *   trailing gaps dropped), for competitor tracking. [] when nothing ranks.
 */
final class SerpParser
{
    /** Features with an "ours" flag. */
    public const OWNABLE = ['featured_snippet', 'local_pack', 'ai_overview'];

    /** Features recorded as present / absent only. */
    public const PRESENCE = ['people_also_ask', 'video', 'images', 'top_stories'];

    /**
     * @param  array<string, mixed>|null  $result  tasks[0].result[0]
     * @return array{position: ?int, url: ?string, matching_urls: list<array{url: string, position: int}>, serp_features: array<string, array<string, bool>>, result_count: ?int, organic_domains: list<?string>}
     */
    public static function parse(?array $result, string $siteDomain, int $depth = 100): array
    {
        $domain = self::bareHost($siteDomain);
        $features = [];
        foreach (self::OWNABLE as $f) {
            $features[$f] = ['present' => false, 'ours' => false];
        }
        foreach (self::PRESENCE as $f) {
            $features[$f] = ['present' => false];
        }

        /** @var array<string, int> $urls url => best position */
        $urls = [];
        /** @var array<int, string> $hosts position => host (the first organic item there) */
        $hosts = [];
        foreach ($result['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? '');

            if ($type === 'organic') {
                $rank = (int) ($item['rank_group'] ?? 0);
                $url = (string) ($item['url'] ?? '');
                if ($rank >= 1 && $rank <= $depth && $url !== '' && self::isOurs($item['domain'] ?? null, $url, $domain)) {
                    $urls[$url] = min($urls[$url] ?? PHP_INT_MAX, $rank);
                }
                $host = self::hostOf($item['domain'] ?? null, $url);
                if ($rank >= 1 && $rank <= $depth && $host !== '' && ! isset($hosts[$rank])) {
                    $hosts[$rank] = $host;
                }

                continue;
            }

            $aiOverview = $type === 'ai_overview' ? $item : self::nestedAiOverview($item);
            if ($aiOverview !== null) {
                $features['ai_overview']['present'] = true;
                $features['ai_overview']['ours'] = $features['ai_overview']['ours'] || self::mentions($aiOverview, $domain);
            }

            if (in_array($type, ['featured_snippet', 'local_pack'], true)) {
                $features[$type]['present'] = true;
                $features[$type]['ours'] = $features[$type]['ours'] || self::mentions($item, $domain);
            } elseif (in_array($type, self::PRESENCE, true)) {
                $features[$type]['present'] = true;
            }
        }

        asort($urls);
        $matching = [];
        foreach ($urls as $url => $position) {
            $matching[] = ['url' => $url, 'position' => $position];
        }

        $count = $result['se_results_count'] ?? null;
        $organic = [];
        for ($rank = 1; $rank <= ($hosts === [] ? 0 : max(array_keys($hosts))); $rank++) {
            $organic[] = $hosts[$rank] ?? null;
        }

        return [
            'position' => $matching[0]['position'] ?? null,
            'url' => $matching[0]['url'] ?? null,
            'matching_urls' => $matching,
            'serp_features' => $features,
            'result_count' => is_numeric($count) ? (int) $count : null,
            'organic_domains' => $organic,
        ];
    }

    /** "WWW.Example.co.za." → "example.co.za". */
    public static function bareHost(string $host): string
    {
        return preg_replace('/^www\./', '', strtolower(rtrim(trim($host), '.')));
    }

    private static function isOurs(mixed $itemDomain, string $url, string $domain): bool
    {
        $host = self::hostOf($itemDomain, $url);
        if ($host === '' || $domain === '') {
            return false;
        }

        return self::matches($host, $domain);
    }

    /** A bare host is the domain or a subdomain of it. */
    public static function matches(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    /** An item's bare host: its `domain`, else its URL's host ('' when neither). */
    private static function hostOf(mixed $itemDomain, string $url): string
    {
        $host = is_string($itemDomain) && $itemDomain !== '' ? $itemDomain : (string) parse_url($url, PHP_URL_HOST);

        return $host === '' ? '' : self::bareHost($host);
    }

    /** Any `url` or `domain` anywhere inside $element points at the site. */
    private static function mentions(array $element, string $domain): bool
    {
        foreach ($element as $key => $value) {
            if (is_array($value)) {
                if (self::mentions($value, $domain)) {
                    return true;
                }
            } elseif (is_string($value) && $value !== '') {
                if ($key === 'domain' && self::isOurs($value, '', $domain)) {
                    return true;
                }
                if ($key === 'url' && self::isOurs(null, $value, $domain)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** A knowledge graph's AI overview part, if it has one. */
    private static function nestedAiOverview(array $item): ?array
    {
        foreach ($item['items'] ?? [] as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'knowledge_graph_ai_overview_item') {
                return $child;
            }
        }

        return null;
    }
}
