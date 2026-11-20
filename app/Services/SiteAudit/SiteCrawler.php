<?php

namespace App\Services\SiteAudit;

use App\Exceptions\AuditCancelled;
use App\Models\SiteAudit;
use App\Models\SiteAuditLink;
use App\Models\SiteAuditPage;
use Illuminate\Support\Facades\DB;
use SplQueue;

/**
 * Crawls one site for an audit and stores what it fetched: pages (with their
 * redirect chains) and links. Analysis is a separate step (AuditAnalyser).
 *
 * Breadth first from the home page; when links run out, the sitemap's URLs
 * nobody linked to are crawled too (depth null). Same site only (www and the
 * apex are one site), robots.txt honoured for IndlelaBot (else *), at most
 * the audit's page_limit URLs and `audit.max_depth` clicks deep. Batches of
 * `audit.concurrency` requests with a pause between them; between batches it
 * writes progress and stops if the audit was cancelled.
 *
 * Every fetched URL is one page row, redirects and errors included: a
 * redirect is followed hop by hop to record the chain, and its target (when
 * on the site) is queued as a page of its own.
 */
class SiteCrawler
{
    private SiteAudit $audit;

    private string $siteHost;

    private RobotsTxt $robots;

    /** @var array<string, true> hashes of URLs already queued or crawled */
    private array $known = [];

    /** @var SplQueue<array{0: string, 1: int|null}> */
    private SplQueue $queue;

    /** @var array<string, string> sitemap URLs, hash => URL */
    private array $sitemapUrls = [];

    public function __construct(private readonly PageFetcher $fetcher, private readonly SitemapReader $sitemaps) {}

    /** @throws AuditCancelled when the audit is cancelled mid-crawl */
    public function crawl(SiteAudit $audit): void
    {
        $this->audit = $audit;
        $this->queue = new SplQueue;
        $this->known = [];

        $home = CrawlUrl::normalise($audit->site->url) ?? $audit->site->url;
        $this->siteHost = CrawlUrl::siteHost($home);
        $origin = CrawlUrl::origin($home);
        $agent = (string) config('indlela.audit.robots_agent');

        $this->robots = $this->robots($origin);
        $homePath = CrawlUrl::pathAndQuery($home);
        $blocksUs = ! $this->robots->allows($homePath, $agent);
        $blocksGoogle = ! $this->robots->allows($homePath, 'googlebot');

        $sitemap = $this->sitemaps->read($origin, $this->siteHost, $this->robots);
        $this->sitemapUrls = $sitemap['urls'];

        $audit->forceFill(['crawl_meta' => [
            'home_url' => $home,
            'scheme' => parse_url($home, PHP_URL_SCHEME),
            'robots_found' => $this->robots->found,
            'robots_blocks_site' => $blocksUs || $blocksGoogle,
            'sitemap_found' => $sitemap['found'],
            'sitemaps' => array_slice($sitemap['sitemaps'], 0, 20),
            'sitemap_urls' => count($sitemap['urls']),
        ]])->save();

        if ($blocksUs) {
            $this->progress(0, true);

            return;
        }

        $this->enqueue($home, 0);
        $crawled = 0;
        $limit = $audit->page_limit;
        $sitemapQueued = false;
        $every = max(1, (int) config('indlela.audit.progress_every'));
        $batchSize = max(1, (int) config('indlela.audit.concurrency'));
        $delay = (int) config('indlela.audit.delay_ms');

        while ($crawled < $limit) {
            if ($this->queue->isEmpty() && ! $sitemapQueued) {
                $sitemapQueued = true;
                foreach ($this->sitemapUrls as $url) {
                    $this->enqueue($url, null);
                }
            }
            if ($this->queue->isEmpty()) {
                break;
            }

            $batch = [];
            while (! $this->queue->isEmpty() && count($batch) < min($batchSize, $limit - $crawled)) {
                $batch[] = $this->queue->dequeue();
            }

            $this->crawlBatch($batch);
            $before = $crawled;
            $crawled += count($batch);

            if (intdiv($crawled, $every) > intdiv($before, $every)) {
                $this->progress($crawled);
            }
            if (SiteAudit::query()->withoutGlobalScopes()->whereKey($audit->id)->value('status') === SiteAudit::CANCELLED) {
                $this->progress($crawled);

                throw new AuditCancelled;
            }
            if ($delay > 0 && $crawled < $limit && ! $this->queue->isEmpty()) {
                usleep($delay * 1000);
            }
        }

        $this->progress($crawled, true);
        $this->checkLinkStatuses();
    }

    private function robots(string $origin): RobotsTxt
    {
        $result = $this->fetcher->fetch([$origin.'/robots.txt'])[0];

        return $result->status === 200 && $result->body !== null ? RobotsTxt::parse($result->body) : RobotsTxt::allowAll();
    }

