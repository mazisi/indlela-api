<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\RankCheck;
use App\Models\RankKeyword;
use App\Models\RankLocation;
use App\Models\Site;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo rank tracking, so the Rank tracking page has data without
 * DataForSEO. Not part of DatabaseSeeder: run it on purpose,
 *
 *     php artisan db:seed --class=RankDemoSeeder
 *
 * In the first workspace it uses (or makes) the client "Demo Client" and the
 * site demo.indlela.local (as the other demo seeders do), removes that
 * site's tracked keywords and checks, and adds 60 keywords (10 phrases x
 * South Africa, Johannesburg and a Sandton coordinate x desktop and mobile)
 * with 90 days of made-up daily results ending today (weekly ones every 7th
 * day): trends, gains and drops this week, keywords outside the top 100,
 * ranking-URL switches (cannibalisation) and SERP features incl. AI
 * overviews. Deterministic (seeded random numbers). Touches no other site.
 *
 * The demo keywords are active: once DataForSEO is configured the nightly
 * job checks them too (in sandbox mode for free).
 */
class RankDemoSeeder extends Seeder
{
    private const DOMAIN = 'demo.indlela.local';

    private const BASE = 'https://'.self::DOMAIN;

    private const DAYS = 90;

    /** phrase => [start position, end position (null = not ranking), landing page, competing page|null]. */
    private const PHRASES = [
        'seo agency johannesburg' => [18, 4, '/services/seo', null],
        'seo services south africa' => [9, 6, '/services/seo', '/blog/seo-guide'],
        'web design sandton' => [35, 12, '/services/web-design', null],
        'digital marketing agency' => [62, 41, '/', null],
        'local seo' => [14, 21, '/services/local-seo', '/blog/local-seo-checklist'],
        'google ads management' => [null, 88, '/services/google-ads', null],
        'seo company near me' => [7, 2, '/contact', null],
        'website audit' => [26, 9, '/services/website-audit', '/blog/website-audit-checklist'],
        'best seo agency in gauteng' => [3, 1, '/', null],
        'ecommerce seo' => [44, null, '/services/ecommerce-seo', null],
    ];

    public function run(CurrentWorkspace $current): void
    {
        $workspace = Workspace::query()->orderBy('id')->first();
        if (! $workspace) {
            $this->command?->warn('No workspace yet: run the default seeders first.');

            return;
        }

        mt_srand(2710);

        [$site, $keywords, $checks] = $current->using($workspace, function () {
            $client = Client::query()->firstOrCreate(['name' => 'Demo Client'], ['contact_name' => 'Demo Contact', 'is_active' => true, 'notes' => 'Made by RankDemoSeeder.']);
            $site = Site::query()->firstOrCreate(['domain' => self::DOMAIN], ['client_id' => $client->id, 'name' => self::DOMAIN, 'url' => self::BASE]);

            RankKeyword::withTrashed()->where('site_id', $site->id)->forceDelete(); // checks cascade

            $keywords = 0;
            $checks = 0;
            foreach (self::PHRASES as $phrase => $shape) {
                foreach ($this->locations() as $l => $location) {
                    foreach (RankKeyword::DEVICES as $device) {
                        // Johannesburg on mobile is checked weekly, on Mondays.
                        $weekly = $l === 1 && $device === RankKeyword::MOBILE;
                        $keyword = RankKeyword::query()->create([
                            ...$location, 'site_id' => $site->id, 'keyword' => $phrase, 'device' => $device, 'language_code' => 'en',
                            'frequency' => $weekly ? RankKeyword::WEEKLY : RankKeyword::DAILY, 'weekly_day' => 1,
                            'tags' => $this->tags($phrase), 'is_active' => true,
                        ]);
                        $keyword->forceFill(['created_at' => today()->subDays(self::DAYS)->setTime(9, 0)])->saveQuietly();
                        $checks += $this->checks($keyword, $shape, $l, $device, $weekly);
                        $keywords++;
                    }
                }
            }

            return [$site, $keywords, $checks];
        });

        $this->command?->info(sprintf('Rank tracking demo for site #%d (%s) in "%s" (#%d): %d keywords, %d checks over %d days.',
            $site->id, self::DOMAIN, $workspace->name, $workspace->id, $keywords, $checks, self::DAYS));
    }

    /** @return list<array<string, mixed>> */
    private function locations(): array
    {
        // Johannesburg's DataForSEO code (checked against the live list:
        // "Johannesburg,City of Johannesburg Metropolitan Municipality,Gauteng,South Africa").
        // Not 1028768, which is Wynberg, Western Cape.
        $joburg = RankLocation::query()->where('name', 'Johannesburg,City of Johannesburg Metropolitan Municipality,Gauteng,South Africa')->value('code') ?? 1028665;
        $sandton = '-26.1076,28.0567,'.(int) config('indlela.rank_tracking.coordinate_radius');

        return [
            ['location_type' => 'code', 'location_code' => 2710, 'location_coordinate' => null, 'location_name' => 'South Africa', 'location_key' => 'code:2710'],
            ['location_type' => 'code', 'location_code' => $joburg, 'location_coordinate' => null, 'location_name' => 'Johannesburg, Gauteng, South Africa', 'location_key' => "code:{$joburg}"],
            ['location_type' => 'coordinate', 'location_code' => null, 'location_coordinate' => $sandton, 'location_name' => 'Sandton, Gauteng, South Africa', 'location_key' => "coord:{$sandton}"],
        ];
    }

