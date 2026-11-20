<?php

namespace App\Services\SiteAudit;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * What the audit reads from a page's raw HTML: title, meta description and
 * robots, canonicals, H1s, links, images and the visible word count.
 * Links and the canonical come back absolute and normalised (CrawlUrl),
 * resolved against <base href> when the page has one.
 */
final class HtmlPage
{
    public ?string $title = null;

    public ?string $metaDescription = null;

    public ?string $metaRobots = null;

    /** @var list<string> */
    public array $canonicals = [];

    /** @var list<string> */
    public array $h1s = [];

    /** @var array<string, bool> url => nofollow (followed if any link to it is) */
    public array $links = [];

    /** @var array<string, true> internal or external image URLs */
    public array $images = [];

    public int $imagesCount = 0;

    /** @var list<string> src of images without alt text (first 20) */
    public array $missingAlt = [];

    public int $missingAltCount = 0;

    public int $wordCount = 0;

    public static function parse(string $html, string $url): self
    {
        $page = new self;
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // The XML declaration makes libxml read the page as UTF-8 (WordPress serves UTF-8).
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($doc);

        $base = $url;
        $baseHref = $xpath->query('//head/base[@href]')->item(0);
        if ($baseHref instanceof DOMElement) {
            $base = CrawlUrl::normalise($baseHref->getAttribute('href'), $url) ?? $url;
        }

        $title = $xpath->query('//title')->item(0);
        $page->title = $title ? self::clean($title->textContent) : null;

        $robots = [];
        foreach ($xpath->query('//meta[@name]') as $meta) {
            /** @var DOMElement $meta */
            $name = strtolower(trim($meta->getAttribute('name')));
            $content = self::clean($meta->getAttribute('content'));
            if ($name === 'description' && $page->metaDescription === null) {
                $page->metaDescription = $content;
            } elseif (in_array($name, ['robots', 'googlebot'], true) && $content !== null) {
                $robots[] = $content;
            }
        }
        $page->metaRobots = $robots ? implode(', ', array_unique($robots)) : null;

        foreach ($xpath->query('//link[@rel][@href]') as $link) {
            /** @var DOMElement $link */
            if (in_array('canonical', preg_split('/\s+/', strtolower(trim($link->getAttribute('rel')))), true)) {
                $canonical = CrawlUrl::normalise($link->getAttribute('href'), $base);
                if ($canonical !== null) {
                    $page->canonicals[] = $canonical;
                }
            }
        }

        foreach ($xpath->query('//h1') as $h1) {
            $page->h1s[] = (string) self::clean($h1->textContent);
        }

        foreach ($xpath->query('//a[@href]') as $a) {
            /** @var DOMElement $a */
            $target = CrawlUrl::normalise($a->getAttribute('href'), $base);
            if ($target === null) {
                continue;
            }
            $nofollow = in_array('nofollow', preg_split('/\s+/', strtolower($a->getAttribute('rel'))), true);
            $page->links[$target] = ($page->links[$target] ?? true) && $nofollow;
        }

        foreach ($xpath->query('//img') as $img) {
            /** @var DOMElement $img */
            $page->imagesCount++;
            // Lazy-loading plugins keep the real file in data-src / data-lazy-src.
            $src = $img->getAttribute('data-src') ?: $img->getAttribute('data-lazy-src') ?: $img->getAttribute('src');
            $resolved = $src !== '' && ! str_starts_with($src, 'data:') ? CrawlUrl::normalise($src, $base) : null;
            if ($resolved !== null) {
                $page->images[$resolved] = true;
            }
            if (! $img->hasAttribute('alt') || trim($img->getAttribute('alt')) === '') {
                $page->missingAltCount++;
                if (count($page->missingAlt) < 20) {
                    $page->missingAlt[] = $resolved ?? $src;
                }
            }
        }

        $body = $xpath->query('//body')->item(0);
        if ($body) {
            foreach ($xpath->query('.//script|.//style|.//noscript|.//template|.//svg', $body) as $node) {
                $node->parentNode?->removeChild($node);
            }
            $text = self::clean(str_replace("\u{00A0}", ' ', $body->textContent)) ?? '';
            $page->wordCount = $text === '' ? 0 : count(preg_split('/\s+/u', $text));
        }

        return $page;
    }

    private static function clean(?string $text): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');

        return $text === '' ? null : $text;
    }
}
