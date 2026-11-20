<?php

namespace App\Services\RankTracking;

use App\Models\RankCheck;
use App\Models\RankKeyword;
use App\Models\Site;
use App\Services\SearchConsole\ExpectedCtr;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The rank tracking reports: a site's keyword list, its summary (distribution,
 * visibility, gains and drops, SERP features), cannibalisation, one keyword's
 * history, and the per-site figures of the sites list. Summaries cover active
 * keywords; the keyword list shows inactive ones too.
 *
 * Visibility (0–100) = Σ expected CTR at each keyword's position ÷ (keywords
 * with a result × expected CTR at position 1) × 100, using the Search Console
 * expected-CTR curve (config indlela.search_console.expected_ctr); positions
 * beyond the curve (20) and "not in the top 100" score 0. Every keyword
 * weighs the same (no search volumes).
 */
class RankReports
{
    public const SORTS = ['keyword', 'position', 'change_7d', 'change_30d', 'best_position'];

    public const BUCKETS = ['top3', 'top10', 'top100', 'not_ranking'];

    /** @param  array<string, mixed>  $filters  search, device, location, tag, bucket, is_key, sort */
    public function keywords(Site $site, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = RankKeywordQuery::for($site->id)
            ->when($filters['search'] ?? null, fn (Builder $q, string $s) => $q->whereRaw('rank_keywords.keyword ILIKE ?', [self::like($s)]))
            ->when($filters['device'] ?? null, fn (Builder $q, string $d) => $q->where('rank_keywords.device', $d))
            ->when($filters['location'] ?? null, fn (Builder $q, string $l) => $q->whereRaw('rank_keywords.location_name ILIKE ?', [self::like($l)]))
            ->when($filters['tag'] ?? null, fn (Builder $q, string $t) => $q->whereRaw('rank_keywords.tags::jsonb @> ?::jsonb', [json_encode([$t])]))
            ->when($filters['bucket'] ?? null, fn (Builder $q, string $b) => RankKeywordQuery::bucket($q, $b))
            ->when(isset($filters['is_key']), fn (Builder $q) => $q->where('rank_keywords.is_key', filter_var($filters['is_key'], FILTER_VALIDATE_BOOLEAN)));

        $sort = $filters['sort'] ?? 'keyword';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = match (ltrim($sort, '-')) {
            'position' => 'cur.position',
            'change_7d' => 'change_7d',
            'change_30d' => 'change_30d',
            'best_position' => 'best_position',
            default => 'LOWER(rank_keywords.keyword)',
        };
        $query->orderByRaw("{$column} {$direction} NULLS LAST")->orderBy('rank_keywords.id');

        $page = $query->paginate($perPage);
        $history = $this->recentHistory($page->getCollection()->pluck('id')->all(), 30);
        $page->getCollection()->each(fn (RankKeyword $k) => $k->setAttribute('history', $history->get($k->id, [])));

        return $page;
    }

    /** One keyword as a list row (after a change or "check now"). */
    public function row(RankKeyword $keyword): RankKeyword
    {
        $row = RankKeywordQuery::for($keyword->site_id)->whereKey($keyword->id)->firstOrFail();
        $row->setAttribute('history', $this->recentHistory([$row->id], 30)->get($row->id, []));

        return $row;
    }

