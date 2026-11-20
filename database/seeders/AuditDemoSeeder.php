<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Site;
use App\Models\SiteAudit;
use App\Models\SiteAuditLink;
use App\Models\SiteAuditPage;
use App\Models\Workspace;
use App\Services\SiteAudit\AuditAnalyser;
use App\Services\SiteAudit\CrawlUrl;
use App\Services\SiteAudit\VitalsRunner;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo site audits, so the Site audit page has data without crawling. Not
 * part of DatabaseSeeder: run it on purpose,
 *
 *     php artisan db:seed --class=AuditDemoSeeder
 *
 * In the first workspace it uses (or makes) the client "Demo Client" and the
 * site demo.indlela.local (as GscDemoSeeder does), deletes that site's
 * audits and builds two finished ones from hand-made pages and links: one
 * two weeks ago and one yesterday. The real analyser turns them into issues
 * (every severity), so scores, counts and the comparison ("fixed / new")
 * are the real maths; PageSpeed results are made up. Touches no other site.
 */
class AuditDemoSeeder extends Seeder
{
    private const DOMAIN = 'demo.indlela.local';

    private const HOME = 'https://'.self::DOMAIN.'/';

    private const NAV = ['/', '/services', '/services/seo', '/services/web-design', '/about', '/pricing', '/blog', '/contact'];

    public function __construct(private readonly AuditAnalyser $analyser, private readonly VitalsRunner $vitals) {}

    public function run(CurrentWorkspace $current): void
    {
        $workspace = Workspace::query()->orderBy('id')->first();
        if (! $workspace) {
            $this->command?->warn('No workspace yet: run the default seeders first.');

            return;
        }

        [$previous, $latest] = $current->using($workspace, function () {
            $client = Client::query()->firstOrCreate(['name' => 'Demo Client'], ['contact_name' => 'Demo Contact', 'is_active' => true, 'notes' => 'Made by GscDemoSeeder.']);
            $site = Site::query()->firstOrCreate(['domain' => self::DOMAIN], ['client_id' => $client->id, 'name' => self::DOMAIN, 'url' => 'https://'.self::DOMAIN]);
            $site->forceFill(['audit_schedule' => Site::AUDIT_WEEKLY, 'audit_page_limit' => 500])->save();

            SiteAudit::query()->where('site_id', $site->id)->delete();

            return [
                $this->build($site, previous: true, finished: now()->subDays(14)->setTime(2, 18)),
                $this->build($site, previous: false, finished: now()->subDay()->setTime(2, 21)),
            ];
        });

        $this->command?->info(sprintf(
            'Site audit demo for site #%d (%s) in "%s": audit #%d (previous, score %d) and #%d (latest, score %d, %d issues; %d fixed, %d new).',
            $latest->site_id, self::DOMAIN, $workspace->name, $previous->id, $previous->health_score, $latest->id, $latest->health_score,
            $latest->counts['total'], $latest->comparison['fixed'] ?? 0, $latest->comparison['new'] ?? 0,
        ));
    }

