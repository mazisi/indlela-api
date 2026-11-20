<?php

namespace Tests\Support;

use App\Models\Client;
use App\Models\Site;
use App\Models\SiteAudit;
use App\Services\SiteAudit\PageSpeedInsights;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A fake web for the site-audit tests: `$this->web` maps full URLs to
 * responses (200 HTML by default), served through Http::fake so the real
 * HttpPageFetcher, robots.txt and sitemap code run. Anything not in the map
 * is a 404. PageSpeed Insights answers from `$this->psi` (or 200 with a
 * good score).
 */
trait SiteAuditHelpers
{
    /** @var array<string, array{status?: int, body?: string, headers?: array<string, string>}> */
    protected array $web = [];

    /** @var (callable(Request): mixed)|null */
    protected $psi = null;

    /** @var (callable(Request): void)|null called before each site request is answered */
    protected $onRequest = null;

    /** @var list<string> "METHOD url" of every request the fake served */
    protected array $requested = [];

    protected function fakeWeb(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_starts_with($url, PageSpeedInsights::ENDPOINT)) {
                return $this->psi ? ($this->psi)($request) : Http::response($this->psiBody(95));
            }

            $this->requested[] = $request->method().' '.$url;
            if ($this->onRequest) {
                ($this->onRequest)($request);
            }
            $page = $this->web[$url] ?? null;
            if ($page === null) {
                return Http::response('<html><body>Not found</body></html>', 404, ['Content-Type' => 'text/html']);
            }

            return Http::response($request->method() === 'HEAD' ? '' : ($page['body'] ?? ''), $page['status'] ?? 200, ['Content-Type' => 'text/html; charset=UTF-8', ...($page['headers'] ?? [])]);
        });
    }

    /** An HTML page. $links: href list; $extra: anything else for <head>/<body>. */
    protected function html(?string $title = 'A perfectly reasonable page title here', ?string $description = 'A description', array $links = [], array $options = []): array
    {
        $head = ($title !== null ? "<title>{$title}</title>" : '')
            .($description !== null ? '<meta name="description" content="'.$description.'">' : '')
            .implode('', array_map(fn ($c) => '<link rel="canonical" href="'.$c.'">', (array) ($options['canonical'] ?? [])))
            .($options['head'] ?? '');
        $h1s = implode('', array_map(fn ($h) => "<h1>{$h}</h1>", $options['h1'] ?? ['Main heading']));
        $anchors = implode(' ', array_map(fn ($href) => is_array($href) ? "<a href=\"{$href[0]}\" rel=\"{$href[1]}\">x</a>" : "<a href=\"{$href}\">link</a>", $links));
        $words = str_repeat('word ', $options['words'] ?? 300);

        return ['status' => $options['status'] ?? 200, 'headers' => $options['headers'] ?? [], 'body' => "<!doctype html><html><head>{$head}</head><body>{$h1s}<p>{$words}</p>{$anchors}".($options['body'] ?? '').'</body></html>'];
    }

    protected function redirect(string $to, int $status = 301): array
    {
        return ['status' => $status, 'headers' => ['Location' => $to], 'body' => ''];
    }

    protected function sitemap(array $urls): array
    {
        $entries = implode('', array_map(fn ($u) => "<url><loc>{$u}</loc></url>", $urls));

        return ['headers' => ['Content-Type' => 'application/xml'], 'body' => '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$entries.'</urlset>'];
    }

    protected function sitemapIndex(array $sitemaps): array
    {
        $entries = implode('', array_map(fn ($u) => "<sitemap><loc>{$u}</loc></sitemap>", $sitemaps));

        return ['headers' => ['Content-Type' => 'application/xml'], 'body' => '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$entries.'</sitemapindex>'];
    }

    protected function robots(string $body): array
    {
        return ['headers' => ['Content-Type' => 'text/plain'], 'body' => $body];
    }

    /** A PageSpeed Insights v5 body. */
    protected function psiBody(int $score, ?array $field = null): array
    {
        return [
            'lighthouseResult' => [
                'categories' => ['performance' => ['score' => $score / 100]],
                'audits' => [
                    'largest-contentful-paint' => ['numericValue' => 2400.4],
                    'cumulative-layout-shift' => ['numericValue' => 0.0512],
                    'total-blocking-time' => ['numericValue' => 150],
                    'first-contentful-paint' => ['numericValue' => 1200],
                    'speed-index' => ['numericValue' => 3100],
                ],
            ],
            'loadingExperience' => $field ?? [],
        ];
    }

    protected function auditSite(?Client $client = null, string $url = 'https://www.karoo.co.za', array $state = []): Site
    {
        $parts = Site::normalise($url);

        return Site::factory()->create(['client_id' => ($client ?? $this->makeClient())->id, 'url' => $parts['url'], 'domain' => $parts['domain'], 'name' => $parts['domain'], ...$state]);
    }

    /** A finished audit row (no crawl), for API tests. */
    protected function makeAudit(Site $site, array $state = []): SiteAudit
    {
        return SiteAudit::query()->withoutGlobalScopes()->create([
            'workspace_id' => $site->workspace_id, 'site_id' => $site->id, 'status' => SiteAudit::DONE, 'trigger' => SiteAudit::MANUAL,
            'page_limit' => 500, 'started_at' => now()->subHour(), 'finished_at' => now(), 'health_score' => 80,
            'counts' => ['critical' => 0, 'warning' => 0, 'notice' => 0, 'total' => 0], 'vitals_status' => SiteAudit::VITALS_DONE,
            ...$state,
        ]);
    }
}
