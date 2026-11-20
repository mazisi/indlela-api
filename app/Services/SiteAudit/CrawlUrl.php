<?php

namespace App\Services\SiteAudit;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;

/**
 * URL handling for the crawler: resolve a link against its page, normalise
 * it (lower-case scheme and host, no default port, no fragment, dot segments
 * removed, an empty path becomes "/", tracking parameters dropped; parameter
 * order is kept), and decide whether it belongs to the audited site.
 */
final class CrawlUrl
{
    /** Query parameters that only track a click: dropped so they don't make duplicate pages. */
    private const TRACKING = ['gclid', 'fbclid', 'msclkid', 'dclid', 'gbraid', 'wbraid', 'mc_cid', 'mc_eid'];

    /** Only http(s) links are followed; mailto:, tel:, javascript:, data: … return null. */
    public static function normalise(string $href, ?string $base = null): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }

        try {
            $uri = new Uri(str_replace(' ', '%20', $href));
            if ($base !== null) {
                $uri = UriResolver::resolve(new Uri($base), $uri);
            }
        } catch (InvalidArgumentException) {
            return null;
        }

        $scheme = strtolower($uri->getScheme());
        if (! in_array($scheme, ['http', 'https'], true) || $uri->getHost() === '') {
            return null;
        }

        $uri = $uri->withScheme($scheme)->withHost(strtolower(rtrim($uri->getHost(), '.')))->withFragment('')->withUserInfo('');
        $uri = $uri->withPath(UriResolver::removeDotSegments($uri->getPath()) ?: '/');

        if ($uri->getQuery() !== '') {
            $kept = array_filter(explode('&', $uri->getQuery()), function (string $pair) {
                $name = strtolower(urldecode(explode('=', $pair, 2)[0]));

                return $name !== '' && ! str_starts_with($name, 'utm_') && ! in_array($name, self::TRACKING, true);
            });
            $uri = $uri->withQuery(implode('&', $kept));
        }

        return (string) $uri;
    }

    public static function hash(string $url): string
    {
        return md5($url);
    }

    /** The host without a leading "www.": www and the apex count as the same site. */
    public static function siteHost(string $url): string
    {
        return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
    }

    public static function isSameSite(string $url, string $siteHost): bool
    {
        return self::siteHost($url) === $siteHost;
    }

    /** scheme://host[:port] of a URL. */
    public static function origin(string $url): string
    {
        $p = parse_url($url);

        return $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
    }

    /** Path and query, as robots.txt rules match them. */
    public static function pathAndQuery(string $url): string
    {
        $p = parse_url($url);

        return ($p['path'] ?? '/').(isset($p['query']) ? '?'.$p['query'] : '');
    }

    /** Links to files that are not pages (checked for status only, never parsed). */
    public static function looksLikeFile(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return preg_match('/\.(jpe?g|png|gif|webp|avif|svg|ico|bmp|tiff?|pdf|docx?|xlsx?|pptx?|csv|zip|rar|gz|7z|tar|mp3|wav|mp4|m4v|mov|avi|webm|wmv|woff2?|ttf|eot|otf|css|js|json|xml|txt|exe|dmg|apk)$/', $path) === 1;
    }
}