    private function enqueue(string $url, ?int $depth): void
    {
        $hash = CrawlUrl::hash($url);
        if (isset($this->known[$hash])) {
            return;
        }
        $this->known[$hash] = true;

        if (CrawlUrl::looksLikeFile($url) || ! $this->robots->allows(CrawlUrl::pathAndQuery($url), (string) config('indlela.audit.robots_agent'))) {
            return;
        }

        $this->queue->enqueue([$url, $depth]);
    }

    /** @param  list<array{0: string, 1: int|null}>  $batch */
    private function crawlBatch(array $batch): void
    {
        $chains = $this->followRedirects(array_column($batch, 0));
        $links = [];

        foreach ($batch as $i => [$url, $depth]) {
            ['hops' => $hops, 'first' => $first, 'final' => $final, 'loop' => $loop] = $chains[$i];
            $redirected = count($hops) > 1;
            $finalUrl = $hops[array_key_last($hops)]['url'];
            $ms = array_sum(array_map(fn (array $hop) => $hop['ms'] ?? 0, $hops));

            $row = [
                'audit_id' => $this->audit->id,
                'url' => $url,
                'url_hash' => CrawlUrl::hash($url),
                'depth' => $depth,
                'status_code' => $first->status,
                'redirect_chain' => $redirected ? array_map(fn (array $hop) => ['url' => $hop['url'], 'status_code' => $hop['status_code']], $hops) : null,
                'final_url' => $finalUrl,
                'final_status_code' => $final->status,
                'content_type' => $first->contentType() ? mb_substr($first->contentType(), 0, 150) : null,
                'response_ms' => $ms > 0 ? $ms : null,
                'fetch_error' => $first->error,
                'x_robots_tag' => $first->header('x-robots-tag') ? mb_substr($first->header('x-robots-tag'), 0, 500) : null,
                'in_sitemap' => isset($this->sitemapUrls[CrawlUrl::hash($url)]),
                'extras' => $loop ? ['redirect_loop' => true] : null,
            ];

            $html = null;
            if (! $redirected && $first->status === 200 && $first->isHtml() && $first->body !== null) {
                $html = HtmlPage::parse($first->body, $url);
                $canonical = $html->canonicals[0] ?? null;
                $row = [...$row,
                    'title' => $html->title,
                    'meta_description' => $html->metaDescription,
                    'h1' => $html->h1s[0] ?? null,
                    'h1_count' => count($html->h1s),
                    'canonical' => $canonical,
                    'canonical_count' => count(array_unique($html->canonicals)),
                    'meta_robots' => $html->metaRobots ? mb_substr($html->metaRobots, 0, 500) : null,
                    'word_count' => $html->wordCount,
                    'images_count' => $html->imagesCount,
                    'images_missing_alt' => $html->missingAltCount,
                    'extras' => [...($row['extras'] ?? []), ...($html->missingAlt ? ['missing_alt_srcs' => $html->missingAlt] : [])] ?: null,
                ];
                $row['is_indexable'] = ! SiteAuditPage::hasNoindex($html->metaRobots) && ! SiteAuditPage::hasNoindex($row['x_robots_tag'])
                    && ($canonical === null || $canonical === $url);
            }

            $page = SiteAuditPage::query()->create($row);

            if ($redirected && ! $loop && CrawlUrl::isSameSite($finalUrl, $this->siteHost)) {
                $this->enqueue($finalUrl, $depth);
            }

            if ($html) {
                $childDepth = $depth === null ? null : $depth + 1;
                $follow = $childDepth === null || $childDepth <= (int) config('indlela.audit.max_depth');

                foreach ($html->links as $target => $nofollow) {
                    $internal = CrawlUrl::isSameSite($target, $this->siteHost);
                    $links[] = $this->linkRow($page->id, $target, SiteAuditLink::LINK, $internal, $nofollow);
                    if ($internal && $follow) {
                        $this->enqueue($target, $childDepth);
                    }
                }
                foreach (array_keys($html->images) as $src) {
                    $links[] = $this->linkRow($page->id, $src, SiteAuditLink::IMAGE, CrawlUrl::isSameSite($src, $this->siteHost), false);
                }
                // Crawl the canonical target too, so a broken or redirected canonical is caught.
                if ($row['canonical'] && CrawlUrl::isSameSite($row['canonical'], $this->siteHost)) {
                    $this->enqueue($row['canonical'], $childDepth);
                }
            }
        }

        foreach (array_chunk($links, 500) as $chunk) {
            SiteAuditLink::query()->insert($chunk);
        }
    }