    /** @return list<string> */
    private function tags(string $phrase): array
    {
        return array_values(array_filter([
            str_contains($phrase, 'seo') ? 'seo' : null,
            preg_match('/johannesburg|sandton|gauteng|near me|local/', $phrase) ? 'local' : null,
            str_contains($phrase, 'agency') || str_contains($phrase, 'company') ? 'brand' : null,
        ]));
    }

    /**
     * 90 days of results: a line from the start to the end position with
     * noise, local results and mobile a little different, this week's last
     * days pushed for a few phrases (gains and drops).
     *
     * @param  array{0: ?int, 1: ?int, 2: string, 3: ?string}  $shape
     */
    private function checks(RankKeyword $keyword, array $shape, int $location, string $device, bool $weekly): int
    {
        [$start, $end, $page, $rival] = $shape;
        $offset = $location * 2 + ($device === RankKeyword::MOBILE ? 1 : 0);
        $rows = [];

        for ($daysAgo = self::DAYS - 1; $daysAgo >= 0; $daysAgo--) {
            $day = today()->subDays($daysAgo);
            if ($weekly && $day->dayOfWeek !== 1) {
                continue;
            }

            $t = 1 - $daysAgo / (self::DAYS - 1);
            $from = $start ?? 130;
            $to = $end ?? 130;
            $position = $from + ($to - $from) * $t + mt_rand(-20, 20) / 10 + $offset;
            // This week's movers.
            if ($daysAgo < 4 && $keyword->keyword === 'web design sandton') {
                $position -= 7;
            }
            if ($daysAgo < 3 && $keyword->keyword === 'local seo') {
                $position += 9;
            }
            $position = (int) round($position);
            $ranks = $position >= 1 && $position <= 100;
            $position = max(1, $position);

            // Competing pages take turns (the second URL ranks too on most days).
            $urls = [];
            if ($ranks) {
                $main = self::BASE.$page;
                if ($rival && $daysAgo % 5 === 0) {
                    [$main, $other] = [self::BASE.$rival, self::BASE.$page];
                } else {
                    $other = $rival ? self::BASE.$rival : null;
                }
                $urls[] = ['url' => $main, 'position' => $position];
                if ($other && $daysAgo % 3 !== 1) {
                    $urls[] = ['url' => $other, 'position' => min(100, $position + 6 + mt_rand(0, 15))];
                }
            }

            $rows[] = [
                'workspace_id' => $keyword->workspace_id, 'site_id' => $keyword->site_id, 'keyword_id' => $keyword->id,
                'checked_on' => $day->toDateString(), 'mode' => RankCheck::STANDARD, 'status' => RankCheck::DONE,
                'task_id' => sprintf('demo-%d-%s', $keyword->id, $day->format('Ymd')),
                'position' => $ranks ? $position : null, 'url' => $ranks ? $urls[0]['url'] : null,
                'matching_urls' => json_encode($urls), 'serp_features' => json_encode($this->features($keyword->keyword, $ranks ? $position : null)),
                'result_count' => 1_000_000 + crc32($keyword->keyword) % 9_000_000, 'cost' => (float) config('indlela.rank_tracking.price_standard'),
                'posted_at' => $day->copy()->setTime(1, 0), 'completed_at' => $day->copy()->setTime(1, 40),
                'created_at' => $day->copy()->setTime(1, 0), 'updated_at' => $day->copy()->setTime(1, 40),
            ];
        }

        DB::table('rank_checks')->insert($rows);

        return count($rows);
    }

    /** @return array<string, array<string, bool>> */
    private function features(string $phrase, ?int $position): array
    {
        $local = (bool) preg_match('/johannesburg|sandton|gauteng|near me|local/', $phrase);
        $question = str_contains($phrase, 'best') || str_contains($phrase, 'audit') || str_contains($phrase, 'seo services');

        return [
            'featured_snippet' => ['present' => $question, 'ours' => $question && $position !== null && $position <= 3],
            'local_pack' => ['present' => $local, 'ours' => $local && $position !== null && $position <= 5],
            'ai_overview' => ['present' => $question || str_contains($phrase, 'ecommerce'), 'ours' => $question && $position !== null && $position <= 10],
            'people_also_ask' => ['present' => true],
            'video' => ['present' => str_contains($phrase, 'design')],
            'images' => ['present' => str_contains($phrase, 'design')],
            'top_stories' => ['present' => false],
        ];
    }
}
