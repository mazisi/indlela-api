<?php

namespace App\Services\RankTracking;

use App\Models\CompetitorAlert;
use App\Models\RankCheck;
use App\Models\RankCompetitorPosition;
use App\Models\RankKeyword;
use App\Models\Site;
use App\Models\SiteCompetitor;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Competitors tab: figures, share of voice, head-to-head, suggestions
 * and alerts. Everything is over the site's active keywords and each one's
 * latest done check (as the other rank-tracking reports), in grouped SQL,
 * built from the models so the workspace scope applies.
 *
 * Figures (site or competitor): top10 / top3 = keywords in positions 1–10 /
 * 1–3, ranked = keywords in the top 100, avg_position over those.
 * Share of voice = top10 ÷ (site's top10 + every active competitor's) × 100
 * (0 when that sum is 0). A competitor with no position on a keyword's
 * latest check (added after it, from a check before competitor tracking)
 * counts as not ranking there.
 */
class CompetitorReports
{
    public const SORTS = ['keyword', 'position', 'gap'];

    public const ONLY = ['losing', 'winning', 'key'];

    /** @return EloquentCollection<int, SiteCompetitor> the site's competitors (paused too), oldest first */
    public function competitors(Site $site): EloquentCollection
    {
        $competitors = SiteCompetitor::query()->where('site_id', $site->id)->orderBy('id')->get();
        $active = $competitors->where('is_active', true);
        $figures = $this->competitorFigures($site, $active->pluck('id')->all());
        $total = $this->siteFigures($site)['top10'] + $figures->sum('top10');
        $withHistory = $competitors->isEmpty() ? collect() : RankCompetitorPosition::query()
            ->whereIn('competitor_id', $competitors->pluck('id'))->groupBy('competitor_id')->pluck('competitor_id')->flip();

        return $competitors->each(function (SiteCompetitor $c) use ($figures, $total, $withHistory) {
            $f = $c->is_active ? $figures->get($c->id) : null;
            $c->setAttribute('latest', $f ? [...$f, 'sov' => self::sov($f['top10'], $total)] : null);
            $c->setAttribute('has_history', $withHistory->has($c->id));
        });
    }

    /** @return array<string, mixed> */
    public function overview(Site $site): array
    {
        $competitors = SiteCompetitor::query()->where('site_id', $site->id)->active()->orderBy('id')->get(['id', 'name', 'domain']);
        $ids = $competitors->pluck('id')->all();
        $siteFigures = $this->siteFigures($site);
        $figures = $this->competitorFigures($site, $ids);
        $total = $siteFigures['top10'] + $figures->sum('top10');
        $keywords = RankKeyword::query()->where('site_id', $site->id)->where('is_active', true)
            ->selectRaw('COUNT(*) AS total, COUNT(*) FILTER (WHERE is_key) AS key')->toBase()->first();
        $since = $ids === [] ? null : RankCompetitorPosition::query()->whereIn('competitor_id', $ids)->min('checked_on');

        return [
            'site' => ['domain' => $site->domain, ...$siteFigures, 'sov' => self::sov($siteFigures['top10'], $total)],
            'competitors' => $competitors->map(function (SiteCompetitor $c) use ($figures, $total) {
                $f = $figures->get($c->id) ?? self::figures(null);

                return ['id' => $c->id, 'name' => $c->name, 'domain' => $c->domain, ...$f, 'sov' => self::sov($f['top10'], $total)];
            })->values()->all(),
            'trend' => $this->trend($site, $ids),
            'keywords_total' => (int) ($keywords->total ?? 0),
            'key_keywords' => (int) ($keywords->key ?? 0),
            'history_since' => $since ? substr((string) $since, 0, 10) : null,
        ];
    }

