<?php

namespace App\Services\SiteAudit;

use App\Models\SiteAudit;
use App\Models\SiteAuditIssue;
use App\Models\SiteAuditPage;
use App\Support\AuditIssues;
use Illuminate\Support\Facades\DB;

/**
 * Turns a crawl's pages and links into issues (App\Support\AuditIssues), then
 * scores and compares the audit. Database only, no network: safe to re-run
 * (crawl issues are replaced; PageSpeed issues are VitalsRunner's).
 *
 * Which pages each check looks at:
 * - status and redirect checks: every URL fetched;
 * - title, meta description, H1, canonical presence, thin content: indexable
 *   pages (200, HTML, no noindex, canonical absent or to itself);
 * - images, canonical targets, slow response: every 200 HTML page.
 */
class AuditAnalyser
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];

    private SiteAudit $audit;

    public function analyse(SiteAudit $audit): void
    {
        $this->audit = $audit;
        $this->rows = [];

        DB::transaction(function () use ($audit) {
            $this->countLinks($audit->id);
            $audit->issues()->whereNotIn('type', AuditIssues::VITALS_TYPES)->delete();
            $this->detect();
            foreach (array_chunk($this->rows, 500) as $chunk) {
                SiteAuditIssue::query()->insert($chunk);
            }
        });

        $this->summarise($audit);
    }

    /**
     * Health score, counts and the comparison with the previous done audit of
     * the site (also re-run when PageSpeed issues arrive).
     */
    public function summarise(SiteAudit $audit): void
    {
        $previous = SiteAudit::query()->withoutGlobalScopes()
            ->where('site_id', $audit->site_id)->where('status', SiteAudit::DONE)->where('id', '<', $audit->id)->whereNull('pruned_at')
            ->orderByDesc('id')->first();

        $counts = ['critical' => 0, 'warning' => 0, 'notice' => 0, 'total' => 0];
        foreach ($audit->issues()->toBase()->selectRaw('severity, COUNT(*) AS n')->groupBy('severity')->get() as $row) {
            $counts[$row->severity] = (int) $row->n;
            $counts['total'] += (int) $row->n;
        }

        $comparison = null;
        DB::table('site_audit_issues')->where('audit_id', $audit->id)->update(['is_new' => false]);
        if ($previous) {
            // Vitals only compare when both audits have them (a PageSpeed outage isn't "12 fixed").
            $skip = $audit->vitals_status === SiteAudit::VITALS_DONE && $previous->vitals_status === SiteAudit::VITALS_DONE ? [] : AuditIssues::VITALS_TYPES;
            $current = $this->fingerprints($audit->id, $skip);
            $before = $this->fingerprints($previous->id, $skip);
            $new = array_diff_key($current, $before);

            foreach (array_chunk(array_keys($new), 1000) as $chunk) {
                DB::table('site_audit_issues')->where('audit_id', $audit->id)->whereIn('fingerprint', $chunk)->update(['is_new' => true]);
            }

            $comparison = [
                'fixed' => count(array_diff_key($before, $current)),
                'new' => count($new),
                'persisting' => count(array_intersect_key($current, $before)),
                'previous_audit_id' => $previous->id,
            ];
        }

        $audit->forceFill([
            'counts' => $counts,
            'health_score' => $this->healthScore($audit),
            'previous_audit_id' => $previous?->id,
            'comparison' => $comparison,
        ])->save();
    }

    /**
     * 100 − Σ weight × (pages with an issue of that severity ÷ pages crawled)
     *     − Σ points × site-wide issues of that severity, clamped to 0–100.
     * Weights (critical 50, warning 30, notice 10) and points (25, 5, 2) are in
     * config indlela.audit.health. No page crawled scores 0.
     */
    public function healthScore(SiteAudit $audit): int
    {
        $pages = $audit->pages()->count();
        if ($pages === 0) {
            return 0;
        }

        $siteWide = array_values(array_filter(AuditIssues::types(), AuditIssues::isSiteWide(...)));
        $byPage = $audit->issues()->toBase()->whereNotIn('type', $siteWide)->whereNotNull('page_id')
            ->selectRaw('severity, COUNT(DISTINCT page_id) AS n')->groupBy('severity')->pluck('n', 'severity');
        $bySite = $audit->issues()->toBase()->whereIn('type', $siteWide)
            ->selectRaw('severity, COUNT(*) AS n')->groupBy('severity')->pluck('n', 'severity');

        $health = config('indlela.audit.health');
        $score = 100.0;
        foreach (AuditIssues::SEVERITIES as $severity) {
            $score -= $health['page_weights'][$severity] * ((int) ($byPage[$severity] ?? 0)) / $pages;
            $score -= $health['site_points'][$severity] * (int) ($bySite[$severity] ?? 0);
        }

        return (int) max(0, min(100, round($score)));
    }

    /** @param  list<string>  $skipTypes  @return array<string, true> */
    private function fingerprints(int $auditId, array $skipTypes): array
    {
        return DB::table('site_audit_issues')->where('audit_id', $auditId)->whereNotIn('type', $skipTypes)
            ->distinct()->pluck('fingerprint')->flip()->map(fn () => true)->all();
    }

    /** inlinks = distinct other pages linking here (any internal <a>); outlinks = distinct link targets. */
    private function countLinks(int $auditId): void
    {
        DB::update('UPDATE site_audit_pages SET inlinks_count = 0, outlinks_count = 0 WHERE audit_id = ?', [$auditId]);
        DB::update("UPDATE site_audit_pages p SET inlinks_count = c.n FROM (
                SELECT l.to_url_hash, COUNT(DISTINCT l.from_page_id) AS n
                FROM site_audit_links l JOIN site_audit_pages src ON src.id = l.from_page_id
                WHERE l.audit_id = ? AND l.kind = 'link' AND l.is_internal AND src.url_hash <> l.to_url_hash
                GROUP BY l.to_url_hash
            ) c WHERE p.audit_id = ? AND p.url_hash = c.to_url_hash", [$auditId, $auditId]);
        DB::update("UPDATE site_audit_pages p SET outlinks_count = c.n FROM (
                SELECT from_page_id, COUNT(DISTINCT to_url_hash) AS n FROM site_audit_links
                WHERE audit_id = ? AND kind = 'link' GROUP BY from_page_id
            ) c WHERE p.id = c.from_page_id", [$auditId]);
    }

    private function detect(): void
    {
        $t = config('indlela.audit.thresholds');
        $meta = $this->audit->crawl_meta ?? [];
        $home = $meta['home_url'] ?? null;

        // Status of every crawled URL, for canonical targets: hash => [status, final status].
        $statuses = [];
        foreach ($this->audit->pages()->toBase()->select('url_hash', 'status_code', 'final_status_code')->cursor() as $p) {
            $statuses[$p->url_hash] = [(int) $p->status_code, (int) $p->final_status_code];
        }

        $duplicates = [
            'duplicate_title' => $this->duplicates('title'),
            'duplicate_meta_description' => $this->duplicates('meta_description'),
        ];

        foreach ($this->audit->pages()->orderBy('id')->lazy(500) as $page) {
            /** @var SiteAuditPage $page */
            $this->pageIssues($page, $t, $home, $statuses, $duplicates);
        }

        $this->linkIssues();
        $this->siteIssues($meta, $home);
    }

    /**
     * @param  array<string, mixed>  $t
     * @param  array<string, array{0: int, 1: int}>  $statuses
     * @param  array<string, array<string, list<string>>>  $duplicates
     */
    private function pageIssues(SiteAuditPage $page, array $t, ?string $home, array $statuses, array $duplicates): void
    {
        $status = $page->status_code;
        $chain = $page->redirect_chain ?? [];

        if ($status === 0 || $status >= 500) {
            $this->add('page_5xx', $page, ['status_code' => $status, 'error' => $page->fetch_error]);
        } elseif ($status >= 400) {
            $this->add('page_4xx', $page, ['status_code' => $status]);
        }

        if ($page->extras['redirect_loop'] ?? false) {
            $this->add('redirect_loop', $page, ['chain' => $chain]);
        } elseif (count($chain) - 1 >= $t['redirect_chain_hops']) {
            $this->add('redirect_chain', $page, ['redirects' => count($chain) - 1, 'chain' => $chain, 'final_url' => $page->final_url]);
        }

        if ($status !== 200 || ! $page->isHtml() || $page->redirect_chain) {
            return;
        }

        if ($page->images_missing_alt > 0) {
            $this->add('images_missing_alt', $page, ['count' => $page->images_missing_alt, 'images_count' => $page->images_count, 'images' => $page->extras['missing_alt_srcs'] ?? []]);
        }
        if ($page->response_ms !== null && $page->response_ms > $t['slow_response_ms']) {
            $this->add('slow_response', $page, ['response_ms' => $page->response_ms]);
        }
        if ($page->canonical_count > 1) {
            $this->add('multiple_canonicals', $page, ['canonical_count' => $page->canonical_count, 'canonical' => $page->canonical]);
        }
        if ($page->canonical !== null && $page->canonical !== $page->url) {
            $target = $statuses[CrawlUrl::hash($page->canonical)] ?? null;
            if ($target !== null && $target[0] !== 200) {
                $this->add('canonical_to_non_200', $page, ['canonical' => $page->canonical, 'status_code' => $target[0], 'final_status_code' => $target[1]]);
            } else {
                $this->add('canonical_to_other_page', $page, ['canonical' => $page->canonical]);
            }
        }

        $noindex = $page->isNoindex();
        $robots = ['meta_robots' => $page->meta_robots, 'x_robots_tag' => $page->x_robots_tag];
        if ($noindex && $page->url === $home) {
            $this->add('homepage_noindex', $page, $robots);
        }
        if ($noindex && $page->in_sitemap) {
            $this->add('noindex_in_sitemap', $page, $robots);
        }
        if ($noindex && $page->inlinks_count > 0) {
            $this->add('noindex_linked_internally', $page, [...$robots, 'inlinks_count' => $page->inlinks_count]);
        }
        if ($page->in_sitemap && $page->inlinks_count === 0 && $page->url !== $home) {
            $this->add('orphan_page', $page);
        }

        if (! $page->is_indexable) {
            return;
        }

        $title = $page->title;
        if ($title === null) {
            $this->add('missing_title', $page);
        } else {
            $length = mb_strlen($title);
            if ($length > $t['title_max']) {
                $this->add('title_too_long', $page, ['title' => $title, 'length' => $length]);
            } elseif ($length < $t['title_min']) {
                $this->add('title_too_short', $page, ['title' => $title, 'length' => $length]);
            }
            $this->duplicateIssue('duplicate_title', 'title', $title, $page, $duplicates);
        }

        $description = $page->meta_description;
        if ($description === null) {
            $this->add('missing_meta_description', $page);
        } else {
            if (mb_strlen($description) > $t['meta_description_max']) {
                $this->add('meta_description_too_long', $page, ['meta_description' => $description, 'length' => mb_strlen($description)]);
            }
            $this->duplicateIssue('duplicate_meta_description', 'meta_description', $description, $page, $duplicates);
        }

        if ($page->h1_count === 0) {
            $this->add('missing_h1', $page);
        } elseif ($page->h1_count > 1) {
            $this->add('multiple_h1', $page, ['h1_count' => $page->h1_count, 'h1' => $page->h1]);
        }
        if ($page->canonical === null) {
            $this->add('missing_canonical', $page);
        }
        if ($page->word_count < $t['thin_content_words']) {
            $this->add('thin_content', $page, ['word_count' => $page->word_count]);
        }
    }

    /** @param  array<string, array<string, list<string>>>  $duplicates */
    private function duplicateIssue(string $type, string $field, string $value, SiteAuditPage $page, array $duplicates): void
    {
        $urls = $duplicates[$type][mb_strtolower($value)] ?? [];
        if (count($urls) < 2) {
            return;
        }
        $others = array_values(array_diff($urls, [$page->url]));
        $this->add($type, $page, [$field => $value, 'pages' => count($urls), 'other_urls' => array_slice($others, 0, 5)]);
    }

    /**
     * Values shared by more than one indexable page (case-insensitive).
     *
     * @return array<string, list<string>> lower-cased value => URLs
     */
    private function duplicates(string $column): array
    {
        $shared = $this->audit->pages()->toBase()->where('is_indexable', true)->whereNotNull($column)
            ->selectRaw("LOWER({$column}) AS v")->groupByRaw("LOWER({$column})")->havingRaw('COUNT(*) > 1')->pluck('v')->all();
        if ($shared === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($shared, 500) as $chunk) {
            $rows = $this->audit->pages()->toBase()->where('is_indexable', true)
                ->whereIn(DB::raw("LOWER({$column})"), $chunk)->orderBy('id')->get([DB::raw("LOWER({$column}) AS v"), 'url']);
            foreach ($rows as $row) {
                $out[$row->v][] = $row->url;
            }
        }

        return $out;
    }

    /** Broken internal/external links and internal links to redirects: one issue per source page and target. */
    private function linkIssues(): void
    {
        $rows = DB::select('SELECT l.from_page_id, src.url AS from_url, l.to_url, l.kind, l.is_internal,
                l.status_code AS link_status, t.status_code AS page_status, t.final_status_code, t.final_url
            FROM site_audit_links l
            JOIN site_audit_pages src ON src.id = l.from_page_id
            LEFT JOIN site_audit_pages t ON t.audit_id = l.audit_id AND t.url_hash = l.to_url_hash
            WHERE l.audit_id = ? AND l.status_code IS NOT NULL
              AND (l.status_code = 0 OR l.status_code >= 300)
            ORDER BY l.id', [$this->audit->id]);

        foreach ($rows as $link) {
            $page = ['id' => (int) $link->from_page_id, 'url' => $link->from_url];
            $first = (int) ($link->page_status ?? $link->link_status);
            $final = $link->final_status_code !== null ? (int) $link->final_status_code : $first;

            if ($link->is_internal) {
                if ($final === 0 || $final >= 400) {
                    $this->add('broken_internal_link', $page, ['to_url' => $link->to_url, 'status_code' => $final, 'kind' => $link->kind], $link->to_url);
                } elseif ($first >= 300 && $first < 400 && $link->kind === 'link') {
                    $this->add('internal_link_to_redirect', $page, ['to_url' => $link->to_url, 'status_code' => $first, 'final_url' => $link->final_url], $link->to_url);
                }
            } elseif ($link->kind === 'link' && self::externalBroken($final)) {
                $this->add('broken_external_link', $page, ['to_url' => $link->to_url, 'status_code' => $final], $link->to_url);
            }
        }
    }

    /**
     * No response, 404/410 or a server error. Other 4xx (401, 403, 429, LinkedIn's 999…)
     * usually mean the site blocks automated checks, not that the page is gone.
     */
    public static function externalBroken(int $status): bool
    {
        return $status === 0 || $status === 404 || $status === 410 || $status >= 500;
    }

    /** @param  array<string, mixed>  $meta */
    private function siteIssues(array $meta, ?string $home): void
    {
        $origin = $home ? CrawlUrl::origin($home) : null;

        if ($meta['robots_blocks_site'] ?? false) {
            $this->add('robots_blocks_site', null, ['robots_url' => $origin.'/robots.txt'], url: $origin.'/robots.txt');
        }
        if (! ($meta['sitemap_found'] ?? false)) {
            $this->add('sitemap_missing', null, ['checked' => $origin ? [$origin.'/robots.txt', $origin.'/sitemap.xml', $origin.'/sitemap_index.xml', $origin.'/wp-sitemap.xml'] : []], url: $home);
        }

        if ($home !== null) {
            $homePage = $this->audit->pages()->where('url_hash', CrawlUrl::hash($home))->first(['id', 'url', 'final_url']);
            $served = $homePage?->final_url ?? $home;
            if (parse_url($served, PHP_URL_SCHEME) === 'http') {
                $this->add('http_not_https', null, ['url' => $served], url: $home);
            }
        }
    }

    /**
     * @param  SiteAuditPage|array{id: int, url: string}|null  $page
     * @param  array<string, mixed>|null  $details
     */
    private function add(string $type, SiteAuditPage|array|null $page, ?array $details = null, string $key = '', ?string $url = null): void
    {
        $pageId = $page instanceof SiteAuditPage ? $page->id : ($page['id'] ?? null);
        $url ??= $page instanceof SiteAuditPage ? $page->url : ($page['url'] ?? null);

        $this->rows[] = [
            'audit_id' => $this->audit->id,
            'workspace_id' => $this->audit->workspace_id,
            'site_id' => $this->audit->site_id,
            'type' => $type,
            'severity' => AuditIssues::severity($type),
            'page_id' => $pageId,
            'url' => $url,
            'details' => $details === null ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'fingerprint' => SiteAuditIssue::fingerprint($type, $url, $key),
            'is_new' => false,
        ];
    }
}
