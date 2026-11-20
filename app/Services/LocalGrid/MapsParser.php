<?php

namespace App\Services\LocalGrid;

/**
 * Reads a Google Maps SERP (DataForSEO live/advanced, `maps_search` items)
 * for one grid point (pure).
 *
 * The tracked business matches an item by place id, else by cid; a grid
 * with neither matches the site's domain (ignoring "www.") against the
 * item's domain. rank = its `rank_group` among the map results (null = not
 * in the first `depth`).
 */
final class MapsParser
{
    /**
     * @param  array{place_id: ?string, cid: ?string, domain: ?string}  $target
     * @return array{rank: ?int, top: list<array<string, mixed>>, results: list<array<string, mixed>>}
     */
    public static function parse(?array $result, array $target, int $depth): array
    {
        $results = [];
        foreach (self::items($result) as $item) {
            $rank = (int) ($item['rank_group'] ?? 0);
            if ($rank < 1 || $rank > $depth) {
                continue;
            }
            $results[] = self::listing($item, $rank);
        }
        usort($results, fn (array $a, array $b) => $a['rank'] <=> $b['rank']);

        $rank = null;
        foreach ($results as $listing) {
            if (self::matches($listing, $target)) {
                $rank = $listing['rank'];
                break;
            }
        }

        return ['rank' => $rank, 'top' => array_slice($results, 0, 3), 'results' => $results];
    }

    /**
     * Business search candidates: the map results as the create form shows them.
     *
     * @return list<array{title: string, address: ?string, place_id: ?string, cid: ?string, lat: ?float, lng: ?float, rating: ?float, votes: ?int, category: ?string, domain: ?string}>
     */
    public static function candidates(?array $result, int $limit): array
    {
        $out = [];
        foreach (self::items($result) as $item) {
            if (blank($item['title'] ?? null)) {
                continue;
            }
            $out[] = [
                'title' => (string) $item['title'],
                'address' => self::text($item['address'] ?? null),
                'place_id' => self::text($item['place_id'] ?? null),
                'cid' => self::text($item['cid'] ?? null),
                'lat' => isset($item['latitude']) ? (float) $item['latitude'] : null,
                'lng' => isset($item['longitude']) ? (float) $item['longitude'] : null,
                'rating' => isset($item['rating']['value']) ? (float) $item['rating']['value'] : null,
                'votes' => isset($item['rating']['votes_count']) ? (int) $item['rating']['votes_count'] : null,
                'category' => self::text($item['category'] ?? null),
                'domain' => self::host($item['domain'] ?? null),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** @param  array{place_id: ?string, cid: ?string, domain: ?string}  $target */
    public static function matches(array $listing, array $target): bool
    {
        if (filled($target['place_id'] ?? null)) {
            return ($listing['place_id'] ?? null) === $target['place_id'];
        }
        if (filled($target['cid'] ?? null)) {
            return ($listing['cid'] ?? null) === (string) $target['cid'];
        }
        $domain = self::host($target['domain'] ?? null);

        return $domain !== null && self::host($listing['domain'] ?? null) === $domain;
    }

    /** @return list<array<string, mixed>> */
    private static function items(?array $result): array
    {
        return array_values(array_filter($result['items'] ?? [], fn ($item) => is_array($item) && ($item['type'] ?? null) === 'maps_search'));
    }

    /** @return array{rank: int, title: string, cid: ?string, place_id: ?string, rating: ?float, votes: ?int, domain: ?string} */
    private static function listing(array $item, int $rank): array
    {
        return [
            'rank' => $rank,
            'title' => (string) ($item['title'] ?? ''),
            'cid' => self::text($item['cid'] ?? null),
            'place_id' => self::text($item['place_id'] ?? null),
            'rating' => isset($item['rating']['value']) ? (float) $item['rating']['value'] : null,
            'votes' => isset($item['rating']['votes_count']) ? (int) $item['rating']['votes_count'] : null,
            'domain' => self::host($item['domain'] ?? null),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    /** A host lower-cased without "www.", from a domain or a URL. */
    public static function host(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = mb_strtolower(trim($value));
        $host = str_contains($value, '://') ? (string) parse_url($value, PHP_URL_HOST) : explode('/', $value)[0];
        $host = preg_replace('/^www\./', '', $host);

        return $host === '' ? null : $host;
    }
}