    /**
     * Share of voice per ISO week (Monday) over the last
     * rank_tracking.competitor_trend_weeks weeks, oldest first, from each
     * keyword's latest done check in the week. A week with no competitor
     * position yet (before tracking began) has site null and no competitors.
     *
     * @param  list<int>  $ids  active competitors
     * @return list<array{week_start: string, site: ?float, competitors: object}>
     */
    public function trend(Site $site, array $ids): array
    {
        $weeks = (int) config('indlela.rank_tracking.competitor_trend_weeks');
        $start = today()->startOfWeek()->subWeeks($weeks - 1);
        $week = "date_trunc('week', rank_checks.checked_on::timestamp)";

        $weekly = $this->doneChecks($site)
            ->selectRaw("DISTINCT ON (rank_checks.keyword_id, {$week}) rank_checks.id AS check_id, rank_checks.position, {$week}::date AS week_start")
            ->where('rank_checks.checked_on', '>=', $start->toDateString())
            ->orderBy('rank_checks.keyword_id')->orderByRaw($week)->orderByDesc('rank_checks.checked_on');

        $ours = DB::query()->fromSub($weekly, 'w')->groupBy('week_start')
            ->select('week_start')->selectRaw('COUNT(*) FILTER (WHERE position <= 10) AS top10')
            ->pluck('top10', 'week_start');
        $theirs = $ids === [] ? collect() : DB::query()->fromSub($weekly, 'w')
            ->join('rank_competitor_positions as p', 'p.check_id', '=', 'w.check_id')
            ->whereIn('p.competitor_id', $ids)
            ->groupBy('w.week_start', 'p.competitor_id')
            ->select(['w.week_start', 'p.competitor_id'])->selectRaw('COUNT(*) FILTER (WHERE p.position <= 10) AS top10')
            ->get()->groupBy(fn (object $r) => substr((string) $r->week_start, 0, 10));
        $ours = $ours->mapWithKeys(fn ($v, $k) => [substr((string) $k, 0, 10) => (int) $v]);

        $out = [];
        for ($i = 0; $i < $weeks; $i++) {
            $day = $start->copy()->addWeeks($i)->toDateString();
            $rows = $theirs->get($day);
            if (! $rows) {
                $out[] = ['week_start' => $day, 'site' => null, 'competitors' => (object) []];

                continue;
            }
            $counts = $rows->mapWithKeys(fn (object $r) => [(int) $r->competitor_id => (int) $r->top10]);
            $total = (int) $ours->get($day, 0) + $counts->sum();
            $out[] = [
                'week_start' => $day,
                'site' => self::sov((int) $ours->get($day, 0), $total),
                'competitors' => (object) collect($ids)->mapWithKeys(fn (int $id) => [$id => self::sov($counts->get($id, 0), $total)])->all(),
            ];
        }

        return $out;
    }

