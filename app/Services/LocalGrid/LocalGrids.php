<?php

namespace App\Services\LocalGrid;

use App\Models\MapGrid;
use App\Models\MapGridPoint;
use App\Models\MapGridScan;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Grids: adding and changing them (the workspace's grid limit, no two grids
 * of a site for the same keyword, business and centre), and the queries
 * behind the Local grid pages.
 */
class LocalGrids
{
    public function __construct(private readonly GridLimits $limits) {}

    /** @param  array<string, mixed>  $data  StoreMapGridRequest's validated data */
    public function create(Site $site, array $data, User $user): MapGrid
    {
        $business = $data['business'];
        $attributes = [
            'site_id' => $site->id,
            'keyword' => trim($data['keyword']),
            'business_title' => trim($business['title']),
            'business_place_id' => $business['place_id'] ?? null,
            'business_cid' => isset($business['cid']) ? (string) $business['cid'] : null,
            'business_address' => $business['address'] ?? null,
            'business_lat' => round((float) $business['lat'], 7),
            'business_lng' => round((float) $business['lng'], 7),
            'center_lat' => round((float) $data['center_lat'], 7),
            'center_lng' => round((float) $data['center_lng'], 7),
            'size' => (int) $data['size'],
            'spacing_km' => (float) $data['spacing_km'],
            'device' => $data['device'] ?? MapGrid::MOBILE,
            'schedule' => $data['schedule'] ?? MapGrid::NONE,
            'is_active' => true,
            'created_by' => $user->id,
        ];

        return $this->limits->gridsSerialised($site->workspace_id, function () use ($site, $attributes) {
            $this->limits->ensureRoomForGrid($site->workspace_id, 'keyword');
            $grid = new MapGrid($attributes);
            $this->ensureUnique($grid);

            return $this->save($grid);
        });
    }

    /** @param  array<string, mixed>  $data  UpdateMapGridRequest's validated data (any of its fields) */
    public function update(MapGrid $grid, array $data): MapGrid
    {
        foreach (['center_lat', 'center_lng'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = round((float) $data[$field], 7);
            }
        }
        if (array_key_exists('keyword', $data)) {
            $data['keyword'] = trim($data['keyword']);
        }

        return $this->limits->gridsSerialised($grid->workspace_id, function () use ($grid, $data) {
            $grid->refresh();
            if (($data['is_active'] ?? false) && ! $grid->is_active) {
                $this->limits->ensureRoomForGrid($grid->workspace_id, 'is_active');
            }
            $grid->fill($data);
            if ($grid->isDirty(['keyword', 'center_lat', 'center_lng'])) {
                $this->ensureUnique($grid);
            }

            return $this->save($grid);
        });
    }

    /** 422 `keyword` when the site already has a grid for this keyword, business and centre. */
    private function ensureUnique(MapGrid $grid): void
    {
        $exists = MapGrid::query()
            ->where('site_id', $grid->site_id)
            ->whereRaw('LOWER(keyword) = ?', [mb_strtolower($grid->keyword)])
            ->where('business_key', MapGrid::businessKey($grid->business_place_id, $grid->business_cid, (string) $grid->business_title))
            ->where('center_lat', $grid->center_lat)
            ->where('center_lng', $grid->center_lng)
            ->when($grid->exists, fn ($q) => $q->whereKeyNot($grid->id))
            ->exists();
        if ($exists) {
            throw self::duplicate();
        }
    }

    private function save(MapGrid $grid): MapGrid
    {
        try {
            $grid->save();
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw self::duplicate();
            }
            throw $e;
        }

