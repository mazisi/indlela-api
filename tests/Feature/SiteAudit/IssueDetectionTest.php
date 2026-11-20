<?php

namespace Tests\Feature\SiteAudit;

use App\Models\SiteAudit;
use App\Models\SiteAuditIssue;
use App\Services\SiteAudit\FetchResult;
use App\Services\SiteAudit\HttpPageFetcher;
use App\Services\SiteAudit\PageFetcher;
use App\Services\SiteAudit\SiteAudits;
use App\Support\AuditIssues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthTestHelpers;
use Tests\Support\SiteAuditHelpers;
use Tests\TestCase;

/**
 * A hand-built site with one example of each crawl issue, and the
 * health score and comparison maths.
 */
class IssueDetectionTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase, SiteAuditHelpers;

    private const S = 'https://www.karoo.co.za';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->fakeWeb();

        // Http::fake can't be slow: pretend /slow took 3.5 s.
        $this->app->bind(PageFetcher::class, fn () => new class extends HttpPageFetcher
        {
            public function fetch(array $urls, string $method = 'GET'): array
            {
                return array_map(fn (FetchResult $r) => str_contains((string) $r->body, 'SLOW-PAGE')
                    ? new FetchResult($r->status, $r->headers, $r->body, 3500) : $r, parent::fetch($urls, $method));
            }
        });
    }

    /** A healthy page at $path (self canonical, good title and description), with overrides. */
    private function page(string $path, array $links = [], array $options = [], ?string $title = null, ?string $description = null): array
    {
        $title ??= 'Karoo Coffee page '.$path.' | Karoo Coffee Roasters';
        $description ??= 'Everything about '.$path.' at Karoo Coffee.';

        return $this->html($title, $description, $links, ['canonical' => self::S.$path, ...$options]);
    }

    private function audit(string $url = self::S): SiteAudit
    {
        return app(SiteAudits::class)->start($this->auditSite(url: $url), null);
    }

    /** @return array<string, list<string>> type => URLs */
    private function issuesOf(SiteAudit $audit): array
    {
        return SiteAuditIssue::query()->where('audit_id', $audit->id)->orderBy('url')->get()
            ->groupBy('type')->map(fn ($rows) => $rows->pluck('url')->all())->all();
    }

    public function test_each_crawl_issue_is_detected_on_a_hand_built_site(): void
    {
        $s = self::S;
        $long = str_repeat('Long title words ', 5); // 85 chars
        $longDescription = str_repeat('A long description. ', 9); // 180 chars

        $this->web = [
            "{$s}/sitemap.xml" => $this->sitemap(["{$s}/", "{$s}/about", "{$s}/orphan", "{$s}/noindex-in-sitemap"]),
            "{$s}/" => $this->page('/', [
                '/about', '/broken', '/server-error', '/moved', '/chain-start', '/loop-a', '/noindex-linked', '/dup-1', '/dup-2',
                '/long-title', '/short-title', '/no-title', '/no-desc', '/long-desc', '/no-h1', '/multi-h1', '/alt', '/canon-broken',
                '/canon-other', '/multi-canon', '/no-canon', '/thin', '/slow', 'https://gone.example.com/', 'https://blocked.example.com/',
            ], ['body' => '<img src="/img/missing.png" alt="Missing">']),
            "{$s}/about" => $this->page('/about', ['/']),
            "{$s}/server-error" => ['status' => 500, 'body' => 'Oops'],
            "{$s}/moved" => $this->redirect("{$s}/about"),
            "{$s}/chain-start" => $this->redirect("{$s}/chain-mid"),
            "{$s}/chain-mid" => $this->redirect("{$s}/about"),
            "{$s}/loop-a" => $this->redirect("{$s}/loop-b", 302),
            "{$s}/loop-b" => $this->redirect("{$s}/loop-a", 302),
            "{$s}/noindex-linked" => $this->page('/noindex-linked', [], ['head' => '<meta name="robots" content="noindex, follow">']),
            "{$s}/noindex-in-sitemap" => $this->page('/noindex-in-sitemap', [], ['headers' => ['X-Robots-Tag' => 'noindex']]),
            "{$s}/orphan" => $this->page('/orphan'),
            "{$s}/dup-1" => $this->page('/dup-1', title: 'The same title on two different pages', description: 'The same description'),
            "{$s}/dup-2" => $this->page('/dup-2', title: 'The same title on two different pages', description: 'The same description'),
            "{$s}/long-title" => $this->page('/long-title', title: $long),
            "{$s}/short-title" => $this->page('/short-title', title: 'Short'),
            "{$s}/no-title" => $this->html(null, 'Has a description', [], ['canonical' => "{$s}/no-title"]),
            "{$s}/no-desc" => $this->html('A page without any meta description set', null, [], ['canonical' => "{$s}/no-desc"]),
            "{$s}/long-desc" => $this->page('/long-desc', description: $longDescription),
            "{$s}/no-h1" => $this->page('/no-h1', [], ['h1' => []]),
            "{$s}/multi-h1" => $this->page('/multi-h1', [], ['h1' => ['One', 'Two']]),
            "{$s}/alt" => $this->page('/alt', [], ['body' => '<img src="/img/a.png" alt="Fine"><img src="/img/b.png">']),
            "{$s}/canon-broken" => $this->page('/canon-broken', [], ['canonical' => "{$s}/broken"]),
            "{$s}/canon-other" => $this->page('/canon-other', [], ['canonical' => "{$s}/about"]),
            "{$s}/multi-canon" => $this->page('/multi-canon', [], ['canonical' => ["{$s}/multi-canon", "{$s}/about"]]),
            "{$s}/no-canon" => $this->html('A page with no canonical tag at all here', 'Desc', [], []),
            "{$s}/thin" => $this->page('/thin', [], ['words' => 20]),
            "{$s}/slow" => $this->page('/slow', [], ['body' => 'SLOW-PAGE']),
            "{$s}/img/a.png" => ['headers' => ['Content-Type' => 'image/png']],
            "{$s}/img/b.png" => ['headers' => ['Content-Type' => 'image/png']],
            'https://blocked.example.com/' => ['status' => 403],
        ];

        $audit = $this->audit();
        $issues = $this->issuesOf($audit);

        $this->assertSame(SiteAudit::DONE, $audit->status);
        $this->assertEqualsCanonicalizing([
            'page_5xx', 'page_4xx', 'broken_internal_link', 'broken_external_link', 'redirect_chain', 'redirect_loop', 'internal_link_to_redirect',
            'missing_title', 'duplicate_title', 'title_too_long', 'title_too_short', 'missing_meta_description', 'duplicate_meta_description',
            'meta_description_too_long', 'missing_h1', 'multiple_h1', 'images_missing_alt', 'noindex_in_sitemap', 'noindex_linked_internally',
            'canonical_to_non_200', 'canonical_to_other_page', 'multiple_canonicals', 'missing_canonical', 'orphan_page', 'slow_response', 'thin_content',
        ], array_keys($issues));

        $this->assertSame(["{$s}/server-error"], $issues['page_5xx']);
        $this->assertSame(["{$s}/broken"], $issues['page_4xx']);
        // From the home page: /broken (404), /server-error (500) and the 404 image.
        $broken = SiteAuditIssue::query()->where('audit_id', $audit->id)->where('type', 'broken_internal_link')->get();
        $this->assertEqualsCanonicalizing(["{$s}/broken", "{$s}/server-error", "{$s}/img/missing.png"], $broken->pluck('details.to_url')->all());
        $this->assertSame(['image'], $broken->where('details.to_url', "{$s}/img/missing.png")->pluck('details.kind')->all());
        // /canon-broken's canonical is crawled (404) but nobody links it: only the home page's links count.
        $this->assertSame(["{$s}/"], array_values(array_unique($issues['broken_internal_link'])));
        $this->assertSame(['https://gone.example.com/'], SiteAuditIssue::query()->where('audit_id', $audit->id)->where('type', 'broken_external_link')->pluck('details')->pluck('to_url')->all(), '403 is a blocked check, not broken');
        $this->assertSame(["{$s}/chain-start"], $issues['redirect_chain']);
        $this->assertSame(["{$s}/loop-a"], $issues['redirect_loop']);
        $this->assertEqualsCanonicalizing(["{$s}/moved", "{$s}/chain-start", "{$s}/loop-a"], SiteAuditIssue::query()->where('audit_id', $audit->id)->where('type', 'internal_link_to_redirect')->get()->pluck('details.to_url')->all());
        $this->assertSame(["{$s}/no-title"], $issues['missing_title']);
        $this->assertSame(["{$s}/dup-1", "{$s}/dup-2"], $issues['duplicate_title']);
        $this->assertSame(["{$s}/dup-1", "{$s}/dup-2"], $issues['duplicate_meta_description']);
        $this->assertSame(["{$s}/long-title"], $issues['title_too_long']);
        $this->assertSame(["{$s}/short-title"], $issues['title_too_short']);
        $this->assertSame(["{$s}/no-desc"], $issues['missing_meta_description']);
        $this->assertSame(["{$s}/long-desc"], $issues['meta_description_too_long']);
        $this->assertSame(["{$s}/no-h1"], $issues['missing_h1']);
        $this->assertSame(["{$s}/multi-h1"], $issues['multiple_h1']);
        $this->assertSame(["{$s}/alt"], $issues['images_missing_alt']);
        $this->assertSame(["{$s}/noindex-in-sitemap"], $issues['noindex_in_sitemap']);
        $this->assertSame(["{$s}/noindex-linked"], $issues['noindex_linked_internally']);
        $this->assertSame(["{$s}/canon-broken"], $issues['canonical_to_non_200']);
        $this->assertSame(["{$s}/canon-other"], $issues['canonical_to_other_page']);
        $this->assertSame(["{$s}/multi-canon"], $issues['multiple_canonicals']);
        $this->assertSame(["{$s}/no-canon"], $issues['missing_canonical']);
        $this->assertEqualsCanonicalizing(["{$s}/orphan", "{$s}/noindex-in-sitemap"], $issues['orphan_page']);
        $this->assertSame(["{$s}/slow"], $issues['slow_response']);
        $this->assertSame(["{$s}/thin"], $issues['thin_content']);

        $dup = SiteAuditIssue::query()->where('audit_id', $audit->id)->where('type', 'duplicate_title')->where('url', "{$s}/dup-1")->first();
        $this->assertSame(['title' => 'The same title on two different pages', 'pages' => 2, 'other_urls' => ["{$s}/dup-2"]], $dup->details);

        // Every severity matches the catalogue; counts add up.
        foreach (SiteAuditIssue::query()->where('audit_id', $audit->id)->get() as $issue) {
            $this->assertSame(AuditIssues::severity($issue->type), $issue->severity);
        }
        $this->assertSame(SiteAuditIssue::query()->where('audit_id', $audit->id)->count(), $audit->counts['total']);
        $this->assertSame(SiteAuditIssue::query()->where('audit_id', $audit->id)->where('severity', 'critical')->count(), $audit->counts['critical']);
    }

    public function test_site_wide_issues(): void
    {
        // Plain http, no sitemap, home page noindex.
        $this->web = ['http://karoo-plain.co.za/' => $this->html('A home page that is set to noindex here', 'Desc', [], ['head' => '<meta name="robots" content="noindex">'])];

        $audit = $this->audit('http://karoo-plain.co.za');
        $issues = $this->issuesOf($audit);

        $this->assertArrayHasKey('homepage_noindex', $issues);
        $this->assertArrayHasKey('sitemap_missing', $issues);
        $this->assertArrayHasKey('http_not_https', $issues);
        $this->assertNull(SiteAuditIssue::query()->where('audit_id', $audit->id)->where('type', 'http_not_https')->value('page_id'));
        // 100 − 25 (homepage_noindex) − 5 (sitemap) − 25 (http).
        $this->assertSame(45, $audit->health_score);
    }

    public function test_an_http_site_that_redirects_to_https_is_not_flagged(): void
    {
        $this->web = [
            'http://karoo-tls.co.za/' => $this->redirect('https://karoo-tls.co.za/'),
            'https://karoo-tls.co.za/' => $this->html(),
        ];

        $audit = $this->audit('http://karoo-tls.co.za');

        $this->assertFalse($audit->issues()->where('type', 'http_not_https')->exists());
    }

    public function test_health_score(): void
    {
        $s = self::S;
        // 4 pages: home (critical: links to a 404), /a (warning: no H1), /b (two notices), /c (critical: 404).
        $this->web = [
            "{$s}/sitemap.xml" => $this->sitemap(["{$s}/", "{$s}/a", "{$s}/b"]),
            "{$s}/" => $this->page('/', ['/a', '/b', '/c']),
            "{$s}/a" => $this->page('/a', [], ['h1' => []]),
            "{$s}/b" => $this->page('/b', [], ['words' => 50], title: 'Short title'),
        ];

        $audit = $this->audit();

        $this->assertSame(['critical' => 2, 'warning' => 1, 'notice' => 2, 'total' => 5], $audit->counts);
        // 100 − 50 × 2/4 − 30 × 1/4 − 10 × 1/4 = 65.
        $this->assertSame(65, $audit->health_score);
    }

    public function test_comparison_with_the_previous_audit(): void
    {
        $s = self::S;
        $this->web = [
            "{$s}/sitemap.xml" => $this->sitemap(["{$s}/", "{$s}/a", "{$s}/b"]),
            "{$s}/" => $this->page('/', ['/a', '/b']),
            "{$s}/a" => $this->page('/a', [], ['h1' => []]),           // missing_h1: fixed next time
            "{$s}/b" => $this->page('/b', [], ['words' => 50]),         // thin_content: persists
        ];
        $site = $this->auditSite();
        $first = app(SiteAudits::class)->start($site, null);

        $this->assertNull($first->comparison);
        $this->assertSame(0, $first->issues()->where('is_new', true)->count());

        $this->web["{$s}/a"] = $this->page('/a');
        $this->web["{$s}/b"] = $this->page('/b', [], ['words' => 50, 'h1' => ['One', 'Two']]); // multiple_h1: new
        $second = app(SiteAudits::class)->start($site, null);

        $this->assertSame(['fixed' => 1, 'new' => 1, 'persisting' => 1, 'previous_audit_id' => $first->id], $second->comparison);
        $this->assertSame($first->id, $second->previous_audit_id);
        $this->assertSame(['multiple_h1'], $second->issues()->where('is_new', true)->pluck('type')->all());

        $owner = $this->ownerOf($this->workspace());
        $this->actingAs($owner, 'sanctum')->getJson("/api/audits/{$second->id}/comparison?filter=fixed")
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0', ['type' => 'missing_h1', 'severity' => 'warning', 'title' => 'Missing H1 heading', 'url' => "{$s}/a", 'details' => null]);
        $this->actingAs($owner, 'sanctum')->getJson("/api/audits/{$second->id}/comparison")
            ->assertOk()->assertJsonPath('filter', 'new')->assertJsonPath('data.0.type', 'multiple_h1')->assertJsonPath('comparison.fixed', 1);
        $this->actingAs($owner, 'sanctum')->getJson("/api/audits/{$second->id}/issues")
            ->assertOk()->assertJsonFragment(['type' => 'multiple_h1', 'count' => 1, 'new_count' => 1])
            ->assertJsonFragment(['type' => 'thin_content', 'count' => 1, 'new_count' => 0]);
        $this->actingAs($owner, 'sanctum')->getJson("/api/audits/{$first->id}/comparison?filter=fixed")
            ->assertOk()->assertJsonPath('meta.total', 0);
    }
}
