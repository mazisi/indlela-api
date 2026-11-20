<?php

namespace App\Services\LocalGrid;

use App\Exceptions\LocalGridException;
use App\Jobs\RunMapGridScan;
use App\Models\MapGrid;
use App\Models\MapGridPoint;
use App\Models\MapGridScan;
use App\Models\Workspace;
use App\Services\RankTracking\RankSpend;
use App\Services\RankTracking\SerpApi;
use App\Support\CurrentWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Starting scans (scan now and the schedule), running their points against
 * DataForSEO's live Maps SERP a round at a time, and finishing them with
 * their metrics.
 *
 * A scan starts only if all its points fit under the platform's monthly
 * spend cap (decided inside RankSpend::serialised, the points created
 * pending before letting go, so they count as pending spend at once).
 */
class GridScanner
{
    public function __construct(
        private readonly SerpApi $api,
        private readonly RankSpend $spend,
        private readonly GridLimits $limits,
    ) {}

    /** Scans are off: the platform's DataForSEO account isn't set up, or the monthly cap is reached (cached). */
    public function paused(): bool
    {
        return ! $this->api->configured() || $this->spend->reached();
    }

    /**
     * "Scan now". 409 grid_inactive (paused grid), local_grid_paused,
     * scan_running; 429 scan_limit past the workspace's daily limit (counted
     * when started: a failed scan counts).
     */
    public function scanNow(MapGrid $grid, ?int $userId): MapGridScan
    {
        if (! $grid->is_active) {
            throw LocalGridException::inactive();
        }

        return $this->start($grid, MapGridScan::MANUAL, $userId);
    }

    /** A queued scan of $grid with all its points pending, and its job dispatched. */
    public function start(MapGrid $grid, string $trigger, ?int $userId = null): MapGridScan
    {
        if (! $this->api->configured()) {
            throw LocalGridException::paused();
        }

        $scan = Cache::lock("local-grid-scan:{$grid->id}", 10)->block(5, function () use ($grid, $trigger, $userId) {
            return $this->spend->serialised(fn () => DB::transaction(function () use ($grid, $trigger, $userId) {
                if (MapGridScan::query()->inWorkspace($grid->workspace_id)->where('grid_id', $grid->id)->inProgress()->exists()) {
                    throw LocalGridException::scanRunning();
                }

                if ($trigger === MapGridScan::MANUAL) {
                    $this->limits->lockScanNow($grid->workspace_id);
                    $limit = $this->limits->scanNowLimit();
                    if ($this->limits->scansToday($grid->workspace_id) >= $limit) {
                        throw LocalGridException::scanLimit($limit);
                    }
                }

                $points = GridGeometry::points($grid->center_lat, $grid->center_lng, $grid->size, $grid->spacing_km);
                $fit = $this->spend->checksThatFit((float) config('indlela.local_grid.price_live'));
                if ($fit !== null && $fit < count($points)) {
                    throw LocalGridException::paused();
                }

                $site = $grid->site()->withoutGlobalScope('workspace')->first();
                $scan = MapGridScan::query()->create([
                    'workspace_id' => $grid->workspace_id, 'site_id' => $grid->site_id, 'grid_id' => $grid->id,
                    'status' => MapGridScan::QUEUED, 'trigger' => $trigger, 'scanned_on' => today()->toDateString(),
                    'keyword' => $grid->keyword, 'center_lat' => $grid->center_lat, 'center_lng' => $grid->center_lng,
                    'size' => $grid->size, 'spacing_km' => $grid->spacing_km, 'device' => $grid->device,
                    'business_title' => $grid->business_title, 'business_place_id' => $grid->business_place_id,
                    'business_cid' => $grid->business_cid, 'business_domain' => $site?->domain,
                    'points_total' => count($points), 'requested_by' => $userId,
                ]);
                MapGridPoint::query()->insert(array_map(fn (array $p) => [...$p, 'scan_id' => $scan->id, 'status' => MapGridPoint::PENDING, 'cost' => 0], $points));

                return $scan;
            }));
        });
        $this->spend->forget();

        RunMapGridScan::dispatch($scan->id, $scan->workspace_id);

        return $scan->refresh();
    }