    private function build(Site $site, bool $previous, \DateTimeInterface $finished): SiteAudit
    {
        $pages = $this->pages($previous);
        $audit = SiteAudit::query()->create([
            'workspace_id' => $site->workspace_id, 'site_id' => $site->id, 'status' => SiteAudit::ANALYSING,
            'trigger' => $previous ? SiteAudit::MANUAL : SiteAudit::SCHEDULED, 'page_limit' => 500,
            'pages_crawled' => count($pages), 'pages_found' => count($pages) + 6,
            'started_at' => (clone $finished)->modify('-4 minutes'), 'vitals_status' => SiteAudit::VITALS_PENDING,
            'crawl_meta' => ['home_url' => self::HOME, 'scheme' => 'https', 'robots_found' => true, 'robots_blocks_site' => false,
                'sitemap_found' => true, 'sitemaps' => ['https://'.self::DOMAIN.'/sitemap_index.xml', 'https://'.self::DOMAIN.'/page-sitemap.xml'], 'sitemap_urls' => 34],
        ]);

        DB::transaction(function () use ($audit, $pages) {
            $ids = [];
            foreach ($pages as $path => $p) {
                $url = self::url($path);
                $ids[$path] = SiteAuditPage::query()->create([
                    'audit_id' => $audit->id, 'url' => $url, 'url_hash' => CrawlUrl::hash($url), 'depth' => $p['depth'],
                    'status_code' => $p['status'], 'final_status_code' => $p['final_status'], 'final_url' => $p['final_url'],
                    'redirect_chain' => $p['chain'], 'content_type' => $p['status'] === 200 ? 'text/html; charset=UTF-8' : 'text/html',
                    'response_ms' => $p['ms'], 'title' => $p['title'], 'meta_description' => $p['description'], 'h1' => $p['h1s'][0] ?? null,
                    'h1_count' => count($p['h1s']), 'canonical' => $p['canonical'], 'canonical_count' => $p['canonical'] ? 1 : 0,
                    'meta_robots' => $p['robots'], 'is_indexable' => $p['indexable'], 'in_sitemap' => $p['sitemap'],
                    'word_count' => $p['words'], 'images_count' => $p['images'], 'images_missing_alt' => $p['missing_alt'],
                    'extras' => $p['missing_alt'] ? ['missing_alt_srcs' => array_map(fn ($i) => self::url("/wp-content/uploads/2026/10/photo-{$i}.jpg"), range(1, $p['missing_alt']))] : null,
                ])->id;
            }

            $rows = [];
            foreach ($pages as $path => $p) {
                foreach ($p['links'] as $target) {
                    $external = str_starts_with($target, 'http');
                    $to = $external ? $target : self::url($target);
                    $status = $external ? ($target === 'https://www.seo-tips-archive.co.za/old-guide' ? 404 : 200) : ($pages[$target]['status'] ?? null);
                    $rows[] = ['audit_id' => $audit->id, 'from_page_id' => $ids[$path], 'to_url' => $to, 'to_url_hash' => CrawlUrl::hash($to),
                        'kind' => SiteAuditLink::LINK, 'is_internal' => ! $external, 'status_code' => $status, 'nofollow' => false];
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                SiteAuditLink::query()->insert($chunk);
            }
        });

        $this->analyser->analyse($audit);
        $audit->forceFill(['status' => SiteAudit::DONE, 'finished_at' => $finished, 'created_at' => (clone $finished)->modify('-5 minutes')])->save();

        // PageSpeed: home slow on mobile, /services middling, the rest fine.
        $runs = [];
        $results = [];
        foreach (['/', '/services', '/blog', '/services/seo', '/contact'] as $i => $path) {
            foreach (['mobile', 'desktop'] as $strategy) {
                $score = match (true) {
                    $path === '/' && $strategy === 'mobile' => $previous ? 38 : 44,
                    $path === '/services' && $strategy === 'mobile' => 71,
                    $strategy === 'mobile' => 86 - $i,
                    default => 97 - $i,
                };
                $runs[] = [self::url($path), $strategy];
                $results[] = [
                    'status' => 'done', 'performance_score' => $score, 'error' => null, 'quota' => false,
                    'lab' => ['lcp_ms' => (int) (1200 + (100 - $score) * 65), 'cls' => round((100 - $score) / 900, 3), 'tbt_ms' => (int) ((100 - $score) * 9),
                        'fcp_ms' => (int) (800 + (100 - $score) * 20), 'si_ms' => (int) (1500 + (100 - $score) * 45)],
                    'field' => $path === '/' && $strategy === 'mobile' ? [
                        'lcp_ms' => 3900, 'lcp_category' => 'needs_improvement', 'inp_ms' => 240, 'inp_category' => 'needs_improvement',
                        'cls' => 0.08, 'cls_category' => 'good', 'category' => 'needs_improvement', 'origin_fallback' => true,
                    ] : null,
                ];
            }
        }
        $this->vitals->store($audit, $runs, $results);
        $audit->forceFill(['vitals_status' => SiteAudit::VITALS_DONE, 'vitals_message' => null])->save();
        $this->analyser->summarise($audit);

        return $audit->refresh();
    }

    /**
     * path => page. Problems differ between the two audits so the comparison
     * has something fixed and something new.
     *
     * @return array<string, array<string, mixed>>
     */
    private function pages(bool $previous): array
    {
        $title = fn (string $topic) => "{$topic} | Indlela Digital Johannesburg";
        $pages = [];
        $add = function (string $path, array $p = []) use (&$pages, $title) {
            $pages[$path] = [
                'depth' => 1, 'status' => 200, 'final_status' => 200, 'final_url' => self::url($path), 'chain' => null, 'ms' => 380 + (crc32($path) % 500),
                'title' => $title(ucwords(trim(str_replace(['/', '-'], [' ', ' '], $path)) ?: 'SEO and web design agency')),
                'description' => 'Indlela Digital helps South African businesses grow with '.(trim(str_replace(['/', '-'], ' ', $path)) ?: 'SEO').'. Get a free quote today.',
                'h1s' => ['Heading'], 'canonical' => self::url($path), 'robots' => null, 'indexable' => true, 'sitemap' => true,
                'words' => 650 + (crc32($path) % 900), 'images' => 6, 'missing_alt' => 0, 'links' => self::NAV,
                ...$p,
            ];
        };

        $add('/', ['depth' => 0, 'links' => [...self::NAV, '/services/google-ads', '/services/social-media', '/case-studies', '/tools/seo-audit', '/tools/keyword-checker', '/locations/johannesburg', '/locations/cape-town']]);
        $add('/services', ['description' => $previous ? null : 'Indlela Digital helps South African businesses grow with SEO, web design and Google Ads.', 'links' => [...self::NAV, '/services/google-ads', '/services/social-media']]);
        $add('/services/seo', ['h1s' => $previous ? [] : ['SEO services']]);
        $add('/services/web-design', ['missing_alt' => $previous ? 2 : 0]);
        $add('/services/google-ads', ['depth' => 2]);
        $add('/services/social-media', ['depth' => 2, 'missing_alt' => 3]);
        $add('/about', ['h1s' => ['About us', 'Our team'], 'missing_alt' => $previous ? 4 : 0, 'links' => [...self::NAV, '/old-services', '/careers']]);
        $add('/pricing', ['h1s' => [], 'ms' => 2650]);
        $add('/contact', ['words' => 120, 'description' => null]);
        $add('/case-studies', ['depth' => 1, 'missing_alt' => 8, 'ms' => 2240]);
        $add('/faq', ['depth' => 2, 'title' => 'FAQ', 'words' => 160]);
        $add('/careers', ['depth' => 2, 'robots' => 'noindex, follow', 'indexable' => false]);
        $add('/tools/seo-audit', $previous ? [] : ['status' => 500, 'final_status' => 500, 'title' => null, 'description' => null, 'h1s' => [], 'canonical' => null, 'indexable' => false, 'words' => 0, 'images' => 0, 'links' => [], 'ms' => 8200]);
        $add('/tools/keyword-checker', ['canonical' => null]);

        $posts = ['local-seo-guide', 'google-business-profile-tips', 'what-is-technical-seo', 'keyword-research-for-smes', 'seo-vs-google-ads',
            'how-long-does-seo-take', 'website-speed-checklist', 'content-calendar-template', 'ecommerce-seo-south-africa', 'popia-website-compliance'];
        $add('/blog', ['links' => [...self::NAV, ...array_map(fn ($p) => "/blog/{$p}", $posts), '/faq']]);
        foreach ($posts as $i => $post) {
            $links = [...self::NAV, '/blog/'.$posts[($i + 1) % count($posts)], '/services/seo'];
            if (in_array($post, ['local-seo-guide', 'keyword-research-for-smes'], true) || ($previous && $post === 'seo-vs-google-ads')) {
                $links[] = '/blog/old-post';
            }
            if ($post === 'local-seo-guide') {
                $links[] = 'https://www.seo-tips-archive.co.za/old-guide';
                $links[] = 'https://developers.google.com/search/docs';
            }
            if ($post === 'website-speed-checklist') {
                $links[] = '/promo';
            }
            $add("/blog/{$post}", [
                'depth' => 2, 'links' => $links, 'missing_alt' => $i % 3 === 0 ? 2 : 0,
                'title' => match ($post) {
                    'what-is-technical-seo' => 'What is technical SEO? A complete beginner-friendly guide for South African small businesses',
                    'seo-vs-google-ads' => $previous ? 'SEO vs Google Ads: which one should your small business choose in 2026 and why?' : $title('SEO vs Google Ads'),
                    'ecommerce-seo-south-africa' => 'Ecommerce SEO in South Africa: how to rank your WooCommerce store on Google',
                    default => $title(ucwords(str_replace('-', ' ', $post))),
                },
                'canonical' => $post === 'content-calendar-template' ? self::url('/blog') : self::url("/blog/{$post}"),
                'indexable' => $post !== 'content-calendar-template',
                'words' => $post === 'popia-website-compliance' ? 180 : 900 + $i * 120,
            ]);
        }

        // Locations: two share a title, three have no description.
        foreach (['johannesburg', 'cape-town', 'durban', 'pretoria', 'gqeberha'] as $city) {
            $add("/locations/{$city}", [
                'depth' => 1, 'links' => [...self::NAV, '/locations/durban', '/locations/pretoria', '/locations/gqeberha'],
                'title' => in_array($city, ['durban', 'pretoria'], true) ? 'SEO agency near you | Indlela Digital' : $title('SEO agency in '.ucwords(str_replace('-', ' ', $city))),
                'description' => in_array($city, ['durban', 'pretoria', 'gqeberha'], true) ? null : "SEO and web design for businesses in {$city}.",
            ]);
        }

        // Redirects and errors.
        $redirect = fn (array $hops) => ['status' => 301, 'final_status' => 200, 'final_url' => self::url(end($hops)), 'title' => null, 'description' => null, 'h1s' => [],
            'canonical' => null, 'indexable' => false, 'words' => 0, 'images' => 0, 'links' => [], 'sitemap' => false, 'ms' => 120,
            'chain' => array_map(fn ($p, $i) => ['url' => self::url($p), 'status_code' => $i === count($hops) - 1 ? 200 : 301], $hops, array_keys($hops))];
        $add('/old-services', ['depth' => 2, ...$redirect(['/old-services', '/services'])]);
        $add('/promo', ['depth' => 3, ...$redirect(['/promo', '/promo-2026', '/pricing'])]);
        $add('/blog/old-post', ['depth' => 3, 'status' => 404, 'final_status' => 404, 'title' => 'Page not found', 'description' => null, 'h1s' => [],
            'canonical' => null, 'indexable' => false, 'words' => 40, 'images' => 0, 'links' => [], 'sitemap' => false]);

        // Only in the sitemap (nobody links to it).
        if (! $previous) {
            $add('/landing/black-friday', ['depth' => null, 'links' => []]);
        }

        return $pages;
    }

    private static function url(string $path): string
    {
        return 'https://'.self::DOMAIN.$path;
    }
}