    /**
     * The last $limit done checks of each keyword, oldest first.
     *
     * @param  list<int>  $keywordIds
     * @return Collection<int, list<array{date: string, position: ?int}>>
     */
    public function recentHistory(array $keywordIds, int $limit): Collection
    {
        if ($keywordIds === []) {
            return collect();
        }

        $ranked = RankCheck::query()->select(['keyword_id', 'checked_on', 'position'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY keyword_id ORDER BY checked_on DESC) AS n')
            ->whereIn('keyword_id', $keywordIds)->where('status', RankCheck::DONE)
            ->toBase();

        return DB::query()->fromSub($ranked, 'h')->where('n', '<=', $limit)->orderBy('checked_on')->get()
            ->groupBy('keyword_id')
            ->map(fn (Collection $rows) => $rows->map(fn (object $r) => ['date' => substr((string) $r->checked_on, 0, 10), 'position' => $r->position === null ? null : (int) $r->position])->values()->all());
    }

    /** @return array<string, mixed> */
    public function summary(Site $site): array
    {
        $keywords = RankKeywordQuery::for($site->id, activeOnly: true)->get();
        $weekAgo = $this->positionsAsOf($site, today()->subDays(7));
        $withResult = $keywords->whereNotNull('position_on');
        $ranking = $withResult->whereNotNull('position');

        $movers = $keywords->filter(fn (RankKeyword $k) => $k->change_7d !== null && (int) $k->change_7d !== 0)
            ->map(fn (RankKeyword $k) => [
                'keyword_id' => $k->id, 'keyword' => $k->keyword, 'location_name' => $k->location_name, 'device' => $k->device,
                'from' => $k->position_7d === null ? null : (int) $k->position_7d,
                'to' => $k->position === null ? null : (int) $k->position,
                'change' => (int) $k->change_7d,
            ]);

        $features = [];
        foreach (SerpParser::OWNABLE as $feature) {
            $features[$feature] = ['present' => 0, 'ours' => 0];
        }
        foreach ($withResult as $k) {
            $serp = json_decode((string) $k->serp_features, true) ?: [];
            foreach (SerpParser::OWNABLE as $feature) {
                $features[$feature]['present'] += (int) ($serp[$feature]['present'] ?? false);
                $features[$feature]['ours'] += (int) ($serp[$feature]['ours'] ?? false);
            }
        }

        $series = $this->visibilitySeries($site, 90);

        return [
            'keywords_count' => $keywords->count(),
            'avg_position' => $ranking->isEmpty() ? null : round($ranking->avg(fn ($k) => (int) $k->position), 1),
            'distribution' => self::distribution($withResult->pluck('position')),
            'distribution_7d_ago' => self::distribution($weekAgo->pluck('position')),
            'visibility' => self::visibility($withResult->pluck('position')) ?? 0.0,
            'visibility_series' => $series,
            'gains' => $movers->where('change', '>', 0)->sortBy([['change', 'desc'], ['keyword', 'asc']])->take(5)->values()->all(),
            'drops' => $movers->where('change', '<', 0)->sortBy([['change', 'asc'], ['keyword', 'asc']])->take(5)->values()->all(),
            'serp_features' => $features,
            ...$this->filterOptions($site),
            'cannibalised_count' => $withResult->filter(fn ($k) => count(json_decode((string) $k->matching_urls, true) ?: []) >= 2)->count(),
        ];
    }

    /**
     * The keyword list's filter choices: distinct location names and tags of
     * every keyword of the site (inactive ones too), sorted.
     *
     * @return array{locations: list<string>, tags: list<string>}
     */
    public function filterOptions(Site $site): array
    {
        $keywords = RankKeyword::query()->where('site_id', $site->id)->get(['location_name', 'tags']);
        $sorted = fn ($values) => $values->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        return [
            'locations' => $sorted($keywords->pluck('location_name')),
            'tags' => $sorted($keywords->pluck('tags')->flatten()),
        ];
    }

    /**
     * Exclusive bands of the latest positions: 1–3, 4–10, 11–20, 21–100 and
     * not in the top 100 (keywords never checked are left out).
     *
     * @param  Collection<int, mixed>  $positions
     * @return array{top3: int, top10: int, top20: int, top100: int, not_ranking: int}
     */
    public static function distribution(Collection $positions): array
    {
        $out = ['top3' => 0, 'top10' => 0, 'top20' => 0, 'top100' => 0, 'not_ranking' => 0];
        foreach ($positions as $p) {
            $key = match (true) {
                $p === null => 'not_ranking',
                $p <= 3 => 'top3',
                $p <= 10 => 'top10',
                $p <= 20 => 'top20',
                default => 'top100',
            };
            $out[$key]++;
        }

        return $out;
    }

    /**
     * See the class comment; null when no keyword has a result.
     *
     * @param  Collection<int, mixed>  $positions  one per keyword with a result (null = not ranking)
     */
    public static function visibility(Collection $positions): ?float
    {
        if ($positions->isEmpty()) {
            return null;
        }
        $curve = config('indlela.search_console.expected_ctr');
        $last = max(array_keys($curve));
        $score = $positions->sum(fn ($p) => $p !== null && (int) $p <= $last ? ExpectedCtr::at((float) $p) : 0.0);

        return round($score / ($positions->count() * ExpectedCtr::at(1)) * 100, 1);
    }

    /**
     * Each day of the last $days: visibility and average position from every
     * active keyword's latest result on or before that day.
     *
     * @return list<array{date: string, visibility: ?float, avg_position: ?float}>
     */
    public function visibilitySeries(Site $site, int $days): array
    {
        $start = today()->subDays($days - 1);
        $active = RankKeyword::query()->where('site_id', $site->id)->where('is_active', true)->select('id');

        // Each keyword's position before the window, then every result in it.
        $known = $this->positionsAsOf($site, $start->copy()->subDay())->mapWithKeys(fn ($r) => [$r->keyword_id => $r->position])->all();
        $byDay = RankCheck::query()->where('site_id', $site->id)->whereIn('keyword_id', $active)->where('status', RankCheck::DONE)
            ->where('checked_on', '>=', $start->toDateString())->where('checked_on', '<=', today()->toDateString())
            ->orderBy('checked_on')->get(['keyword_id', 'checked_on', 'position'])
            ->groupBy(fn (RankCheck $c) => $c->checked_on->toDateString());

        $series = [];
        foreach (CarbonPeriod::create($start, today()) as $day) {
            foreach ($byDay->get($day->toDateString(), []) as $check) {
                $known[$check->keyword_id] = $check->position;
            }
            $positions = collect(array_values($known));
            $ranking = $positions->filter(fn ($p) => $p !== null);
            $series[] = [
                'date' => $day->toDateString(),
                'visibility' => self::visibility($positions),
                'avg_position' => $ranking->isEmpty() ? null : round($ranking->avg(), 1),
            ];
        }

        return $series;
    }

    /**
     * Each active keyword's latest done result on or before $day.
     *
     * @return Collection<int, object{keyword_id: int, position: ?int}>
     */
    public function positionsAsOf(Site $site, CarbonInterface $day): Collection
    {
        return RankCheck::query()->toBase()
            ->selectRaw('DISTINCT ON (keyword_id) keyword_id, position')
            ->where('site_id', $site->id)->where('status', RankCheck::DONE)
            ->where('checked_on', '<=', $day->toDateString())
            ->whereIn('keyword_id', RankKeyword::query()->where('site_id', $site->id)->where('is_active', true)->select('id'))
            ->orderBy('keyword_id')->orderByDesc('checked_on')
            ->get()
            ->map(fn (object $r) => (object) ['keyword_id' => (int) $r->keyword_id, 'position' => $r->position === null ? null : (int) $r->position]);
    }

    /** Active keywords whose latest result has ≥ 2 of the site's URLs, most URLs first. */
    public function cannibalisation(Site $site, int $perPage): LengthAwarePaginator
    {
        return RankKeywordQuery::cannibalised(RankKeywordQuery::for($site->id, activeOnly: true))
            ->orderByRaw('jsonb_array_length(cur.matching_urls::jsonb) DESC')->orderBy('cur.position')->orderBy('rank_keywords.id')
            ->paginate($perPage)
            ->through(fn (RankKeyword $k) => [
                'keyword_id' => $k->id,
                'keyword' => $k->keyword,
                'location_name' => $k->location_name,
                'device' => $k->device,
                'urls' => json_decode((string) $k->matching_urls, true) ?: [],
            ]);
    }

    /** @return list<array{date: string, position: ?int, url: ?string, serp_features: ?array}> */
    public function history(RankKeyword $keyword, int $days): array
    {
        return $keyword->checks()->where('status', RankCheck::DONE)
            ->where('checked_on', '>=', today()->subDays($days - 1)->toDateString())
            ->orderBy('checked_on')->get(['checked_on', 'position', 'url', 'serp_features'])
            ->map(fn (RankCheck $c) => ['date' => $c->checked_on->toDateString(), 'position' => $c->position, 'url' => $c->url, 'serp_features' => $c->serp_features])
            ->all();
    }

    /**
     * The sites list's figures per site (active keywords): count, average
     * position, how many are in the top 3 / 10 / 100 (cumulative), the
     * average 7-day change and the latest check.
     *
     * @param  list<int>  $siteIds
     * @return Collection<int, array<string, mixed>> site id => figures
     */
    public function siteFigures(array $siteIds): Collection
    {
        if ($siteIds === []) {
            return collect();
        }

        $stats = DB::query()->fromSub(RankKeywordQuery::for($siteIds, activeOnly: true)->toBase(), 'k')
            ->select('site_id')
            ->selectRaw('COUNT(*) AS keywords_count')
            ->selectRaw('AVG(position) AS avg_position')
            ->selectRaw('COUNT(*) FILTER (WHERE position <= 3) AS top3')
            ->selectRaw('COUNT(*) FILTER (WHERE position <= 10) AS top10')
            ->selectRaw('COUNT(position) AS top100')
            ->selectRaw('AVG(change_7d) AS change_7d_avg')
            ->selectRaw('MAX(position_on) AS last_checked_on')
            ->groupBy('site_id')
            ->get()->keyBy('site_id');

        return collect($siteIds)->mapWithKeys(function (int $id) use ($stats) {
            $s = $stats->get($id);

            return [$id => [
                'keywords_count' => (int) ($s->keywords_count ?? 0),
                'avg_position' => $s && $s->avg_position !== null ? round((float) $s->avg_position, 1) : null,
                'top3' => (int) ($s->top3 ?? 0),
                'top10' => (int) ($s->top10 ?? 0),
                'top100' => (int) ($s->top100 ?? 0),
                'change_7d_avg' => $s && $s->change_7d_avg !== null ? round((float) $s->change_7d_avg, 1) : null,
                'last_checked_on' => $s && $s->last_checked_on ? substr((string) $s->last_checked_on, 0, 10) : null,
            ]];
        });
    }

    private static function like(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($search)).'%';
    }
}
