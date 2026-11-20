<?php

namespace App\Services\RankTracking;

use App\Models\MapGridPoint;
use App\Models\MapGridScan;
use App\Models\MapGridSearch;
use App\Models\RankCheck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The platform's DataForSEO spend this calendar month, across every
 * workspace and both products (rank tracking, local grid), against
 * rank_tracking.monthly_spend_cap_usd. Platform-only:
 * never shown to a workspace (they only see `paused`).
 *
 * Spend = what DataForSEO charged (rank_checks.cost) plus the expected price
 * of checks posted and not answered yet (a live check, or a standard one
 * claimed but not yet accepted). Whatever posts or starts a check decides
 * inside serialised(), reading the spend fresh, and marks its checks posted
 * before letting go, so two batches can't both spend the last dollar.
 */
class RankSpend
{
    /** pg_advisory_xact_lock key for the platform budget. */
    private const BUDGET_LOCK = 7_340_101;

    /** The cap in USD; null = no cap (0 or empty). */
    public function cap(): ?float
    {
        $cap = (float) config('indlela.rank_tracking.monthly_spend_cap_usd');

        return $cap > 0 ? $cap : null;
    }

    /** This month's spend, cached briefly (the usage endpoint reads it on every call). */
    public function thisMonth(): float
    {
        return (float) Cache::remember($this->cacheKey(), (int) config('indlela.rank_tracking.spend_cache_seconds'), fn () => $this->fresh());
    }

    /** This month's spend from the database. */
    public function fresh(): float
    {
        ['charged' => $charged, 'pending' => $pending] = $this->breakdown();

        return round($charged + $pending, 6);
    }

    /**
     * This month's spend in its two parts: what DataForSEO charged and the
     * expected price of calls made and not answered yet. Rank checks
     * (Σ rank_checks.cost; live checks pending, standard ones claimed and not
     * accepted) and the local grid (Σ map_grid_points.cost of scans started
     * this month and Σ map_grid_searches.cost; points of scans in progress
     * not done yet, searches still running). Platform › Usage shows both.
     *
     * @return array{charged: float, pending: float}
     */
    public function breakdown(): array
    {
        $prices = config('indlela.rank_tracking');
        $gridPrice = (float) config('indlela.local_grid.price_live');
        $month = today()->startOfMonth()->toDateString();

        $row = RankCheck::query()->withoutGlobalScope('workspace')
            ->where('checked_on', '>=', $month)
            ->selectRaw(
                'COALESCE(SUM(cost), 0) AS charged,'
                ." CAST(? AS numeric) * COUNT(*) FILTER (WHERE status = 'pending' AND posted_at IS NOT NULL AND mode = 'live')"
                ." + CAST(? AS numeric) * COUNT(*) FILTER (WHERE status = 'pending' AND posted_at IS NOT NULL AND mode = 'standard' AND task_id IS NULL) AS pending",
                [(float) $prices['price_live'], (float) $prices['price_standard']],
            )
            ->toBase()->first();

        $points = MapGridPoint::query()
            ->join('map_grid_scans', 'map_grid_scans.id', '=', 'map_grid_points.scan_id')
            ->where(fn ($q) => $q->where('map_grid_scans.scanned_on', '>=', $month)->orWhereIn('map_grid_scans.status', MapGridScan::IN_PROGRESS))
            ->selectRaw(
                'COALESCE(SUM(map_grid_points.cost) FILTER (WHERE map_grid_scans.scanned_on >= ?), 0) AS charged,'
                ." CAST(? AS numeric) * COUNT(*) FILTER (WHERE map_grid_points.status = 'pending' AND map_grid_scans.status IN ('queued', 'running')) AS pending",
                [$month, $gridPrice],
            )
            ->toBase()->first();

        $searches = MapGridSearch::query()->withoutGlobalScope('workspace')
            ->where('searched_on', '>=', $month)
            ->selectRaw(
                'COALESCE(SUM(cost), 0) AS charged,'
                ." CAST(? AS numeric) * COUNT(*) FILTER (WHERE status = 'pending' AND created_at > ?) AS pending",
                [$gridPrice, now()->subMinutes(10)],
            )
            ->toBase()->first();

        return [
            'charged' => round((float) $row->charged + (float) $points->charged + (float) $searches->charged, 6),
            'pending' => round((float) $row->pending + (float) $points->pending + (float) $searches->pending, 6),
        ];
    }

    /** Whether the cap is reached (cached spend): workspaces see rank checks as paused. */
    public function reached(): bool
    {
        $cap = $this->cap();

        return $cap !== null && $this->thisMonth() >= $cap;
    }

    /** How many more checks at $price fit under the cap (fresh spend); null = no cap. */
    public function checksThatFit(float $price): ?int
    {
        $cap = $this->cap();
        if ($cap === null || $price <= 0) {
            return null;
        }
        $left = $cap - $this->fresh();

        // Rounded first: 0.0018 / 0.0006 must be 3, not 2.9999….
        return $left <= 0 ? 0 : (int) floor(round($left / $price, 6));
    }

    /**
     * Runs $work holding the platform budget lock (in a transaction; the
     * lock is released when it ends). Without a cap nothing is locked.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function serialised(callable $work): mixed
    {
        if ($this->cap() === null) {
            return $work();
        }

        return DB::transaction(function () use ($work) {
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::BUDGET_LOCK]);

            return $work();
        });
    }

    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return 'rank-spend:'.today()->format('Y-m');
    }
}
