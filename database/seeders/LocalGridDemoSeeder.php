<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\MapGrid;
use App\Models\MapGridPoint;
use App\Models\MapGridScan;
use App\Models\Site;
use App\Models\Workspace;
use App\Services\LocalGrid\GridGeometry;
use App\Services\LocalGrid\GridScanner;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Seeder;

/**
 * Demo local grid, so the Local grid pages have data without DataForSEO.
 * Not part of DatabaseSeeder: run it on purpose,
 *
 *     php artisan db:seed --class=LocalGridDemoSeeder
 *
 * In the first workspace it uses (or makes) the client "Demo Client" and the
 * site demo.indlela.local (as the other demo seeders do) and adds one 5 x 5
 * grid ("seo agency sandton", 1 km apart around Sandton) with two finished
 * scans of made-up results (14 and 2 days ago, the later one better):
 * strong near the business, weaker or not found towards the edges, rival
 * agencies in the top 3. Deterministic. Idempotent and never deletes: an
 * existing demo grid that already has scans is left as it is. The grid is
 * not scheduled, so it never costs anything.
 */
class LocalGridDemoSeeder extends Seeder
{
    private const DOMAIN = 'demo.indlela.local';

    private const KEYWORD = 'seo agency sandton';

    private const CENTRE = [-26.1076, 28.0567];

    private const BUSINESS = ['title' => 'Indlela Demo Agency', 'place_id' => 'demo-place-indlela', 'cid' => '1000000000000000001',
        'address' => '5th Street, Sandton, 2196', 'lat' => -26.1052, 'lng' => 28.0560];

    /** Rivals: [title, place id, rating, votes, how strong (0–1)]. */
    private const RIVALS = [
        ['Sandton SEO Co', 'demo-place-rival-1', 4.7, 182, 0.9],
        ['Jozi Digital', 'demo-place-rival-2', 4.5, 96, 0.75],
        ['Rank Right Marketing', 'demo-place-rival-3', 4.8, 54, 0.6],
        ['Gauteng Web Studio', 'demo-place-rival-4', 4.2, 311, 0.5],
        ['Northside Media', 'demo-place-rival-5', 4.0, 40, 0.4],
        ['Clickwise Agency', 'demo-place-rival-6', 4.9, 23, 0.35],
        ['Rosebank Growth Lab', 'demo-place-rival-7', 4.4, 77, 0.3],
    ];

    public function run(CurrentWorkspace $current, GridScanner $scanner): void
    {
        $workspace = Workspace::query()->orderBy('id')->first();
        if (! $workspace) {
            $this->command?->warn('No workspace yet: run the default seeders first.');

            return;
        }

        mt_srand(2196);

        [$grid, $added] = $current->using($workspace, function () use ($scanner) {
            $client = Client::query()->firstOrCreate(['name' => 'Demo Client'], ['contact_name' => 'Demo Contact', 'is_active' => true, 'notes' => 'Made by a demo seeder.']);
            $site = Site::query()->firstOrCreate(['domain' => self::DOMAIN], ['client_id' => $client->id, 'name' => self::DOMAIN, 'url' => 'https://'.self::DOMAIN]);

            $grid = MapGrid::query()->firstOrCreate(
                ['site_id' => $site->id, 'keyword' => self::KEYWORD, 'business_key' => MapGrid::businessKey(self::BUSINESS['place_id'], null, '')],
                [
                    'business_title' => self::BUSINESS['title'], 'business_place_id' => self::BUSINESS['place_id'], 'business_cid' => self::BUSINESS['cid'],
                    'business_address' => self::BUSINESS['address'], 'business_lat' => self::BUSINESS['lat'], 'business_lng' => self::BUSINESS['lng'],
                    'center_lat' => self::CENTRE[0], 'center_lng' => self::CENTRE[1], 'size' => 5, 'spacing_km' => 1,
                    'device' => MapGrid::MOBILE, 'schedule' => MapGrid::NONE, 'is_active' => true,
                ],
            );
            if ($grid->scans()->exists()) {
                return [$grid, 0];
            }

            foreach ([14 => 2.0, 2 => 0.0] as $daysAgo => $handicap) {
                $this->scan($grid, $site, $daysAgo, $handicap, $scanner);
            }

            return [$grid, 2];
        });

        $this->command?->info($added
            ? sprintf('Local grid demo: grid #%d ("%s") for %s in "%s" (#%d) with 2 scans.', $grid->id, self::KEYWORD, self::DOMAIN, $workspace->name, $workspace->id)
            : sprintf('Local grid demo: grid #%d already has scans; nothing added.', $grid->id));
    }

