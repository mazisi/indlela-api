<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\GscDailyTotal;
use App\Models\GscQueryPageDaily;
use App\Models\Site;
use App\Models\Workspace;
use App\Services\SearchConsole\ExpectedCtr;
use App\Services\SearchConsole\SearchConsoleSync;
use App\Support\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo Search Console data, so every report has rows without a Google
 * account. Not part of DatabaseSeeder: run it on purpose,
 *
 *     php artisan db:seed --class=GscDemoSeeder
 *
 * In the first workspace it makes (if missing) the client "Demo Client" and
 * the site demo.indlela.local linked to `sc-domain:demo.indlela.local`, then
 * replaces that site's GSC data with ~16 months of generated days (~150
 * queries on 30 pages): CTR falls with position, weekends are quieter, some
 * queries sit at positions 5-15 with plenty of impressions (quick wins), some
 * page-one pages get few clicks (low CTR), and some pages lose traffic over
 * the last months (content decay). Deterministic: re-running gives the same
 * numbers (relative to yesterday). Touches no other site.
 */
class GscDemoSeeder extends Seeder
{
    private const DOMAIN = 'demo.indlela.local';

    private const PAGES = [
        '/', '/services', '/services/seo', '/services/web-design', '/services/google-ads', '/services/social-media',
        '/about', '/contact', '/pricing', '/case-studies', '/blog', '/blog/local-seo-guide',
        '/blog/google-business-profile-tips', '/blog/what-is-technical-seo', '/blog/keyword-research-for-smes',
        '/blog/seo-vs-google-ads', '/blog/how-long-does-seo-take', '/blog/website-speed-checklist',
        '/blog/content-calendar-template', '/blog/ecommerce-seo-south-africa', '/locations/johannesburg',
        '/locations/cape-town', '/locations/durban', '/locations/pretoria', '/locations/gqeberha',
        '/tools/seo-audit', '/tools/keyword-checker', '/faq', '/careers', '/blog/popia-website-compliance',
    ];

    /** Page-one pages whose titles don't earn the click. */
    private const LOW_CTR_PAGES = ['/services/google-ads', '/blog/what-is-technical-seo', '/pricing', '/locations/durban'];

    /** Pages losing traffic over the last ~5 months. */
    private const DECAYING_PAGES = ['/blog/local-seo-guide', '/blog/seo-vs-google-ads', '/blog/website-speed-checklist', '/locations/pretoria', '/tools/keyword-checker'];

    /** Monday … Sunday. */
    private const WEEKDAY = [1 => 1.06, 2 => 1.09, 3 => 1.07, 4 => 1.04, 5 => 0.95, 6 => 0.76, 7 => 0.8];

    public function run(CurrentWorkspace $current): void
    {
        $workspace = Workspace::query()->orderBy('id')->first();
        if (! $workspace) {
            $this->command?->warn('No workspace yet: run the default seeders first.');

            return;
        }

        $site = $current->using($workspace, function () {
            $client = Client::query()->firstOrCreate(['name' => 'Demo Client'], ['contact_name' => 'Demo Contact', 'is_active' => true, 'notes' => 'Made by GscDemoSeeder.']);
            $site = Site::query()->firstOrCreate(['domain' => self::DOMAIN], ['client_id' => $client->id, 'name' => self::DOMAIN, 'url' => 'https://'.self::DOMAIN]);

            DB::transaction(function () use ($site) {
                app(SearchConsoleSync::class)->purge($site);
                $through = $this->generate($site);
                $site->forceFill([
                    'gsc_property' => 'sc-domain:'.self::DOMAIN,
                    'gsc_sync_status' => Site::GSC_DONE,
                    'gsc_synced_at' => now(),
                    'gsc_data_through' => $through,
                    'gsc_sync_error' => null,
                ])->save();
            });

            return $site;
        });

        $this->command?->info(sprintf('Search Console demo data for site #%d (%s) in workspace "%s": %d query/page rows.',
            $site->id, self::DOMAIN, $workspace->name, GscQueryPageDaily::query()->where('site_id', $site->id)->count()));
    }

