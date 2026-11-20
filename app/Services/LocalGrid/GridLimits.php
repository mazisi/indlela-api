<?php

namespace App\Services\LocalGrid;

use App\Models\MapGrid;
use App\Models\MapGridScan;
use App\Models\MapGridSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A workspace's local grid beta limits: active grids, manual scans and
 * business searches a calendar day (SAST). Counted without the workspace
 * scope, so they hold in queued work too; each has an advisory lock so two
 * requests can't both take the last place.
 */
class GridLimits
{
    /** pg_advisory_xact_lock keys (with the workspace id). */
    private const GRIDS_LOCK = 7_340_201;

    private const SCAN_NOW_LOCK = 7_340_202;

    private const SEARCH_LOCK = 7_340_203;

    public function gridLimit(): int
    {
        return (int) config('indlela.local_grid.max_grids_per_workspace');
    }

    /** Active, non-deleted grids of the workspace. */
    public function gridsUsed(int $workspaceId): int
    {
        return MapGrid::query()->inWorkspace($workspaceId)->where('is_active', true)->count();
    }

    /** 422 on $field when one more active grid would take the workspace over its limit. */
    public function ensureRoomForGrid(int $workspaceId, string $field): void
    {
        $limit = $this->gridLimit();
        if ($this->gridsUsed($workspaceId) >= $limit) {
            throw ValidationException::withMessages([$field => "This workspace can have up to {$limit} active local grids. Pause or delete one to add another."]);
        }
    }

    /**
     * Runs $work in a transaction holding the workspace's grid lock.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function gridsSerialised(int $workspaceId, callable $work): mixed
    {
        return DB::transaction(function () use ($workspaceId, $work) {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::GRIDS_LOCK, $workspaceId]);

            return $work();
        });
    }

    public function scanNowLimit(): int
    {
        return (int) config('indlela.local_grid.scan_now_daily_limit');
    }

    /** Manual scans the workspace started today (SAST), whatever became of them. */
    public function scansToday(int $workspaceId): int
    {
        return MapGridScan::query()->inWorkspace($workspaceId)
            ->where('trigger', MapGridScan::MANUAL)->where('scanned_on', today()->toDateString())->count();
    }

    /** Takes the workspace's scan-now lock until the surrounding transaction ends. */
    public function lockScanNow(int $workspaceId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::SCAN_NOW_LOCK, $workspaceId]);
    }

    public function searchLimit(): int
    {
        return (int) config('indlela.local_grid.business_search_daily_limit');
    }

    /** Business searches the workspace made today (SAST), failed ones included. */
    public function searchesToday(int $workspaceId): int
    {
        return MapGridSearch::query()->inWorkspace($workspaceId)->where('searched_on', today()->toDateString())->count();
    }

    public function lockSearch(int $workspaceId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::SEARCH_LOCK, $workspaceId]);
    }
}
