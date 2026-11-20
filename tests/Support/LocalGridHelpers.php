<?php

namespace Tests\Support;

use App\Models\MapGrid;
use App\Models\MapGridPoint;
use App\Models\MapGridScan;
use App\Models\Site;
use Illuminate\Http\Client\Request;

/**
 * Local grid tests on top of RankTrackingHelpers' fake DataForSEO: Maps
 * SERP answers, grids and finished scans made directly.
 */
trait LocalGridHelpers
{
    use RankTrackingHelpers;

    protected const MAPS_LIVE = '/v3/serp/google/maps/live/advanced';

    /** A maps_search item. */
    protected function listing(int $rank, string $title, ?string $placeId = null, ?string $cid = null, ?string $domain = null): array
    {
        return ['type' => 'maps_search', 'rank_group' => $rank, 'rank_absolute' => $rank, 'title' => $title, 'domain' => $domain,
            'url' => $domain ? "https://{$domain}/" : null, 'address' => "{$rank} Main Road, Sandton", 'phone' => null,
            'rating' => ['rating_type' => 'Max5', 'value' => 4.5, 'votes_count' => 10 * $rank, 'rating_max' => null],
            'cid' => $cid, 'place_id' => $placeId, 'latitude' => -26.1, 'longitude' => 28.05, 'category' => 'Marketing agency'];
    }

    /**
     * Answers every live Maps call: $rankAt(lat, lng, keyword) = the
     * business's place there (null = not listed), rivals around it.
     *
     * @param  callable(float, float, string): ?int  $rankAt
     */
    protected function answerMaps(callable $rankAt, float $cost = 0.002): void
    {
        $this->dfsRoutes[self::MAPS_LIVE] = function (Request $r) use ($rankAt, $cost) {
            $task = $r->data()[0];
            [$lat, $lng] = array_map('floatval', explode(',', (string) ($task['location_coordinate'] ?? '0,0')));
            $rank = $rankAt($lat, $lng, (string) $task['keyword']);
            $items = [];
            foreach (range(1, 5) as $i) {
                $items[] = $i === $rank
                    ? $this->listing($i, 'Karoo Coffee', 'place-karoo', '111', 'karoo.co.za')
                    : $this->listing($i, "Rival {$i}", "place-rival-{$i}", (string) (900 + $i), "rival{$i}.co.za");
            }

            return $this->envelope([$this->serpTask($items, 'maps-'.($task['tag'] ?? 'search'), cost: $cost)]);
        };
    }

    /** The body a new grid is made with (business Karoo Coffee, 3 x 3 by default). */
    protected function gridBody(array $state = []): array
    {
        return [
            'keyword' => 'coffee shop sandton',
            'business' => ['title' => 'Karoo Coffee', 'place_id' => 'place-karoo', 'cid' => '111', 'address' => '1 Main Road', 'lat' => -26.1076, 'lng' => 28.0567],
            'center_lat' => -26.1076, 'center_lng' => 28.0567, 'size' => 3, 'spacing_km' => 1, 'device' => 'mobile', 'schedule' => 'none',
            ...$state,
        ];
    }

    protected function makeGrid(Site $site, array $state = []): MapGrid
    {
        return MapGrid::query()->withoutGlobalScopes()->create([
            'site_id' => $site->id, 'keyword' => 'coffee shop sandton', 'business_title' => 'Karoo Coffee',
            'business_place_id' => 'place-karoo', 'business_cid' => '111', 'business_lat' => -26.1076, 'business_lng' => 28.0567,
            'center_lat' => -26.1076, 'center_lng' => 28.0567, 'size' => 3, 'spacing_km' => 1, 'device' => 'mobile', 'schedule' => 'none',
            ...$state,
        ]);
    }

    /**
     * A scan of $grid with points of these ranks (row by row; null = not found).
     *
     * @param  list<?int>  $ranks
     */
    protected function makeScan(MapGrid $grid, array $ranks, array $state = []): MapGridScan
    {
        $scan = MapGridScan::query()->withoutGlobalScopes()->create([
            'workspace_id' => $grid->workspace_id, 'site_id' => $grid->site_id, 'grid_id' => $grid->id, 'status' => MapGridScan::DONE,
            'trigger' => MapGridScan::MANUAL, 'scanned_on' => today()->toDateString(), 'keyword' => $grid->keyword,
            'center_lat' => $grid->center_lat, 'center_lng' => $grid->center_lng, 'size' => $grid->size, 'spacing_km' => $grid->spacing_km,
            'device' => $grid->device, 'business_title' => $grid->business_title, 'business_place_id' => $grid->business_place_id,
            'business_cid' => $grid->business_cid, 'points_total' => count($ranks), 'points_done' => count($ranks),
            'started_at' => now(), 'finished_at' => now(),
            ...$state,
        ]);
        foreach (array_values($ranks) as $i => $rank) {
            MapGridPoint::query()->create([
                'scan_id' => $scan->id, 'row' => intdiv($i, $grid->size), 'col' => $i % $grid->size, 'lat' => -26.1, 'lng' => 28.05,
                'status' => MapGridPoint::DONE, 'rank' => $rank, 'cost' => 0.002, 'top' => [], 'results' => [],
            ]);
        }

        return $scan;
    }
}