    /** Writes the rows; returns the last date with data. */
    private function generate(Site $site): CarbonImmutable
    {
        mt_srand(20261120);
        $end = CarbonImmutable::today()->subDays(3); // GSC lags 2-3 days
        $start = $end->subMonthsNoOverflow(16)->addDay();
        $totalDays = (int) $start->diffInDays($end) + 1;
        $queries = $this->queries();

        $buffer = [];
        $daily = [];
        for ($d = 0; $d < $totalDays; $d++) {
            $date = $start->addDays($d);
            $daysLeft = $totalDays - 1 - $d;
            $season = self::WEEKDAY[$date->dayOfWeekIso] * ($date->month === 12 && $date->day > 15 ? 0.8 : 1.0) * (0.85 + 0.25 * $d / $totalDays);

            foreach ($queries as $q) {
                foreach ($q['pages'] as [$page, $share, $positionOffset]) {
                    $decay = in_array($page, self::DECAYING_PAGES, true) && $daysLeft < 150 ? 1 - 0.6 * (150 - $daysLeft) / 150 : 1.0;
                    $impressions = (int) round($q['impressions'] * $share * $season * $decay * (0.7 + $this->rand() * 0.6));
                    if ($impressions < 1) {
                        continue;
                    }

                    $drift = $decay < 1 ? 3 * (1 - $decay) : 0; // decaying pages also slip in position
                    $position = max(1.0, round($q['position'] + $positionOffset + $drift + ($this->rand() - 0.5) * 1.2, 2));
                    $ctrFactor = in_array($page, self::LOW_CTR_PAGES, true) ? 0.3 : $q['ctr_factor'];
                    $clicks = min($impressions, (int) floor($impressions * ExpectedCtr::at($position) * $ctrFactor + $this->rand()));

                    $buffer[] = [
                        'workspace_id' => $site->workspace_id, 'site_id' => $site->id, 'date' => $date->toDateString(),
                        'query' => $q['query'], 'page' => 'https://'.self::DOMAIN.$page,
                        'query_hash' => md5($q['query']), 'page_hash' => md5('https://'.self::DOMAIN.$page),
                        'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $clicks / $impressions, 'position' => $position,
                    ];

                    $day = &$daily[$date->toDateString()];
                    $day['clicks'] = ($day['clicks'] ?? 0) + $clicks;
                    $day['impressions'] = ($day['impressions'] ?? 0) + $impressions;
                    $day['weight'] = ($day['weight'] ?? 0) + $position * $impressions;
                    unset($day);
                }
            }

            if (count($buffer) >= 2000) {
                GscQueryPageDaily::query()->insert($buffer);
                $buffer = [];
            }
        }
        if ($buffer) {
            GscQueryPageDaily::query()->insert($buffer);
        }

        // Daily totals include anonymised queries, so they sit above the query rows' sums.
        $totals = [];
        foreach ($daily as $date => $day) {
            $impressions = (int) round($day['impressions'] * 1.12);
            $clicks = (int) round($day['clicks'] * 1.08);
            $totals[] = [
                'workspace_id' => $site->workspace_id, 'site_id' => $site->id, 'date' => $date,
                'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $impressions ? $clicks / $impressions : 0,
                'position' => round($day['weight'] / $day['impressions'] + 0.4, 2),
            ];
        }
        foreach (array_chunk($totals, 1000) as $chunk) {
            GscDailyTotal::query()->insert($chunk);
        }

        return $end;
    }

    /**
     * ~150 queries: a few brand terms at #1, page-one terms, quick wins (5-15
     * with high impressions) and a long tail. Each has 1-2 pages: [path,
     * impression share, position offset].
     *
     * @return list<array{query: string, impressions: float, position: float, ctr_factor: float, pages: list<array{0: string, 1: float, 2: float}>}>
     */
    private function queries(): array
    {
        $services = ['seo', 'seo services', 'web design', 'google ads management', 'digital marketing agency', 'seo agency', 'local seo', 'website design', 'ppc agency', 'social media marketing'];
        $places = ['johannesburg', 'cape town', 'durban', 'pretoria', 'gqeberha', 'sandton', 'south africa', 'near me'];
        $topics = ['how long does seo take', 'what is technical seo', 'seo vs google ads', 'google business profile tips', 'keyword research tool',
            'website speed test', 'seo audit free', 'local seo checklist', 'content calendar template', 'ecommerce seo', 'popia website requirements',
            'seo pricing south africa', 'how much does a website cost', 'free keyword checker', 'seo case study'];

        $phrases = [];
        foreach ($services as $service) {
            foreach ($places as $place) {
                $phrases[] = "{$service} {$place}";
            }
        }
        $phrases = array_merge($topics, $phrases, array_map(fn (string $p) => "best {$p}", array_slice($phrases, 0, 50)));

        $list = [];
        foreach (['indlela', 'indlela seo', 'indlela digital', 'indlela contact', 'indlela reviews'] as $brand) {
            $list[] = ['query' => $brand, 'impressions' => 20 + $this->rand() * 40, 'position' => 1.1, 'ctr_factor' => 1.4, 'pages' => [['/', 1.0, 0.0]]];
        }

        foreach (array_slice(array_values(array_unique($phrases)), 0, 145) as $i => $phrase) {
            $kind = match (true) {
                $i < 35 => 'top',
                $i < 70 => 'quick',
                default => 'tail',
            };
            $page = $this->pageFor($phrase, $i, $kind);
            [$impressions, $position] = match ($kind) {
                'top' => [8 + $this->rand() * 30, 1.5 + $this->rand() * 3],
                'quick' => [20 + $this->rand() * 45, 6 + $this->rand() * 7],
                'tail' => [0.4 + $this->rand() * 6, 12 + $this->rand() * 28],
            };
            // Some queries split across two pages (the second ranks lower).
            $second = self::PAGES[($i * 7 + 3) % count(self::PAGES)];
            $pages = $second !== $page && $this->rand() < 0.3 ? [[$page, 0.75, 0.0], [$second, 0.25, 3.0]] : [[$page, 1.0, 0.0]];

            $list[] = ['query' => $phrase, 'impressions' => $impressions, 'position' => $position, 'ctr_factor' => 0.85 + $this->rand() * 0.3, 'pages' => $pages];
        }

        return $list;
    }

    /** A plausible landing page; page-one terms feed the low-CTR and decaying pages. */
    private function pageFor(string $phrase, int $i, string $kind): string
    {
        if ($kind === 'top' && $i < 20) {
            $special = array_merge(self::LOW_CTR_PAGES, self::DECAYING_PAGES);

            return $special[$i % count($special)];
        }
        foreach (['johannesburg', 'cape-town', 'durban', 'pretoria', 'gqeberha'] as $city) {
            if (str_contains($phrase, str_replace('-', ' ', $city))) {
                return "/locations/{$city}";
            }
        }
        foreach (['web design' => '/services/web-design', 'website design' => '/services/web-design', 'google ads' => '/services/google-ads',
            'ppc' => '/services/google-ads', 'social media' => '/services/social-media', 'seo' => '/services/seo'] as $needle => $path) {
            if (str_contains($phrase, $needle)) {
                return $path;
            }
        }

        return self::PAGES[$i % count(self::PAGES)];
    }

    private function rand(): float
    {
        return mt_rand() / mt_getrandmax();
    }
}
