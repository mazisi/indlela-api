<?php

namespace App\Services\SearchConsole;

use App\Models\GscDailyTotal;
use App\Models\GscQueryPageDaily;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Search Console reports for one site. Each is a single aggregate query
 * (plus one for low CTR's top queries), paginated in SQL.
 *
 * Maths: CTR = clicks / impressions (a fraction); position = the
 * impression-weighted average of the stored average positions. Overview totals
 * come from gsc_daily_totals (accurate); the query/page reports from
 * gsc_query_page_daily, which leaves out anonymised queries.
 *
 * Every query starts from the models, so the BelongsToWorkspace scope applies
 * on top of the site filter.
 */
class SearchConsoleReports
{
    /** Report => sortable columns (the first is the default, descending). */
    public const SORTS = [
        'queries' => ['clicks', 'impressions', 'ctr', 'position', 'clicks_change', 'position_change'],
        'pages' => ['clicks', 'impressions', 'ctr', 'position', 'clicks_change', 'position_change'],
        'quick_wins' => ['potential_clicks', 'impressions', 'clicks', 'ctr', 'position'],
        'low_ctr' => ['missed_clicks', 'impressions', 'clicks', 'ctr', 'expected_ctr', 'position'],
        'content_decay' => ['clicks_lost', 'clicks_last_28', 'clicks_prev_28', 'clicks_change_pct', 'decline_months'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function overview(Site $site, ReportPeriod $period): array
    {
        $empty = ['clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'position' => null];

        if ($site->gsc_data_through === null) {
            return [...$period->toArray(), 'data_through' => null, 'totals' => $empty,
                'compare_totals' => $period->hasCompare() ? $empty : null, 'series' => [], 'compare_series' => $period->hasCompare() ? [] : null];
        }

        $cur = [$period->start->toDateString(), $period->end->toDateString()];
        $cmp = $period->hasCompare() ? [$period->compareStart->toDateString(), $period->compareEnd->toDateString()] : null;

        $days = GscDailyTotal::query()
            ->where('site_id', $site->id)
            ->where(fn (Builder $q) => $q->whereBetween('date', $cur)->when($cmp, fn ($q) => $q->orWhereBetween('date', $cmp)))
            ->orderBy('date')
            ->get(['date', 'clicks', 'impressions', 'ctr', 'position'])
            ->keyBy(fn (GscDailyTotal $day) => $day->date->toDateString());

        $series = fn (CarbonImmutable $from, CarbonImmutable $to) => collect(CarbonPeriod::create($from, $to))
            ->map(function ($date) use ($days) {
                $day = $days->get($date->toDateString());

                return [
                    'date' => $date->toDateString(),
                    'clicks' => $day->clicks ?? 0,
                    'impressions' => $day->impressions ?? 0,
                    'ctr' => round($day->ctr ?? 0, 4),
                    'position' => $day && $day->impressions > 0 ? round($day->position, 1) : null,
                ];
            })->values()->all();

        $current = $series($period->start, $period->end);
        $compare = $cmp ? $series($period->compareStart, $period->compareEnd) : null;

        return [
            ...$period->toArray(),
            'data_through' => $site->gsc_data_through->toDateString(),
            'totals' => $this->totals($days, $cur),
            'compare_totals' => $cmp ? $this->totals($days, $cmp) : null,
            'series' => $current,
            'compare_series' => $compare,
        ];
    }

    /** Queries or pages ($dimension) with their change against the compare period. */
    public function dimension(Site $site, string $dimension, ReportPeriod $period, array $options): LengthAwarePaginator
    {
        $cur = [$period->start->toDateString(), $period->end->toDateString()];
        $cmp = $period->hasCompare() ? [$period->compareStart->toDateString(), $period->compareEnd->toDateString()] : null;

        $inner = $this->rows($site)
            ->selectRaw("{$dimension} AS key")
            ->selectRaw('SUM(clicks) FILTER (WHERE date BETWEEN ? AND ?) AS clicks', $cur)
            ->selectRaw('SUM(impressions) FILTER (WHERE date BETWEEN ? AND ?) AS impressions', $cur)
            ->selectRaw('SUM(position * impressions) FILTER (WHERE date BETWEEN ? AND ?) AS position_weight', $cur)
            ->where(fn (Builder $q) => $q->whereBetween('date', $cur)->when($cmp, fn ($q) => $q->orWhereBetween('date', $cmp)))
            ->when($options['search'] ?? null, fn (Builder $q, string $search) => $q->whereRaw("{$dimension} ILIKE ?", [$this->like($search)]))
            ->groupBy($dimension);

        if ($cmp) {
            $inner->selectRaw('SUM(clicks) FILTER (WHERE date BETWEEN ? AND ?) AS compare_clicks', $cmp)
                ->selectRaw('SUM(impressions) FILTER (WHERE date BETWEEN ? AND ?) AS compare_impressions', $cmp)
                ->selectRaw('SUM(position * impressions) FILTER (WHERE date BETWEEN ? AND ?) AS compare_position_weight', $cmp);
        }

        $metrics = DB::query()->fromSub($inner, 'a')
            ->select('key', 'clicks', 'impressions')
            ->selectRaw('clicks::float / impressions AS ctr')
            ->selectRaw('position_weight / impressions AS position')
            ->where('impressions', '>', 0);

        if ($cmp) {
            $metrics->selectRaw('clicks - COALESCE(compare_clicks, 0) AS clicks_change')
                ->selectRaw('position_weight / impressions - compare_position_weight / NULLIF(compare_impressions, 0) AS position_change');
        } else {
            $metrics->selectRaw('NULL::bigint AS clicks_change, NULL::float AS position_change');
        }

        return $this->paginate(DB::query()->fromSub($metrics, 'r'), $dimension === 'query' ? 'queries' : 'pages', $options, 'key')
            ->through(fn (object $row) => [
                $dimension => $row->key,
                'clicks' => (int) $row->clicks,
                'impressions' => (int) $row->impressions,
                'ctr' => round((float) $row->ctr, 4),
                'position' => round((float) $row->position, 1),
                'clicks_change' => $row->clicks_change === null ? null : (int) $row->clicks_change,
                'position_change' => $row->position_change === null ? null : (round((float) $row->position_change, 1) ?: 0.0), // never -0
            ]);
    }

    /**
     * Queries averaging positions 5-15 with enough impressions: potential_clicks is
     * the extra clicks a month at the target position's expected CTR
     * ((impressions x expected_ctr(target) - clicks), scaled to 30 days).
     */
    public function quickWins(Site $site, ReportPeriod $period, array $options): LengthAwarePaginator
    {
        $config = config('indlela.search_console.quick_wins');
        $targetCtr = ExpectedCtr::at($config['target_position']);
        $perMonth = 30 / $period->days();

        $byQueryPage = $this->rows($site)
            ->select('query', 'page')
            ->selectRaw('SUM(clicks) AS clicks, SUM(impressions) AS impressions, SUM(position * impressions) AS position_weight')
            ->whereBetween('date', [$period->start->toDateString(), $period->end->toDateString()])
            ->when($options['search'] ?? null, fn (Builder $q, string $search) => $q->whereRaw('query ILIKE ?', [$this->like($search)]))
            ->groupBy('query', 'page');

        $byQuery = DB::query()->fromSub($byQueryPage, 'qp')
            ->select('query')
            ->selectRaw('(ARRAY_AGG(page ORDER BY impressions DESC, page))[1] AS page')
            ->selectRaw('SUM(clicks) AS clicks, SUM(impressions) AS impressions')
            ->selectRaw('SUM(clicks)::float / NULLIF(SUM(impressions), 0) AS ctr')
            ->selectRaw('SUM(position_weight) / NULLIF(SUM(impressions), 0) AS position')
            ->groupBy('query');

        $rows = DB::query()->fromSub($byQuery, 'q')
            ->select('*')
            ->selectRaw('GREATEST(0, (impressions * ? - clicks) * ?) AS potential_clicks', [$targetCtr, $perMonth])
            ->whereBetween('position', [$config['min_position'], $config['max_position']])
            ->where('impressions', '>=', $period->scaled($config['min_impressions']));

        return $this->paginate(DB::query()->fromSub($rows, 'r'), 'quick_wins', $options, 'query')
            ->through(fn (object $row) => [
                'query' => $row->query,
                'page' => $row->page,
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
                'ctr' => round((float) $row->ctr, 4),
                'position' => round((float) $row->position, 1),
                'potential_clicks' => (int) round((float) $row->potential_clicks),
            ]);
    }

    /**
     * Page-one pages (position <= 10) with enough impressions whose CTR is below
     * expected_ctr(position) x margin; missed_clicks = impressions x expected_ctr
     * - clicks over the period.
     */
    public function lowCtr(Site $site, ReportPeriod $period, array $options): LengthAwarePaginator
    {
        $config = config('indlela.search_console.low_ctr');
        $range = [$period->start->toDateString(), $period->end->toDateString()];

        $byPage = $this->rows($site)
            ->select('page')
            ->selectRaw('SUM(clicks) AS clicks, SUM(impressions) AS impressions')
            ->selectRaw('SUM(clicks)::float / NULLIF(SUM(impressions), 0) AS ctr')
            ->selectRaw('SUM(position * impressions) / NULLIF(SUM(impressions), 0) AS position')
            ->whereBetween('date', $range)
            ->when($options['search'] ?? null, fn (Builder $q, string $search) => $q->whereRaw('page ILIKE ?', [$this->like($search)]))
            ->groupBy('page');

        $withExpected = DB::query()->fromSub($byPage, 'p')
            ->select('*')
            ->selectRaw(ExpectedCtr::sql('position').' AS expected_ctr')
            ->where('position', '<=', $config['max_position'])
            ->where('impressions', '>=', $period->scaled($config['min_impressions']));

        $rows = DB::query()->fromSub($withExpected, 'e')
            ->select('*')
            ->selectRaw('impressions * expected_ctr - clicks AS missed_clicks')
            ->whereRaw('ctr < expected_ctr * ?', [$config['margin']]);

        $paginator = $this->paginate(DB::query()->fromSub($rows, 'r'), 'low_ctr', $options, 'page');
        $topQueries = $this->topQueries($site, $range, collect($paginator->items())->pluck('page')->all());

        return $paginator->through(fn (object $row) => [
            'page' => $row->page,
            'impressions' => (int) $row->impressions,
            'clicks' => (int) $row->clicks,
            'ctr' => round((float) $row->ctr, 4),
            'expected_ctr' => round((float) $row->expected_ctr, 4),
            'position' => round((float) $row->position, 1),
            'missed_clicks' => (int) round((float) $row->missed_clicks),
            'top_queries' => $topQueries[$row->page] ?? [],
        ]);
    }

    /**
     * Pages losing traffic, as of the site's latest data D: the last 28 days
     * against the 28 before (a drop of drop_pct % or more), or clicks falling in
     * each of the last decline_months full months. A page needs min_clicks in
     * the previous 28 days or in one of the 6 months shown. Ignores start/end.
     */
    public function contentDecay(Site $site, array $options): LengthAwarePaginator
    {
        $config = config('indlela.search_console.decay');
        if ($site->gsc_data_through === null) {
            return new LengthAwarePaginator([], 0, $options['per_page'] ?? 15, $options['page'] ?? 1, ['path' => LengthAwarePaginator::resolveCurrentPath()]);
        }

        $latest = CarbonImmutable::parse($site->gsc_data_through);
        $last = [$latest->subDays(27), $latest];
        $prev = [$latest->subDays(55), $latest->subDays(28)];
        // The last full month is D's own when D is its last day.
        $lastFullMonth = $latest->isLastOfMonth() ? $latest->startOfMonth() : $latest->subMonthNoOverflow()->startOfMonth();
        $months = collect(range(5, 0))->map(fn (int $ago) => $lastFullMonth->subMonthsNoOverflow($ago));
        $from = $prev[0]->min($months->first());

        $inner = $this->rows($site)
            ->select('page')
            ->selectRaw('COALESCE(SUM(clicks) FILTER (WHERE date BETWEEN ? AND ?), 0) AS clicks_last_28', $this->dates($last))
            ->selectRaw('COALESCE(SUM(clicks) FILTER (WHERE date BETWEEN ? AND ?), 0) AS clicks_prev_28', $this->dates($prev))
            ->whereBetween('date', [$from->toDateString(), $latest->toDateString()])
            ->when($options['search'] ?? null, fn (Builder $q, string $search) => $q->whereRaw('page ILIKE ?', [$this->like($search)]))
            ->groupBy('page');
        foreach ($months as $i => $month) {
            $inner->selectRaw("COALESCE(SUM(clicks) FILTER (WHERE date BETWEEN ? AND ?), 0) AS m{$i}", [$month->toDateString(), $month->endOfMonth()->toDateString()]);
        }

        // Consecutive month-on-month falls ending at the last full month (m5 < m4, m4 < m3, …), 0-5.
        $declines = '(CASE WHEN m5 < m4 THEN '.$this->declineRun(0).' ELSE 0 END)';

        $metrics = DB::query()->fromSub($inner, 'a')
            ->select('*')
            ->selectRaw('clicks_prev_28 - clicks_last_28 AS clicks_lost')
            ->selectRaw('(clicks_last_28 - clicks_prev_28) * 100.0 / NULLIF(clicks_prev_28, 0) AS clicks_change_pct')
            ->selectRaw("{$declines} AS decline_months");

        $rows = DB::query()->fromSub($metrics, 'd')
            ->whereRaw('GREATEST(clicks_prev_28, m0, m1, m2, m3, m4, m5) >= ?', [$config['min_clicks']])
            ->where(fn (QueryBuilder $q) => $q
                ->where('clicks_change_pct', '<=', -$config['drop_pct'])
                ->orWhere('decline_months', '>=', $config['decline_months']));

        return $this->paginate($rows, 'content_decay', $options, 'page')
            ->through(fn (object $row) => [
                'page' => $row->page,
                'clicks_last_28' => (int) $row->clicks_last_28,
                'clicks_prev_28' => (int) $row->clicks_prev_28,
                'clicks_change_pct' => $row->clicks_change_pct === null ? null : round((float) $row->clicks_change_pct, 1),
                'monthly' => $months->map(fn (CarbonImmutable $month, int $i) => ['month' => $month->format('Y-m'), 'clicks' => (int) $row->{"m{$i}"}])->values()->all(),
                'decline_months' => (int) $row->decline_months,
            ]);
    }

    /** The site's query x page rows (workspace scope applied). */
    private function rows(Site $site): Builder
    {
        return GscQueryPageDaily::query()->where('site_id', $site->id);
    }

    /** Given m5 < m4: CASE WHEN m4 < m3 THEN (CASE WHEN m3 < m2 THEN … ELSE 2 END) ELSE 1 END. */
    private function declineRun(int $depth): string
    {
        if ($depth === 4) {
            return '5';
        }
        $a = 4 - $depth;

        return "CASE WHEN m{$a} < m".($a - 1).' THEN '.$this->declineRun($depth + 1).' ELSE '.($depth + 1).' END';
    }

    /**
     * The 3 queries with the most impressions for each of $pages, in one query.
     *
     * @param  array{0: string, 1: string}  $range
     * @param  list<string>  $pages
     * @return array<string, list<string>>
     */
    private function topQueries(Site $site, array $range, array $pages): array
    {
        if ($pages === []) {
            return [];
        }

        $ranked = $this->rows($site)
            ->select('page', 'query')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY page ORDER BY SUM(impressions) DESC, SUM(clicks) DESC, query) AS rank')
            ->whereBetween('date', $range)
            ->whereIn('page_hash', array_map(GscQueryPageDaily::hash(...), $pages))
            ->groupBy('page', 'query');

        return DB::query()->fromSub($ranked, 't')
            ->where('rank', '<=', 3)
            ->orderBy('page')->orderBy('rank')
            ->get()
            ->groupBy('page')
            ->map(fn ($rows) => $rows->pluck('query')->all())
            ->all();
    }

    /** Sort (`-col` = descending; default: the report's first column, descending) and paginate. */
    private function paginate(QueryBuilder $query, string $report, array $options, string $tieBreaker): LengthAwarePaginator
    {
        $allowed = self::SORTS[$report];
        $sort = $options['sort'] ?? '-'.$allowed[0];
        $column = ltrim($sort, '-');

        if (! in_array($column, $allowed, true)) {
            throw ValidationException::withMessages(['sort' => 'Sort by one of: '.implode(', ', $allowed).' (prefix - for descending).']);
        }

        return $query
            ->orderByRaw(sprintf('%s %s NULLS LAST', $column, str_starts_with($sort, '-') ? 'DESC' : 'ASC'))
            ->orderBy($tieBreaker)
            ->paginate($options['per_page'] ?? 15, ['*'], 'page', $options['page'] ?? null);
    }

    /**
     * Sums over the days in $range; position weighted by each day's impressions.
     *
     * @param  Collection<string, GscDailyTotal>  $days
     * @param  array{0: string, 1: string}  $range
     * @return array{clicks: int, impressions: int, ctr: float, position: float|null}
     */
    private function totals(Collection $days, array $range): array
    {
        $in = $days->filter(fn (GscDailyTotal $day, string $date) => $date >= $range[0] && $date <= $range[1]);
        $clicks = (int) $in->sum('clicks');
        $impressions = (int) $in->sum('impressions');
        $weight = $in->sum(fn (GscDailyTotal $day) => $day->position * $day->impressions);

        return [
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? round($clicks / $impressions, 4) : 0.0,
            'position' => $impressions > 0 ? round($weight / $impressions, 1) : null,
        ];
    }

    /** @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $range */
    private function dates(array $range): array
    {
        return [$range[0]->toDateString(), $range[1]->toDateString()];
    }

    private function like(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
    }
}