    /**
     * Head-to-head: each active keyword's latest result against every active
     * competitor's in the same check, and the change since the keyword's
     * previous check (positive = moved up; not ranking = 101).
     * gap = best competitor position − ours (positive = we're ahead; not
     * ranking = 101; null when neither ranks or no competitor position).
     * only: losing = a competitor is ahead; winning = we rank and every
     * competitor is behind; key = key keywords.
     *
     * @param  array{search?: ?string, only?: ?string, sort?: ?string}  $filters
     */
    public function keywords(Site $site, array $filters, int $perPage): LengthAwarePaginator
    {
        $competitors = SiteCompetitor::query()->where('site_id', $site->id)->active()->orderBy('id')->pluck('id')->all();
        $nr = RankKeywordQuery::notRanking();
        $in = $competitors === [] ? '0' : implode(',', array_map('intval', $competitors));
        $positions = fn (string $select, string $where = 'TRUE') => "(SELECT {$select} FROM rank_competitor_positions p WHERE p.keyword_id = rank_keywords.id AND p.checked_on = cur.checked_on AND p.competitor_id IN ({$in}) AND {$where})";

        $base = RankKeywordQuery::for($site->id, activeOnly: true)
            ->selectRaw($positions('MIN(p.position)').' AS best_competitor_position')
            ->selectRaw($positions('COUNT(*)').' AS competitor_rows')
            ->selectRaw($positions('COUNT(*)', "COALESCE(p.position, {$nr}) < COALESCE(cur.position, {$nr})").' AS ahead_count')
            ->selectRaw($positions('COUNT(*)', "COALESCE(p.position, {$nr}) <= COALESCE(cur.position, {$nr})").' AS level_count')
            ->toBase();

        $gap = "CASE WHEN k.competitor_rows = 0 OR (k.best_competitor_position IS NULL AND k.position IS NULL) THEN NULL ELSE COALESCE(k.best_competitor_position, {$nr}) - COALESCE(k.position, {$nr}) END";
        $query = DB::query()->fromSub($base, 'k')->select('k.*')->selectRaw("{$gap} AS gap")
            ->when($filters['search'] ?? null, fn (QueryBuilder $q, string $s) => $q->whereRaw('k.keyword ILIKE ?', [self::like($s)]))
            ->when(($filters['only'] ?? null) === 'losing', fn (QueryBuilder $q) => $q->where('k.ahead_count', '>', 0))
            ->when(($filters['only'] ?? null) === 'winning', fn (QueryBuilder $q) => $q->whereNotNull('k.position')->where('k.competitor_rows', '>', 0)->where('k.level_count', 0))
            ->when(($filters['only'] ?? null) === 'key', fn (QueryBuilder $q) => $q->where('k.is_key', true));

        $sort = $filters['sort'] ?? 'keyword';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = match (ltrim($sort, '-')) {
            'position' => 'k.position',
            'gap' => $gap,
            default => 'LOWER(k.keyword)',
        };
        $page = $query->orderByRaw("{$column} {$direction} NULLS LAST")->orderBy('k.id')->paginate($perPage);

        $rows = $page->getCollection();
        $dates = $rows->pluck('position_on')->merge($rows->pluck('previous_on'))->filter()->map(fn ($d) => substr((string) $d, 0, 10))->unique()->values();
        $known = $rows->isEmpty() || $competitors === [] || $dates->isEmpty() ? collect() : RankCompetitorPosition::query()->toBase()
            ->whereIn('keyword_id', $rows->pluck('id'))->whereIn('competitor_id', $competitors)->whereIn('checked_on', $dates)
            ->get(['keyword_id', 'competitor_id', 'checked_on', 'position'])
            ->keyBy(fn (object $p) => $p->keyword_id.'|'.$p->competitor_id.'|'.substr((string) $p->checked_on, 0, 10));

        return $page->through(function (object $k) use ($competitors, $known, $nr) {
            $ours = $k->position === null ? null : (int) $k->position;
            $on = $k->position_on ? substr((string) $k->position_on, 0, 10) : null;
            $before = $k->previous_on ? substr((string) $k->previous_on, 0, 10) : null;
            $theirs = [];
            $ahead = [];
            foreach ($competitors as $id) {
                $now = $on ? $known->get("{$k->id}|{$id}|{$on}") : null;
                $then = $before ? $known->get("{$k->id}|{$id}|{$before}") : null;
                $position = $now?->position === null ? null : (int) $now->position;
                $theirs[] = [
                    'id' => $id,
                    'position' => $position,
                    'change' => $now && $then ? ($then->position ?? $nr) - ($position ?? $nr) : null,
                ];
                if ($now && ($position ?? $nr) < ($ours ?? $nr)) {
                    $ahead[] = $id;
                }
            }

            return [
                'keyword_id' => (int) $k->id,
                'keyword' => $k->keyword,
                'location_name' => $k->location_name,
                'device' => $k->device,
                'is_key' => (bool) $k->is_key,
                'ours' => ['position' => $ours, 'change' => $k->change_1d === null ? null : (int) $k->change_1d],
                'competitors' => $theirs,
                'best_competitor_position' => $k->best_competitor_position === null ? null : (int) $k->best_competitor_position,
                'gap' => $k->gap === null ? null : (int) $k->gap,
                'ahead_of_us' => $ahead,
            ];
        });
    }

    /**
     * Hosts most often in the top 10 of the site's active keywords' latest
     * checks (only checks that stored their hosts), leaving out the site, its
     * competitors (paused too) and their subdomains.
     *
     * @return list<array{domain: string, top10_keywords: int, keywords_seen: int, best_position: int}>
     */
    public function suggestions(Site $site): array
    {
        $exclude = [
            SerpParser::bareHost((string) $site->domain),
            ...SiteCompetitor::query()->where('site_id', $site->id)->pluck('domain')->all(),
            // Big generic sites aren't competitors (config: rank_tracking.suggestion_blocklist).
            ...config('indlela.rank_tracking.suggestion_blocklist', []),
        ];
        $latest = $this->latest($site)->addSelect('rank_checks.organic_domains')->whereNotNull('rank_checks.organic_domains');

        $query = DB::query()->fromSub($latest, 'l')
            ->crossJoin(DB::raw('LATERAL jsonb_array_elements_text(l.organic_domains::jsonb) WITH ORDINALITY AS d(host, pos)'))
            ->whereNotNull('d.host')
            ->select('d.host AS domain')
            ->selectRaw('COUNT(DISTINCT l.keyword_id) FILTER (WHERE d.pos <= 10) AS top10_keywords')
            ->selectRaw('COUNT(DISTINCT l.keyword_id) AS keywords_seen')
            ->selectRaw('MIN(d.pos) AS best_position')
            ->groupBy('d.host')
            ->havingRaw('COUNT(*) FILTER (WHERE d.pos <= 10) > 0')
            ->orderByDesc('top10_keywords')->orderBy('best_position')->orderBy('domain')
            ->limit((int) config('indlela.rank_tracking.competitor_suggestions'));
        foreach (array_filter($exclude) as $domain) {
            $query->whereRaw('NOT (d.host = ? OR RIGHT(d.host, ?) = ?)', [$domain, strlen($domain) + 1, '.'.$domain]);
        }

        return $query->get()->map(fn (object $r) => [
            'domain' => $r->domain,
            'top10_keywords' => (int) $r->top10_keywords,
            'keywords_seen' => (int) $r->keywords_seen,
            'best_position' => (int) $r->best_position,
        ])->all();
    }