    /**
     * One round: up to `concurrency` pending points checked at once. Returns
     * whether points are still pending (the job queues the next round);
     * otherwise the scan is finished.
     */
    public function runRound(MapGridScan $scan): bool
    {
        if (! $scan->inProgress()) {
            return false;
        }
        if ($scan->status === MapGridScan::QUEUED) {
            $scan->forceFill(['status' => MapGridScan::RUNNING, 'started_at' => now()])->save();
        }

        $points = $scan->points()->where('status', MapGridPoint::PENDING)->orderBy('id')
            ->limit(max(1, (int) config('indlela.local_grid.concurrency')))->get();
        if ($points->isNotEmpty()) {
            $this->check($scan, $points->all());
        }

        if ($scan->points()->where('status', MapGridPoint::PENDING)->exists()) {
            $scan->forceFill(['points_done' => $scan->points()->where('status', '!=', MapGridPoint::PENDING)->count()])->save();

            return true;
        }

        $this->finish($scan);

        return false;
    }

    /** @param  list<MapGridPoint>  $points */
    private function check(MapGridScan $scan, array $points): void
    {
        $tasks = [];
        foreach ($points as $point) {
            $tasks[$point->id] = $this->task($scan, $point);
        }
        $answers = $this->api->mapsLive($tasks);
        $target = ['place_id' => $scan->business_place_id, 'cid' => $scan->business_cid, 'domain' => $scan->business_domain];
        $depth = (int) config('indlela.local_grid.depth');

        foreach ($points as $point) {
            $answer = $answers[$point->id] ?? ['status_code' => 0, 'status_message' => 'No answer.', 'cost' => 0.0, 'result' => null];
            // 40102: no results there, so not found.
            if (in_array($answer['status_code'], [20000, 40102], true)) {
                $point->forceFill([...MapsParser::parse($answer['result'], $target, $depth), 'status' => MapGridPoint::DONE, 'error' => null]);
            } else {
                $point->forceFill(['status' => MapGridPoint::FAILED, 'error' => mb_substr("{$answer['status_message']} ({$answer['status_code']})", 0, 500)]);
                Log::warning('Local grid: a point could not be checked.', ['scan_id' => $scan->id, 'point_id' => $point->id, 'error' => $answer['status_message']]);
            }
            $point->forceFill(['cost' => round((float) $answer['cost'], 6), 'checked_at' => now()])->save();
        }
    }

    /** The live/advanced body of a point. */
    public function task(MapGridScan $scan, MapGridPoint $point): array
    {
        return [
            'keyword' => $scan->keyword,
            'location_coordinate' => GridGeometry::coordinate($point->lat, $point->lng, (int) config('indlela.local_grid.zoom')),
            'language_code' => (string) config('indlela.local_grid.language_code'),
            'device' => $scan->device,
            'os' => $scan->device === MapGrid::MOBILE ? 'android' : 'windows',
            'depth' => (int) config('indlela.local_grid.depth'),
            'tag' => "grid-{$scan->id}-{$point->row}-{$point->col}",
        ];
    }