    /** @return array<string, mixed> */
    private function linkRow(int $pageId, string $url, string $kind, bool $internal, bool $nofollow): array
    {
        return ['audit_id' => $this->audit->id, 'from_page_id' => $pageId, 'to_url' => $url, 'to_url_hash' => CrawlUrl::hash($url),
            'kind' => $kind, 'is_internal' => $internal, 'nofollow' => $nofollow];
    }

    /**
     * Fetches each URL and follows its redirects hop by hop (all URLs of the
     * batch in parallel at each hop), up to `audit.max_redirects`.
     *
     * @param  list<string>  $urls
     * @return list<array{hops: list<array{url: string, status_code: int, ms: int|null}>, first: FetchResult, final: FetchResult, loop: bool}>
     */
    private function followRedirects(array $urls): array
    {
        $out = [];
        $pending = $urls;
        $max = (int) config('indlela.audit.max_redirects');

        for ($hop = 0; $pending !== []; $hop++) {
            $next = [];
            foreach ($this->fetcher->fetch($pending) as $i => $result) {
                $url = $pending[$i];
                $out[$i]['hops'][] = ['url' => $url, 'status_code' => $result->status, 'ms' => $result->ms];
                $out[$i]['first'] ??= $result;
                $out[$i]['final'] = $result;
                $out[$i]['loop'] = false;

                if (! $result->isRedirect()) {
                    continue;
                }
                $target = CrawlUrl::normalise((string) $result->header('location'), $url);
                if ($target === null) {
                    continue;
                }
                $seen = array_column($out[$i]['hops'], 'url');
                if (in_array($target, $seen, true) || $hop >= $max) {
                    // Back where it started (or endless): record the hop that closes the loop.
                    $out[$i]['hops'][] = ['url' => $target, 'status_code' => in_array($target, $seen, true) ? $out[$i]['hops'][array_search($target, $seen, true)]['status_code'] : 0, 'ms' => null];
                    $out[$i]['loop'] = true;

                    continue;
                }
                $next[$i] = $target;
            }
            $pending = $next;
        }

        ksort($out);

        return $out;
    }

    private function progress(int $crawled, bool $final = false): void
    {
        $found = count($this->known + $this->sitemapUrls);
        SiteAudit::query()->withoutGlobalScopes()->whereKey($this->audit->id)->whereIn('status', [SiteAudit::CRAWLING, SiteAudit::CANCELLED])
            ->update(['pages_crawled' => $crawled, 'pages_found' => max($found, $crawled), 'updated_at' => now()]);
        if ($final) {
            $this->audit->forceFill(['pages_crawled' => $crawled, 'pages_found' => max($found, $crawled)]);
        }
    }

    /**
     * Statuses of the links: internal ones from the pages crawled, then (with
     * HEAD/GET checks, each URL once per audit) internal images and files and
     * the most-linked external URLs, up to the configured limits. Links
     * beyond them keep status null (not checked).
     */
    private function checkLinkStatuses(): void
    {
        $id = $this->audit->id;

        DB::update('UPDATE site_audit_links l SET status_code = p.status_code FROM site_audit_pages p
            WHERE l.audit_id = ? AND p.audit_id = ? AND l.is_internal AND p.url_hash = l.to_url_hash', [$id, $id]);

        // Internal page links left unchecked were beyond the crawl (limit, depth, robots): not fetched here.
        $internal = SiteAuditLink::query()->where('audit_id', $id)->where('is_internal', true)->whereNull('status_code')
            ->selectRaw('to_url_hash, MIN(to_url) AS to_url, MIN(kind) AS kind')->groupBy('to_url_hash')->orderByRaw('COUNT(*) DESC')
            ->toBase()->get()
            ->filter(fn (object $link) => $link->kind === SiteAuditLink::IMAGE || CrawlUrl::looksLikeFile($link->to_url))
            ->take((int) config('indlela.audit.image_check_limit'))
            ->pluck('to_url', 'to_url_hash')->all();

        $external = SiteAuditLink::query()->where('audit_id', $id)->where('is_internal', false)->where('kind', SiteAuditLink::LINK)
            ->selectRaw('to_url_hash, MIN(to_url) AS to_url')->groupBy('to_url_hash')->orderByRaw('COUNT(*) DESC')
            ->limit((int) config('indlela.audit.external_check_limit'))
            ->toBase()->pluck('to_url', 'to_url_hash')->all();

        foreach (array_chunk($internal + $external, 50, true) as $chunk) {
            foreach ($this->fetcher->statuses($chunk) as $hash => $status) {
                SiteAuditLink::query()->where('audit_id', $id)->where('to_url_hash', (string) $hash)->update(['status_code' => $status]);
            }
        }
    }
}