        return $grid;
    }

    private static function duplicate(): ValidationException
    {
        return ValidationException::withMessages(['keyword' => 'This site already has a grid for this keyword, business and centre.']);
    }

    /** @return EloquentCollection<int, MapGrid> the site's grids, each with its latest scan (and its ranks) */
    public function forSite(Site $site): EloquentCollection
    {
        $grids = MapGrid::query()->where('site_id', $site->id)
            ->with(['latestScan', 'latestDoneScan'])
            ->orderByRaw('LOWER(keyword)')->orderBy('id')
            ->get();
        $this->withRanks($grids->pluck('latestScan')->filter());

        return $grids;
    }

    /** One grid with its latest scan (and its ranks). */
    public function load(MapGrid $grid): MapGrid
    {
        $grid->load(['latestScan', 'latestDoneScan']);
        $this->withRanks(collect([$grid->latestScan])->filter());

        return $grid;
    }

    /** @return EloquentCollection<int, MapGridScan> the grid's latest scans, newest first */
    public function scans(MapGrid $grid): EloquentCollection
    {
        return $grid->scans()->orderByDesc('id')->limit((int) config('indlela.local_grid.scans_listed'))->get();
    }

    /**
     * Each scan's ranks as a size x size matrix (row 0 = north; null = not
     * found or not checked), for the small heatmaps: one query for them all.
     *
     * @param  Collection<int, MapGridScan>  $scans
     */
    public function withRanks(Collection $scans): void
    {
        if ($scans->isEmpty()) {
            return;
        }
        $points = MapGridPoint::query()->whereIn('scan_id', $scans->pluck('id'))->get(['scan_id', 'row', 'col', 'rank'])->groupBy('scan_id');
        foreach ($scans as $scan) {
            $matrix = array_fill(0, $scan->size, array_fill(0, $scan->size, null));
            foreach ($points->get($scan->id, []) as $point) {
                $matrix[$point->row][$point->col] = $point->rank;
            }
            $scan->setAttribute('ranks', $matrix);
        }
    }

    /**
     * Every site the user can access, by client then name, with its grid
     * count and latest finished scan (of a grid not deleted).
     *
     * @return EloquentCollection<int, Site>
     */
    public function sites(User $user): EloquentCollection
    {
        $sites = Site::query()->accessibleBy($user)
            ->with('client')
            ->join('clients', 'clients.id', '=', 'sites.client_id')
            ->orderBy('clients.name')->orderBy('sites.name')
            ->select('sites.*')
            ->selectSub(MapGrid::query()->whereColumn('map_grids.site_id', 'sites.id')->selectRaw('COUNT(*)'), 'grids_count')
            ->get();

        $latest = MapGridScan::query()
            ->whereIn('site_id', $sites->modelKeys())
            ->where('status', MapGridScan::DONE)
            ->whereHas('grid', fn ($q) => $q->withoutTrashed())
            ->selectRaw('DISTINCT ON (site_id) map_grid_scans.*')
            ->orderBy('site_id')->orderByDesc('id')
            ->get()->keyBy('site_id');
        $this->withRanks($latest->values());
        foreach ($sites as $site) {
            $site->setRelation('latestGridScan', $latest->get($site->id));
        }

        return $sites;
    }

    /**
     * Who holds the map pack across the scan's points (done ones), the
     * tracked business left out: the top 10 by points in the top 3, then
     * points found, then average rank.
     *
     * @return list<array{title: string, cid: ?string, place_id: ?string, rating: ?float, votes: ?int, top3_points: int, found_points: int, avg_rank: float}>
     */
    public function competitors(MapGridScan $scan, iterable $points): array
    {
        $target = ['place_id' => $scan->business_place_id, 'cid' => $scan->business_cid, 'domain' => $scan->business_domain];
        $by = [];
        foreach ($points as $point) {
            if ($point->status !== MapGridPoint::DONE) {
                continue;
            }
            $seen = [];
            foreach ($point->results ?? [] as $listing) {
                if (MapsParser::matches($listing, $target)) {
                    continue;
                }
                $key = $listing['place_id'] ?? $listing['cid'] ?? 'title:'.mb_strtolower((string) $listing['title']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $by[$key] ??= ['title' => $listing['title'], 'cid' => $listing['cid'] ?? null, 'place_id' => $listing['place_id'] ?? null,
                    'rating' => $listing['rating'] ?? null, 'votes' => $listing['votes'] ?? null, 'top3_points' => 0, 'found_points' => 0, 'rank_sum' => 0];
                $by[$key]['found_points']++;
                $by[$key]['rank_sum'] += (int) $listing['rank'];
                if ((int) $listing['rank'] <= 3) {
                    $by[$key]['top3_points']++;
                }
            }
        }

        $rows = array_map(function (array $row) {
            $row['avg_rank'] = round($row['rank_sum'] / $row['found_points'], 1);
            unset($row['rank_sum']);

            return $row;
        }, array_values($by));
        usort($rows, fn (array $a, array $b) => [$b['top3_points'], $b['found_points'], $a['avg_rank'], $a['title']]
            <=> [$a['top3_points'], $a['found_points'], $b['avg_rank'], $b['title']]);

        return array_slice($rows, 0, 10);
    }
}
