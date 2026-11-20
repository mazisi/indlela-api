<?php

namespace Tests\Feature\SiteAudit;

use App\Models\SiteAudit;
use App\Models\SiteAuditLink;
use App\Models\SiteAuditPage;
use App\Services\SiteAudit\CrawlUrl;
use App\Services\SiteAudit\RobotsTxt;
use App\Services\SiteAudit\SiteAudits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Tests\Support\AuthTestHelpers;
use Tests\Support\SiteAuditHelpers;
use Tests\TestCase;

class CrawlerTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase, SiteAuditHelpers;

    private const S = 'https://www.karoo.co.za';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->fakeWeb();
    }

    private function audit(array $siteState = []): SiteAudit
    {
        $site = $this->auditSite(state: $siteState);

        return app(SiteAudits::class)->start($site, null);
    }

    /** @return list<string> */
    private function crawledUrls(SiteAudit $audit): array
    {
        return $audit->pages()->orderBy('url')->pluck('url')->all();
    }

    public function test_robots_txt_sitemap_index_and_seeding(): void
    {
        $s = self::S;
        $this->web = [
            "{$s}/robots.txt" => $this->robots("User-agent: Googlebot\nDisallow: /nothing\n\nUser-agent: *\nDisallow: /private\nAllow: /private/open\nSitemap: {$s}/sitemap_index.xml"),
            "{$s}/sitemap_index.xml" => $this->sitemapIndex(["{$s}/page-sitemap.xml", "{$s}/post-sitemap.xml"]),
            "{$s}/page-sitemap.xml" => $this->sitemap(["{$s}/", "{$s}/about", "{$s}/orphan", 'https://elsewhere.co.za/x']),
            "{$s}/post-sitemap.xml" => $this->sitemap(["{$s}/blog/post"]),
            "{$s}/" => $this->html(links: ['/about', '/private/secret', '/private/open', '/contact?utm_source=fb&id=2#top', 'https://karoo.co.za/apex', 'https://external.co.za/', 'mailto:hi@karoo.co.za']),
            "{$s}/about" => $this->html(links: ['/']),
            "{$s}/private/open" => $this->html(),
            "{$s}/contact?id=2" => $this->html(),
            'https://karoo.co.za/apex' => $this->html(),
            "{$s}/orphan" => $this->html(),
            "{$s}/blog/post" => $this->html(),
            'https://external.co.za/' => $this->html(),
        ];

        $audit = $this->audit();

        $this->assertSame(SiteAudit::DONE, $audit->status);
        $this->assertEqualsCanonicalizing([
            "{$s}/", "{$s}/about", "{$s}/blog/post", "{$s}/contact?id=2", "{$s}/orphan", "{$s}/private/open", 'https://karoo.co.za/apex',
        ], $this->crawledUrls($audit));
        $this->assertNotContains("GET {$s}/private/secret", $this->requested);
        $this->assertNotContains('GET https://external.co.za/', $this->requested, 'external links are only status-checked');
        $this->assertContains('HEAD https://external.co.za/', $this->requested);

        $orphan = $audit->pages()->where('url', "{$s}/orphan")->first();
        $this->assertTrue($orphan->in_sitemap);
        $this->assertNull($orphan->depth, 'reached only through the sitemap');
        $this->assertSame(1, $audit->pages()->where('url', "{$s}/about")->value('depth'));
        $this->assertSame(7, $audit->pages_crawled);
        $this->assertSame(['https://www.karoo.co.za/sitemap_index.xml', 'https://www.karoo.co.za/page-sitemap.xml', 'https://www.karoo.co.za/post-sitemap.xml'], $audit->crawl_meta['sitemaps']);
        $this->assertSame(4, $audit->crawl_meta['sitemap_urls'], 'other hosts left out');
        $this->assertFalse($audit->crawl_meta['robots_blocks_site']);

        // Links: tracking params stripped, mailto ignored, www and apex the same site.
        $links = SiteAuditLink::query()->where('audit_id', $audit->id)->where('from_page_id', $audit->pages()->where('url', "{$s}/")->value('id'))->pluck('is_internal', 'to_url')->all();
        $this->assertTrue($links["{$s}/contact?id=2"]);
        $this->assertTrue($links['https://karoo.co.za/apex']);
        $this->assertFalse($links['https://external.co.za/']);
        $this->assertCount(6, $links);
        $this->assertSame(200, SiteAuditLink::query()->where('audit_id', $audit->id)->where('to_url', 'https://external.co.za/')->value('status_code'));
    }

    public function test_robots_blocking_the_home_page_stops_the_crawl(): void
    {
        $this->web = [self::S.'/robots.txt' => $this->robots("User-agent: IndlelaBot\nDisallow: /\n\nUser-agent: *\nAllow: /"), self::S.'/' => $this->html()];

        $audit = $this->audit();

        $this->assertSame(SiteAudit::DONE, $audit->status);
        $this->assertSame(0, $audit->pages_crawled);
        $this->assertNotContains('GET '.self::S.'/', $this->requested);
        $this->assertTrue($audit->issues()->where('type', 'robots_blocks_site')->exists());
        $this->assertSame(0, $audit->health_score);
    }

    public function test_sitemap_fallbacks_when_robots_names_none(): void
    {
        $s = self::S;
        $this->web = [
            "{$s}/sitemap_index.xml" => $this->sitemapIndex(["{$s}/page-sitemap.xml"]),
            "{$s}/page-sitemap.xml" => $this->sitemap(["{$s}/only-in-sitemap"]),
            "{$s}/" => $this->html(),
            "{$s}/only-in-sitemap" => $this->html(),
        ];

        $audit = $this->audit();

        $this->assertContains("{$s}/only-in-sitemap", $this->crawledUrls($audit));
        $this->assertTrue($audit->crawl_meta['sitemap_found']);
        $this->assertFalse($audit->issues()->where('type', 'sitemap_missing')->exists());
        $this->assertNotContains("GET {$s}/wp-sitemap.xml", $this->requested, 'the first working fallback is the sitemap');
    }

    public function test_redirect_chains_and_loops_are_recorded_hop_by_hop(): void
    {
        $s = self::S;
        $this->web = [
            "{$s}/" => $this->html(links: ['/old', '/moved', '/loop-a']),
            "{$s}/old" => $this->redirect("{$s}/older"),
            "{$s}/older" => $this->redirect('/new', 302),
            "{$s}/new" => $this->html(),
            "{$s}/moved" => $this->redirect("{$s}/new"),
            "{$s}/loop-a" => $this->redirect("{$s}/loop-b", 302),
            "{$s}/loop-b" => $this->redirect("{$s}/loop-a", 302),
        ];

        $audit = $this->audit();

        $old = $audit->pages()->where('url', "{$s}/old")->first();
        $this->assertSame(301, $old->status_code);
        $this->assertSame([
            ['url' => "{$s}/old", 'status_code' => 301], ['url' => "{$s}/older", 'status_code' => 302], ['url' => "{$s}/new", 'status_code' => 200],
        ], $old->redirect_chain);
        $this->assertSame("{$s}/new", $old->final_url);
        $this->assertSame(200, $old->final_status_code);
        $this->assertSame(1, $audit->pages()->where('url', "{$s}/new")->count(), 'the target is crawled once');

        $loop = $audit->pages()->where('url', "{$s}/loop-a")->first();
        $this->assertTrue($loop->extras['redirect_loop']);
        $this->assertSame(["{$s}/loop-a", "{$s}/loop-b", "{$s}/loop-a"], array_column($loop->redirect_chain, 'url'));

        $types = $audit->issues()->pluck('url', 'type');
        $this->assertSame("{$s}/old", $audit->issues()->where('type', 'redirect_chain')->value('url'));
        $this->assertSame(1, $audit->issues()->where('type', 'redirect_chain')->count(), '/older → /new is one hop');
        $this->assertTrue($audit->issues()->where('type', 'redirect_loop')->where('url', "{$s}/loop-a")->exists());
        $this->assertEqualsCanonicalizing(["{$s}/old", "{$s}/moved", "{$s}/loop-a"], $audit->issues()->where('type', 'internal_link_to_redirect')->get()->pluck('details.to_url')->all());
        $this->assertNotNull($types);
    }

    public function test_page_limit_and_depth(): void
    {
        $s = self::S;
        $this->web = [
            "{$s}/" => $this->html(links: ['/l1']),
            "{$s}/l1" => $this->html(links: ['/l2']),
            "{$s}/l2" => $this->html(links: ['/l3']),
            "{$s}/l3" => $this->html(links: ['/l4']),
            "{$s}/l4" => $this->html(),
        ];

        config(['indlela.audit.max_depth' => 2]);
        $audit = $this->audit();
        $this->assertSame(["{$s}/", "{$s}/l1", "{$s}/l2"], $this->crawledUrls($audit));
        $this->assertSame([0, 1, 2], $audit->pages()->orderBy('depth')->pluck('depth')->all());

        config(['indlela.audit.max_depth' => 10]);
        $limited = $this->audit(['audit_page_limit' => 3, 'url' => 'https://www.karoo.co.za', 'domain' => 'limited-karoo.co.za']);
        $this->assertSame(3, $limited->page_limit);
        $this->assertSame(3, $limited->pages_crawled);
        $this->assertSame(3, $limited->pages()->count());
        $this->assertSame(4, $limited->pages_found, 'l3 was found but not crawled');

        config(['indlela.audit.max_page_limit' => 2]);
        $capped = $this->audit(['audit_page_limit' => 500, 'domain' => 'capped-karoo.co.za']);
        $this->assertSame(2, $capped->page_limit, 'never above the hard max');
    }

    public function test_cancelling_stops_the_crawl_between_batches(): void
    {
        config(['indlela.audit.concurrency' => 1]);
        $s = self::S;
        $links = array_map(fn ($i) => "/p{$i}", range(1, 20));
        $this->web = ["{$s}/" => $this->html(links: $links)];
        foreach ($links as $path) {
            $this->web[$s.$path] = $this->html();
        }

        // The page /p3 is fetched → someone presses Cancel.
        $this->onRequest = function (Request $request) use ($s) {
            if ($request->url() === "{$s}/p3") {
                SiteAudit::query()->withoutGlobalScopes()->update(['status' => SiteAudit::CANCELLED, 'finished_at' => now()]);
            }
        };

        $audit = $this->audit();

        $this->assertSame(SiteAudit::CANCELLED, $audit->status);
        $this->assertSame(4, $audit->pages()->count(), 'stopped after the batch with /p3');
        $this->assertSame(4, $audit->pages_crawled);
        $this->assertSame(0, $audit->issues()->count(), 'not analysed');
        $this->assertNull($audit->health_score);
    }

    public function test_urls_are_normalised(): void
    {
        $this->assertSame('https://www.karoo.co.za/b?id=1&x=', CrawlUrl::normalise('HTTPS://WWW.Karoo.co.za:443/a/../b?utm_source=x&id=1&fbclid=y&x=#frag'));
        $this->assertSame('https://www.karoo.co.za/', CrawlUrl::normalise('https://www.karoo.co.za'));
        $this->assertSame('https://www.karoo.co.za/shop/item', CrawlUrl::normalise('../shop/item', 'https://www.karoo.co.za/blog/post'));
        $this->assertSame('https://www.karoo.co.za/blog/?p=2', CrawlUrl::normalise('?p=2&gclid=abc', 'https://www.karoo.co.za/blog/'));
        $this->assertSame('http://cdn.karoo.co.za:8080/x', CrawlUrl::normalise('//cdn.karoo.co.za:8080/x', 'http://www.karoo.co.za/'));
        $this->assertNull(CrawlUrl::normalise('mailto:hi@karoo.co.za'));
        $this->assertNull(CrawlUrl::normalise('tel:+27215550000'));
        $this->assertNull(CrawlUrl::normalise('javascript:void(0)'));
        $this->assertNull(CrawlUrl::normalise('#top', 'https://www.karoo.co.za/'));
        $this->assertTrue(CrawlUrl::isSameSite('https://karoo.co.za/x', 'karoo.co.za'));
        $this->assertTrue(CrawlUrl::isSameSite('http://www.karoo.co.za/x', 'karoo.co.za'));
        $this->assertFalse(CrawlUrl::isSameSite('https://shop.karoo.co.za/x', 'karoo.co.za'));
    }

    public function test_robots_rules_follow_googles_matching(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\nDisallow: /*?s=\nDisallow: /*.pdf$\n\nUser-agent: IndlelaBot\nUser-agent: OtherBot\nDisallow: /no-bots\n\nSitemap: https://x.co.za/s.xml");

        // IndlelaBot's own group replaces *'s.
        $this->assertTrue($robots->allows('/wp-admin/', 'indlelabot'));
        $this->assertFalse($robots->allows('/no-bots/page', 'indlelabot'));

        $this->assertFalse($robots->allows('/wp-admin/options.php', 'googlebot'));
        $this->assertTrue($robots->allows('/wp-admin/admin-ajax.php', 'googlebot'), 'longer Allow wins');
        $this->assertFalse($robots->allows('/?s=coffee', 'googlebot'));
        $this->assertFalse($robots->allows('/files/menu.pdf', 'googlebot'));
        $this->assertTrue($robots->allows('/files/menu.pdf?v=2', 'googlebot'), '$ anchors the end');
        $this->assertTrue($robots->allows('/shop', 'googlebot'));
        $this->assertSame(['https://x.co.za/s.xml'], $robots->sitemaps);
        $this->assertTrue(RobotsTxt::allowAll()->allows('/anything', 'indlelabot'));
    }

    public function test_html_is_read_from_the_page(): void
    {
        $s = self::S;
        $this->web = [
            "{$s}/" => $this->html('Karoo Coffee Roasters | Fresh beans', 'Fresh coffee', ['/a', ['/b', 'nofollow']], [
                'canonical' => "{$s}/",
                'h1' => ['First', 'Second'],
                'head' => '<meta name="robots" content="index, follow">',
                'body' => '<img src="/logo.png" alt="Logo"><img data-src="/lazy.jpg" src="data:image/gif;base64,x"><img src="https://cdn.other.com/p.png" alt=" ">',
                'words' => 250,
            ]),
            "{$s}/a" => $this->html(),
            "{$s}/b" => $this->html(),
            "{$s}/logo.png" => ['headers' => ['Content-Type' => 'image/png']],
            "{$s}/lazy.jpg" => ['headers' => ['Content-Type' => 'image/jpeg']],
        ];

        $audit = $this->audit();
        /** @var SiteAuditPage $home */
        $home = $audit->pages()->where('url', "{$s}/")->first();

        $this->assertSame('Karoo Coffee Roasters | Fresh beans', $home->title);
        $this->assertSame('Fresh coffee', $home->meta_description);
        $this->assertSame('First', $home->h1);
        $this->assertSame(2, $home->h1_count);
        $this->assertSame("{$s}/", $home->canonical);
        $this->assertSame('index, follow', $home->meta_robots);
        $this->assertTrue($home->is_indexable);
        $this->assertSame(3, $home->images_count);
        $this->assertSame(2, $home->images_missing_alt);
        $this->assertSame(["{$s}/lazy.jpg", 'https://cdn.other.com/p.png'], $home->extras['missing_alt_srcs']);
        $this->assertGreaterThanOrEqual(250, $home->word_count);
        $this->assertSame(2, $home->outlinks_count);
        $this->assertSame(1, $audit->pages()->where('url', "{$s}/a")->value('inlinks_count'));
        $this->assertTrue(SiteAuditLink::query()->where('audit_id', $audit->id)->where('to_url', "{$s}/b")->value('nofollow'));
        $this->assertSame(200, SiteAuditLink::query()->where('audit_id', $audit->id)->where('to_url', "{$s}/logo.png")->value('status_code'), 'internal images are HEAD-checked');
    }
}