    /** A finished scan $daysAgo with made-up results ($handicap places worse everywhere). */
    private function scan(MapGrid $grid, Site $site, int $daysAgo, float $handicap, GridScanner $scanner): void
    {
        $at = now()->subDays($daysAgo)->setTime(9, 30);
        $points = GridGeometry::points($grid->center_lat, $grid->center_lng, $grid->size, $grid->spacing_km);
        $scan = MapGridScan::query()->create([
            'workspace_id' => $grid->workspace_id, 'site_id' => $site->id, 'grid_id' => $grid->id,
            'status' => MapGridScan::RUNNING, 'trigger' => MapGridScan::MANUAL, 'scanned_on' => $at->toDateString(),
            'keyword' => $grid->keyword, 'center_lat' => $grid->center_lat, 'center_lng' => $grid->center_lng,
            'size' => $grid->size, 'spacing_km' => $grid->spacing_km, 'device' => $grid->device,
            'business_title' => $grid->business_title, 'business_place_id' => $grid->business_place_id,
            'business_cid' => $grid->business_cid, 'business_domain' => $site->domain, 'points_total' => count($points),
        ]);

        $rows = [];
        foreach ($points as $point) {
            $results = $this->results($point, $handicap);
            $rank = collect($results)->firstWhere('place_id', self::BUSINESS['place_id'])['rank'] ?? null;
            $rows[] = [...$point, 'scan_id' => $scan->id, 'status' => MapGridPoint::DONE, 'rank' => $rank,
                'top' => json_encode(array_slice($results, 0, 3)), 'results' => json_encode($results), 'cost' => 0, 'checked_at' => $at];
        }
        MapGridPoint::query()->insert($rows);

        $scanner->finish($scan);
        $scan->forceFill(['started_at' => $at, 'finished_at' => $at->copy()->addMinutes(2), 'created_at' => $at, 'updated_at' => $at])->save();
    }

    /**
     * The map results at a point: the business's place grows worse with its
     * distance from it (not in the top 20 far away), rivals fill the rest.
     *
     * @param  array{lat: float, lng: float}  $point
     * @return list<array<string, mixed>>
     */
    private function results(array $point, float $handicap): array
    {
        $km = sqrt((($point['lat'] - self::BUSINESS['lat']) * GridGeometry::KM_PER_DEGREE_LAT) ** 2
            + (($point['lng'] - self::BUSINESS['lng']) * GridGeometry::KM_PER_DEGREE_LNG * cos(deg2rad(self::BUSINESS['lat']))) ** 2);
        $place = (int) round(1 + $km * 3.2 + $handicap + mt_rand(0, 15) / 10);

        $rivals = self::RIVALS;
        usort($rivals, fn (array $a, array $b) => ($b[4] + mt_rand(0, 30) / 100) <=> ($a[4] + mt_rand(0, 30) / 100));
        $listings = array_map(fn (array $r) => ['title' => $r[0], 'cid' => null, 'place_id' => $r[1], 'rating' => $r[2], 'votes' => $r[3], 'domain' => null], $rivals);
        if ($place <= count($listings) + 1) {
            array_splice($listings, $place - 1, 0, [['title' => self::BUSINESS['title'], 'cid' => self::BUSINESS['cid'], 'place_id' => self::BUSINESS['place_id'],
                'rating' => 4.6, 'votes' => 128, 'domain' => self::DOMAIN]]);
        }

        return array_values(array_map(fn (array $l, int $i) => ['rank' => $i + 1, ...$l], $listings, array_keys($listings)));
    }
}