    /** The site's alerts, newest first. */
    public function alerts(Site $site, int $perPage): LengthAwarePaginator
    {
        return CompetitorAlert::query()->where('site_id', $site->id)
            ->with(['keyword:id,keyword,location_name,device', 'competitor:id,name,domain'])
            ->orderByDesc('checked_on')->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (CompetitorAlert $a) => self::alertRow($a));
    }

    /** @return array<string, mixed> */
    public static function alertRow(CompetitorAlert $a): array
    {
        return [
            'id' => $a->id,
            'checked_on' => $a->checked_on->toDateString(),
            'keyword' => $a->keyword ? ['id' => $a->keyword->id, 'keyword' => $a->keyword->keyword, 'location_name' => $a->keyword->location_name, 'device' => $a->keyword->device] : null,
            'competitor' => $a->competitor ? ['id' => $a->competitor->id, 'name' => $a->competitor->name, 'domain' => $a->competitor->domain] : null,
            'our_position' => $a->our_position,
            'their_position' => $a->their_position,
            'previous_our_position' => $a->previous_our_position,
            'previous_their_position' => $a->previous_their_position,
            'emailed_at' => $a->emailed_at?->toIso8601String(),
        ];
    }

    /** @return array{top10: int, top3: int, ranked: int, avg_position: ?float} */
    private function siteFigures(Site $site): array
    {
        return self::figures(DB::query()->fromSub($this->latest($site), 'l')
            ->selectRaw('COUNT(*) FILTER (WHERE position <= 10) AS top10, COUNT(*) FILTER (WHERE position <= 3) AS top3, COUNT(position) AS ranked, AVG(position) AS avg_position')
            ->first());
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, array{top10: int, top3: int, ranked: int, avg_position: ?float}> competitor id => figures (only those with a position)
     */
    private function competitorFigures(Site $site, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return DB::query()->fromSub($this->latest($site), 'l')
            ->join('rank_competitor_positions as p', 'p.check_id', '=', 'l.check_id')
            ->whereIn('p.competitor_id', $ids)
            ->groupBy('p.competitor_id')
            ->select('p.competitor_id')
            ->selectRaw('COUNT(*) FILTER (WHERE p.position <= 10) AS top10, COUNT(*) FILTER (WHERE p.position <= 3) AS top3, COUNT(p.position) AS ranked, AVG(p.position) AS avg_position')
            ->get()
            ->mapWithKeys(fn (object $r) => [(int) $r->competitor_id => self::figures($r)]);
    }

    /** @return array{top10: int, top3: int, ranked: int, avg_position: ?float} */
    private static function figures(?object $row): array
    {
        return [
            'top10' => (int) ($row->top10 ?? 0),
            'top3' => (int) ($row->top3 ?? 0),
            'ranked' => (int) ($row->ranked ?? 0),
            'avg_position' => $row && $row->avg_position !== null ? round((float) $row->avg_position, 1) : null,
        ];
    }

    private static function sov(int $appearances, int $total): float
    {
        return $total === 0 ? 0.0 : round($appearances / $total * 100, 1);
    }

    /** Each active keyword's latest done check: check_id, keyword_id, position, checked_on. */
    private function latest(Site $site): QueryBuilder
    {
        return $this->doneChecks($site)
            ->selectRaw('DISTINCT ON (rank_checks.keyword_id) rank_checks.id AS check_id, rank_checks.keyword_id, rank_checks.position, rank_checks.checked_on')
            ->orderBy('rank_checks.keyword_id')->orderByDesc('rank_checks.checked_on');
    }

    /** Done checks of the site's active keywords. */
    private function doneChecks(Site $site): QueryBuilder
    {
        return RankCheck::query()
            ->where('rank_checks.site_id', $site->id)->where('rank_checks.status', RankCheck::DONE)
            ->whereIn('rank_checks.keyword_id', RankKeyword::query()->where('site_id', $site->id)->where('is_active', true)->select('id'))
            ->toBase();
    }

    private static function like(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($search)).'%';
    }
}