    /**
     * Counts and metrics from the points (done ones; failed points are left
     * out). No point checked at all = failed. Only a scan still in progress
     * is finished (a second call changes nothing).
     */
    public function finish(MapGridScan $scan): void
    {
        $row = $scan->points()->toBase()->selectRaw(
            "COUNT(*) FILTER (WHERE status <> 'pending') AS finished,"
            ." COUNT(*) FILTER (WHERE status = 'done') AS ok,"
            ." COUNT(*) FILTER (WHERE status = 'done' AND rank IS NOT NULL) AS found,"
            ." COUNT(*) FILTER (WHERE status = 'done' AND rank <= 3) AS top3,"
            ." AVG(rank) FILTER (WHERE status = 'done' AND rank IS NOT NULL) AS arp,"
            ." AVG(COALESCE(rank, 21)) FILTER (WHERE status = 'done') AS atrp,"
            .' COALESCE(SUM(cost), 0) AS cost'
        )->first();
        $ok = (int) $row->ok;

        MapGridScan::query()->withoutGlobalScope('workspace')->whereKey($scan->id)->inProgress()->update([
            'status' => $ok > 0 ? MapGridScan::DONE : MapGridScan::FAILED,
            'points_done' => (int) $row->finished,
            'points_found' => (int) $row->found,
            'arp' => $row->arp === null ? null : round((float) $row->arp, 2),
            'atrp' => $ok > 0 ? round((float) $row->atrp, 2) : null,
            'solv' => $ok > 0 ? round((int) $row->top3 / $ok * 100, 1) : null,
            'found_pct' => $ok > 0 ? round((int) $row->found / $ok * 100, 1) : null,
            'cost' => round((float) $row->cost, 6),
            'error' => $ok > 0 ? null : 'None of the grid\'s points could be checked. Try again later.',
            'started_at' => $scan->started_at ?? now(),
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
        $scan->refresh();
        $this->spend->forget();
    }

    /** A scan that stopped: its pending points failed, then finished with what it has. */
    public function abandon(MapGridScan $scan, string $reason): void
    {
        $scan->points()->where('status', MapGridPoint::PENDING)
            ->update(['status' => MapGridPoint::FAILED, 'error' => mb_substr($reason, 0, 500), 'checked_at' => now()]);
        $this->finish($scan);
    }

    /**
     * Scans still in progress after `stale_hours` (a lost job): abandoned.
     *
     * @return int scans abandoned
     */
    public function failStale(): int
    {
        $scans = MapGridScan::query()->withoutGlobalScope('workspace')->inProgress()
            ->where('created_at', '<', now()->subHours((int) config('indlela.local_grid.stale_hours')))->get();
        foreach ($scans as $scan) {
            $this->abandon($scan, 'The scan did not finish in time.');
        }

        return $scans->count();
    }

    /**
     * Queues a scheduled scan of every active, scheduled grid of an active
     * site that is due on $day (next_scan_on ≤ $day) and has no scan in
     * progress, each in its own workspace. A scan that doesn't fit under the
     * spend cap (or with no credentials) is skipped: the next run tries again.
     *
     * @return array{queued: int, skipped: int}
     */
    public function scheduleDue(CarbonInterface $day, CurrentWorkspace $current): array
    {
        $queued = 0;
        $skipped = 0;
        $grids = MapGrid::query()->withoutGlobalScope('workspace')->active()
            ->where('schedule', '!=', MapGrid::NONE)
            ->whereHas('site', fn ($q) => $q->withoutGlobalScope('workspace')->whereNull('sites.deleted_at')->where('sites.is_active', true))
            ->whereNotExists(fn ($q) => $q->from('map_grid_scans')->whereColumn('map_grid_scans.grid_id', 'map_grids.id')->whereIn('map_grid_scans.status', MapGridScan::IN_PROGRESS))
            ->with(['latestDoneScan' => fn ($q) => $q->withoutGlobalScope('workspace')])
            ->orderBy('workspace_id')->orderBy('id')
            ->get();

        $workspaces = Workspace::query()->whereKey($grids->pluck('workspace_id')->unique())->get()->keyBy('id');
        foreach ($grids as $grid) {
            $workspace = $workspaces->get($grid->workspace_id);
            $next = $grid->nextScanOn();
            if (! $workspace || $next === null || $next->toDateString() > $day->toDateString()) {
                continue;
            }
            try {
                $current->using($workspace, fn () => $this->start($grid, MapGridScan::SCHEDULED));
                $queued++;
            } catch (LocalGridException) {
                $skipped++;
            }
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }
}
