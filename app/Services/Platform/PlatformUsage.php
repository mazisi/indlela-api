<?php

namespace App\Services\Platform;

use App\Models\MapGridPoint;
use App\Models\MapGridSearch;
use App\Models\RankCheck;
use App\Models\RankKeyword;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use App\Services\RankTracking\RankSpend;
use App\Services\RankTracking\SerpApi;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Platform › Usage (Indlela staff, platform workspace only): the company's
 * DataForSEO spend and every workspace's rank-tracking and local grid usage.
 *
 * The ONLY place that reads across workspaces on purpose: every tenant
 * query here drops the workspace scope explicitly
 * (withoutGlobalScope('workspace')) and aggregates in grouped SQL.
 *
 * Months are calendar months of `rank_checks.checked_on` (SAST), as in
 * RankSpend. Cost = Σ rank_checks.cost (what DataForSEO charged); a check
 * = a check posted to DataForSEO (`posted_at` set: skipped ones aren't);
 * live checks = Σ `live_requests` ("check now" requests, billed even when
 * they fail). Local grid spend (points by their scan's `scanned_on`,
 * business searches by `searched_on`) is added to every cost.
 */
class PlatformUsage
{
    /** Months in each series, the current one last. */
    public const SERIES_MONTHS = 6;

    /** sort key => SQL expression (the list's select aliases). */
    public const SORTS = [
        'name' => 'LOWER(workspaces.name)',
        'keywords_tracked' => 'keywords_tracked',
        'checks_this_month' => 'checks_this_month',
        'cost_this_month' => 'cost_this_month',
        'cost_last_month' => 'cost_last_month',
        'last_check_at' => 'last_check_at',
    ];

    public function __construct(private readonly RankSpend $spend, private readonly SerpApi $api) {}

    /** @return array<string, mixed> the page's header: spend, provider, limits, totals and the 6-month series */
    public function overview(): array
    {
        ['charged' => $charged, 'pending' => $pending] = $this->spend->breakdown();
        $thisMonth = $charged + $pending;
        $cap = $this->spend->cap();

        $monthly = $this->checks()
            ->where('checked_on', '>=', $this->month(-(self::SERIES_MONTHS - 1))->toDateString())
            ->groupByRaw("to_char(checked_on, 'YYYY-MM')")
            ->selectRaw("to_char(checked_on, 'YYYY-MM') AS month, COALESCE(SUM(cost), 0) AS cost,"
                .' COUNT(*) FILTER (WHERE posted_at IS NOT NULL) AS checks, COALESCE(SUM(live_requests), 0) AS live_checks')
            ->get()->keyBy('month');
        $current = $monthly->get($this->month(0)->format('Y-m'));
        $gridMonthly = $this->gridSpend()
            ->where('day', '>=', $this->month(-(self::SERIES_MONTHS - 1))->toDateString())
            ->groupByRaw("to_char(day, 'YYYY-MM')")
            ->selectRaw("to_char(day, 'YYYY-MM') AS month, COALESCE(SUM(cost), 0) AS cost, COALESCE(SUM(points), 0) AS points")
            ->get()->keyBy('month');

        $keywords = $this->activeKeywords()
            ->selectRaw('COUNT(*) AS keywords, COUNT(DISTINCT rank_keywords.workspace_id) AS workspaces')
            ->first();

        return [
            'month' => $this->month(0)->format('Y-m'),
            'spend' => [
                'this_month' => self::usd($thisMonth),
                'pending' => self::usd($pending),
                'cap' => $cap,
                'cap_used_pct' => $cap === null ? null : round($thisMonth / $cap * 100, 1),
            ],
            'provider' => [
                'configured' => $this->api->configured(),
                'sandbox' => (bool) config('services.dataforseo.sandbox'),
                'balance' => $this->balance(),
            ],
            'limits' => [
                'workspace_keyword_limit' => (int) config('indlela.rank_tracking.workspace_keyword_limit'),
                'check_now_daily_limit' => (int) config('indlela.rank_tracking.check_now_daily_limit'),
            ],
            'totals' => [
                'workspaces' => Workspace::query()->count(),
                'active_workspaces' => (int) $keywords->workspaces,
                'keywords_tracked' => (int) $keywords->keywords,
                'checks_this_month' => (int) ($current->checks ?? 0),
                'live_checks_this_month' => (int) ($current->live_checks ?? 0),
                'grid_points_this_month' => (int) ($gridMonthly->get($this->month(0)->format('Y-m'))->points ?? 0),
            ],
            'series' => $this->months()->map(fn (string $month) => [
                'month' => $month,
                'cost' => self::usd((float) ($monthly->get($month)->cost ?? 0) + (float) ($gridMonthly->get($month)->cost ?? 0)),
                'checks' => (int) ($monthly->get($month)->checks ?? 0),
            ])->all(),
        ];
    }

    /**
     * Every workspace with its usage, paginated.
     *
     * @param  array{search?: ?string, sort?: ?string, per_page?: ?int}  $params
     */
    public function workspaces(array $params): LengthAwarePaginator
    {
        $sort = $params['sort'] ?? '-cost_this_month';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = self::SORTS[ltrim($sort, '-')];

        $page = $this->listQuery()
            ->when($params['search'] ?? null, fn (Builder $q, string $search) => $this->search($q, $search))
            ->orderByRaw("{$column} {$direction} NULLS LAST")
            ->orderBy('workspaces.id')
            ->paginate($params['per_page'] ?? 15);

        $rows = $this->rows($page->getCollection());
        $page->setCollection($rows);

        return $page;
    }

    /** @return array<string, mixed> one workspace's row */
    public function row(int $workspaceId): array
    {
        return $this->rows($this->listQuery()->whereKey($workspaceId)->get())->firstOrFail();
    }

    /** Workspaces (not deleted) with their figures as select aliases. */
    private function listQuery(): Builder
    {
        $thisMonth = $this->month(0)->toDateString();
        $lastMonth = $this->month(-1)->toDateString();

        $checks = $this->checks()
            ->where('checked_on', '>=', $lastMonth)
            ->groupBy('workspace_id')
            ->selectRaw('workspace_id,'
                .' COUNT(*) FILTER (WHERE checked_on >= ? AND posted_at IS NOT NULL) AS checks_this_month,'
                .' COALESCE(SUM(live_requests) FILTER (WHERE checked_on >= ?), 0) AS live_checks_this_month,'
                .' COALESCE(SUM(cost) FILTER (WHERE checked_on >= ?), 0) AS cost_this_month,'
                .' COALESCE(SUM(cost) FILTER (WHERE checked_on < ?), 0) AS cost_last_month',
                [$thisMonth, $thisMonth, $thisMonth, $thisMonth]);

        $keywords = RankKeyword::query()->withoutGlobalScope('workspace')->toBase()
            ->where('is_active', true)->groupBy('workspace_id')
            ->selectRaw('workspace_id, COUNT(*) AS keywords_tracked');

        $sites = Site::query()->withoutGlobalScope('workspace')->toBase()
            ->groupBy('workspace_id')->selectRaw('workspace_id, COUNT(*) AS sites_count');

        $grid = $this->gridSpend()
            ->where('day', '>=', $lastMonth)
            ->groupBy('workspace_id')
            ->selectRaw('workspace_id,'
                .' COALESCE(SUM(cost) FILTER (WHERE day >= ?), 0) AS cost_this_month,'
                .' COALESCE(SUM(cost) FILTER (WHERE day < ?), 0) AS cost_last_month,'
                .' COALESCE(SUM(points) FILTER (WHERE day >= ?), 0) AS points_this_month',
                [$thisMonth, $thisMonth, $thisMonth]);

        return Workspace::query()
            ->leftJoinSub($checks, 'c', 'c.workspace_id', '=', 'workspaces.id')
            ->leftJoinSub($keywords, 'k', 'k.workspace_id', '=', 'workspaces.id')
            ->leftJoinSub($sites, 's', 's.workspace_id', '=', 'workspaces.id')
            ->leftJoinSub($grid, 'g', 'g.workspace_id', '=', 'workspaces.id')
            ->select('workspaces.*')
            ->selectRaw('COALESCE(k.keywords_tracked, 0) AS keywords_tracked, COALESCE(s.sites_count, 0) AS sites_count,'
                .' COALESCE(c.checks_this_month, 0) AS checks_this_month, COALESCE(c.live_checks_this_month, 0) AS live_checks_this_month,'
                .' COALESCE(c.cost_this_month, 0) + COALESCE(g.cost_this_month, 0) AS cost_this_month,'
                .' COALESCE(c.cost_last_month, 0) + COALESCE(g.cost_last_month, 0) AS cost_last_month,'
                .' COALESCE(g.points_this_month, 0) AS grid_points_this_month,'
                ." (SELECT MAX(rc.completed_at) FROM rank_checks rc WHERE rc.workspace_id = workspaces.id AND rc.status = 'done') AS last_check_at");
    }

    /** Name, slug, or the email of one of the workspace's Owners. */
    private function search(Builder $query, string $search): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($search)).'%';

        return $query->where(fn (Builder $q) => $q
            ->whereRaw('LOWER(workspaces.name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(workspaces.slug) LIKE ?', [$like])
            ->orWhereExists(fn (QueryBuilder $owners) => $this->owners($owners)
                ->selectRaw('1')
                ->whereColumn('roles.workspace_id', 'workspaces.id')
                ->whereRaw('LOWER(users.email) LIKE ?', [$like])));
    }

    /**
     * The page's workspaces as rows, with their Owner and series: two
     * grouped queries for the whole page.
     *
     * @param  Collection<int, Workspace>  $workspaces
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(Collection $workspaces): Collection
    {
        $ids = $workspaces->modelKeys();

        $owners = $this->owners(DB::table('model_has_roles'))
            ->whereIn('roles.workspace_id', $ids)
            ->orderBy('users.id')
            ->get(['roles.workspace_id', 'users.full_name', 'users.surname', 'users.email'])
            ->unique('workspace_id')->keyBy('workspace_id');

        $series = $this->checks()
            ->whereIn('workspace_id', $ids)
            ->where('checked_on', '>=', $this->month(-(self::SERIES_MONTHS - 1))->toDateString())
            ->groupBy('workspace_id')->groupByRaw("to_char(checked_on, 'YYYY-MM')")
            ->selectRaw("workspace_id, to_char(checked_on, 'YYYY-MM') AS month, COALESCE(SUM(cost), 0) AS cost")
            ->get()->groupBy('workspace_id')->map(fn (Collection $rows) => $rows->pluck('cost', 'month'));
        $gridSeries = $this->gridSpend()
            ->whereIn('workspace_id', $ids)
            ->where('day', '>=', $this->month(-(self::SERIES_MONTHS - 1))->toDateString())
            ->groupBy('workspace_id')->groupByRaw("to_char(day, 'YYYY-MM')")
            ->selectRaw("workspace_id, to_char(day, 'YYYY-MM') AS month, COALESCE(SUM(cost), 0) AS cost")
            ->get()->groupBy('workspace_id')->map(fn (Collection $rows) => $rows->pluck('cost', 'month'));
        $months = $this->months();
        $default = (int) config('indlela.rank_tracking.workspace_keyword_limit');

        return $workspaces->map(function (Workspace $w) use ($owners, $series, $gridSeries, $months, $default) {
            $owner = $owners->get($w->id);
            $costs = $series->get($w->id, collect());
            $gridCosts = $gridSeries->get($w->id, collect());

            return [
                'id' => $w->id,
                'name' => $w->name,
                'slug' => $w->slug,
                'owner' => $owner ? ['name' => trim($owner->full_name.' '.$owner->surname), 'email' => $owner->email] : null,
                'created_at' => $w->created_at?->toIso8601String(),
                'keywords_tracked' => (int) $w->keywords_tracked,
                'keyword_limit' => $w->rank_keyword_limit ?? $default,
                'keyword_limit_override' => $w->rank_keyword_limit,
                'sites_count' => (int) $w->sites_count,
                'checks_this_month' => (int) $w->checks_this_month,
                'live_checks_this_month' => (int) $w->live_checks_this_month,
                'grid_points_this_month' => (int) $w->grid_points_this_month,
                'cost_this_month' => self::usd((float) $w->cost_this_month),
                'cost_last_month' => self::usd((float) $w->cost_last_month),
                'last_check_at' => $w->last_check_at ? Carbon::parse($w->last_check_at)->toIso8601String() : null,
                'series' => $months->map(fn (string $month) => ['month' => $month, 'cost' => self::usd((float) ($costs->get($month) ?? 0) + (float) ($gridCosts->get($month) ?? 0))])->all(),
            ];
        })->values();
    }

    /** Active Owner memberships (role → user), on $query = model_has_roles. */
    private function owners(QueryBuilder $query): QueryBuilder
    {
        return $query->from('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('roles.name', Role::OWNER)
            ->whereNull('roles.deleted_at')
            ->whereNull('users.deleted_at');
    }

    /** rank_checks of every workspace (deleted workspaces included: their spend was real). */
    private function checks(): QueryBuilder
    {
        return RankCheck::query()->withoutGlobalScope('workspace')->toBase();
    }

    /**
     * Local grid spend of every workspace by day: points (by the day their
     * scan started; `points` = points checked) and business searches,
     * as rows {workspace_id, day, cost, points} to group further.
     */
    private function gridSpend(): QueryBuilder
    {
        $points = MapGridPoint::query()->toBase()
            ->join('map_grid_scans', 'map_grid_scans.id', '=', 'map_grid_points.scan_id')
            ->groupBy('map_grid_scans.workspace_id', 'map_grid_scans.scanned_on')
            ->selectRaw("map_grid_scans.workspace_id, map_grid_scans.scanned_on AS day, SUM(map_grid_points.cost) AS cost, COUNT(*) FILTER (WHERE map_grid_points.status <> 'pending') AS points");
        $searches = MapGridSearch::query()->withoutGlobalScope('workspace')->toBase()
            ->groupBy('workspace_id', 'searched_on')
            ->selectRaw('workspace_id, searched_on AS day, SUM(cost) AS cost, 0 AS points');

        return DB::query()->fromSub($points->unionAll($searches), 'grid_spend');
    }

    /** Active keywords of workspaces that aren't deleted, across the platform. */
    private function activeKeywords(): QueryBuilder
    {
        return RankKeyword::query()->withoutGlobalScope('workspace')->toBase()
            ->join('workspaces', 'workspaces.id', '=', 'rank_keywords.workspace_id')
            ->whereNull('workspaces.deleted_at')
            ->where('rank_keywords.is_active', true);
    }

    /** The provider's balance in USD, cached; null when not configured or unreachable. */
    private function balance(): ?float
    {
        if (! $this->api->configured()) {
            return null;
        }

        // Wrapped, so a failed lookup (null) is cached too instead of retried on every load.
        return Cache::remember('platform-usage:balance', (int) config('indlela.rank_tracking.balance_cache_seconds'), function () {
            try {
                return ['usd' => $this->api->balance()];
            } catch (Throwable $e) {
                report($e);

                return ['usd' => null];
            }
        })['usd'];
    }

    /** The first day of the month $offset months from this one. */
    private function month(int $offset): CarbonImmutable
    {
        return CarbonImmutable::parse(today())->startOfMonth()->addMonthsNoOverflow($offset);
    }

    /** @return Collection<int, string> the series' months ('YYYY-MM'), oldest first */
    private function months(): Collection
    {
        return collect(range(-(self::SERIES_MONTHS - 1), 0))->map(fn (int $offset) => $this->month($offset)->format('Y-m'));
    }

    private static function usd(float $amount): float
    {
        return round($amount, 4);
    }
}
