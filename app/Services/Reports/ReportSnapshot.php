<?php

namespace App\Services\Reports;

use App\Models\RankCheck;
use App\Models\RankKeyword;
use App\Models\Report;
use App\Models\Site;
use App\Models\SiteAudit;
use App\Models\Workspace;
use App\Services\RankTracking\RankKeywordQuery;
use App\Services\RankTracking\RankReports;
use App\Services\RankTracking\SerpParser;
use App\Services\SearchConsole\ReportPeriod;
use App\Services\SearchConsole\SearchConsoleReports;
use App\Services\SiteAudit\AuditReports;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Freezes a report's numbers: everything the PDF, the share page and the
 * email show, read once at generation (reports.data). Built from the same
 * services as the app's pages, inside the report's workspace (the caller
 * wraps it in CurrentWorkspace::using()).
 *
 * Each requested section is either `{available: true, …figures}` or
 * `{available: false, reason}` in plain words; sections not requested are
 * left out. The snapshot holds no ids or emails: the public page returns it
 * as is (minus `branding`, which carries the logo's storage path).
 */
class ReportSnapshot
{
    public function __construct(
        private readonly SearchConsoleReports $searchConsole,
        private readonly AuditReports $audits,
    ) {}

    /** @return array<string, mixed> */
    public function build(Report $report): array
    {
        $site = $report->site;
        $workspace = Workspace::query()->findOrFail($report->workspace_id);
        $start = CarbonImmutable::parse($report->period_start)->startOfDay();
        $end = CarbonImmutable::parse($report->period_end)->startOfDay();
        $compare = ReportPeriods::compare($start, $end, $report->compare);
        $sections = array_values(array_intersect(Report::SECTIONS, $report->sections ?? []));

        $data = [
            'branding' => ['name' => $workspace->name, 'logo' => $workspace->logo_path, 'brand_color' => $workspace->brandColor()],
            'client' => ['name' => $report->client?->name],
            'site' => ['name' => $site->name, 'url' => $site->url],
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'label' => ReportPeriods::label($start, $end)],
            'compare_period' => $compare ? [
                'start' => $compare[0]->toDateString(), 'end' => $compare[1]->toDateString(), 'label' => ReportPeriods::label($compare[0], $compare[1]),
            ] : null,
            'sections' => $sections,
        ];

        foreach ($sections as $section) {
            $data[$section] = match ($section) {
                'search_console' => $this->searchConsole($site, $start, $end, $compare),
                'rank_tracking' => $this->rankTracking($site, $start, $end),
                'site_audit' => $this->siteAudit($site, $end),
            };
        }

        return $data;
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $compare
     * @return array<string, mixed>
     */
    public function searchConsole(Site $site, CarbonImmutable $start, CarbonImmutable $end, ?array $compare): array
    {
        if (! $site->hasSearchConsole()) {
            return self::unavailable('Google Search Console is not connected for this website.');
        }
        if ($site->gsc_data_through === null || $site->gsc_data_through->lt($start)) {
            return self::unavailable('Google has not published search data for this period yet.');
        }

        $period = new ReportPeriod($start, $end, $compare[0] ?? null, $compare[1] ?? null);
        $overview = $this->searchConsole->overview($site, $period);
        $totals = $overview['totals'];
        if ($totals['impressions'] === 0) {
            return self::unavailable('The website did not appear in Google search during this period.');
        }
        $previous = $overview['compare_totals'];
        $top = ['per_page' => 10, 'page' => 1, 'sort' => '-clicks'];

        return [
            'available' => true,
            'data_through' => $overview['data_through'],
            'totals' => $totals,
            'compare_totals' => $previous,
            'changes' => [
                'clicks_pct' => $previous ? self::pct($totals['clicks'], $previous['clicks']) : null,
                'impressions_pct' => $previous ? self::pct($totals['impressions'], $previous['impressions']) : null,
                // Percentage points.
                'ctr' => $previous && $previous['impressions'] > 0 ? round(($totals['ctr'] - $previous['ctr']) * 100, 2) : null,
                // Positive = moved up (a smaller position number).
                'position' => $previous && $previous['position'] !== null && $totals['position'] !== null ? round($previous['position'] - $totals['position'], 1) : null,
            ],
            // Days Google hasn't published yet are left out (they'd read as zero).
            'series' => collect($overview['series'])->filter(fn (array $d) => $d['date'] <= $overview['data_through'])
                ->map(fn (array $d) => ['date' => $d['date'], 'clicks' => $d['clicks'], 'impressions' => $d['impressions']])->values()->all(),
            'top_queries' => collect($this->searchConsole->dimension($site, 'query', $period, $top)->items())
                ->map(fn (array $r) => ['query' => $r['query'], 'clicks' => $r['clicks'], 'impressions' => $r['impressions'], 'position' => $r['position']])->values()->all(),
            'top_pages' => collect($this->searchConsole->dimension($site, 'page', $period, $top)->items())
                ->map(fn (array $r) => ['page' => $r['page'], 'clicks' => $r['clicks'], 'impressions' => $r['impressions'], 'position' => $r['position']])->values()->all(),
            'quick_wins' => collect($this->searchConsole->quickWins($site, $period, ['per_page' => 5, 'page' => 1])->items())
                ->map(fn (array $r) => ['query' => $r['query'], 'page' => $r['page'], 'position' => $r['position'], 'impressions' => $r['impressions'], 'potential_clicks' => $r['potential_clicks']])->values()->all(),
        ];
    }

    /**
     * Active keywords: each one's latest result by the end of the period
     * against its latest result before the period began (or, for keywords
     * first checked during the period, their first result in it).
     *
     * @return array<string, mixed>
     */
    public function rankTracking(Site $site, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $keywords = RankKeyword::query()->where('site_id', $site->id)->where('is_active', true)
            ->orderBy('id')->get(['id', 'keyword', 'location_name', 'device'])->keyBy('id');
        if ($keywords->isEmpty()) {
            return self::unavailable('No keywords are tracked for this website yet.');
        }

        $now = $this->positions($site, fn ($q) => $q->where('checked_on', '<=', $end->toDateString())->orderByDesc('checked_on'));
        if ($now === []) {
            return self::unavailable('No keyword rankings had been checked by the end of this period.');
        }
        $then = $this->positions($site, fn ($q) => $q->where('checked_on', '<', $start->toDateString())->orderByDesc('checked_on'))
            + $this->positions($site, fn ($q) => $q->whereBetween('checked_on', [$start->toDateString(), $end->toDateString()])->orderBy('checked_on'));

        $nr = RankKeywordQuery::notRanking();
        $rows = collect();
        foreach ($now as $id => $current) {
            $keyword = $keywords->get($id);
            $from = $then[$id]['position'] ?? null;
            $to = $current['position'];
            $rows->push([
                'keyword' => $keyword->keyword, 'location_name' => $keyword->location_name, 'device' => $keyword->device,
                'from' => $from, 'to' => $to, 'change' => ($from ?? $nr) - ($to ?? $nr), 'serp' => $current['serp'],
            ]);
        }

        $crossed = fn (int $band, bool $entered) => $rows
            ->filter(fn (array $r) => $entered
                ? ($r['to'] !== null && $r['to'] <= $band && ($r['from'] === null || $r['from'] > $band))
                : ($r['from'] !== null && $r['from'] <= $band && ($r['to'] === null || $r['to'] > $band)))
            ->sortBy([[$entered ? 'to' : 'from', 'asc'], ['keyword', 'asc']]);
        $band = fn (int $band, bool $entered) => [
            'count' => $crossed($band, $entered)->count(),
            'keywords' => $crossed($band, $entered)->pluck('keyword')->unique()->take(10)->values()->all(),
        ];
        $mover = fn (array $r) => ['keyword' => $r['keyword'], 'location_name' => $r['location_name'], 'device' => $r['device'], 'from' => $r['from'], 'to' => $r['to'], 'change' => $r['change']];

        $features = [];
        foreach (SerpParser::OWNABLE as $feature) {
            $features[$feature] = [
                'present' => $rows->filter(fn (array $r) => (bool) ($r['serp'][$feature]['present'] ?? false))->count(),
                'ours' => $rows->filter(fn (array $r) => (bool) ($r['serp'][$feature]['ours'] ?? false))->count(),
            ];
        }

        $avgNow = self::average($rows->pluck('to'));
        $avgThen = self::average($rows->pluck('from'));

        return [
            'available' => true,
            'keywords_tracked' => $keywords->count(),
            'keywords_checked' => $rows->count(),
            'avg_position' => $avgNow,
            'avg_position_start' => $avgThen,
            // Positive = moved up.
            'avg_position_change' => $avgNow !== null && $avgThen !== null ? round($avgThen - $avgNow, 1) : null,
            'distribution' => RankReports::distribution($rows->pluck('to')),
            'distribution_start' => RankReports::distribution($rows->pluck('from')),
            'entered_top10' => $band(10, true),
            'left_top10' => $band(10, false),
            'entered_top3' => $band(3, true),
            'left_top3' => $band(3, false),
            'gains' => $rows->where('change', '>', 0)->sortBy([['change', 'desc'], ['keyword', 'asc']])->take(5)->map($mover)->values()->all(),
            'drops' => $rows->where('change', '<', 0)->sortBy([['change', 'asc'], ['keyword', 'asc']])->take(5)->map($mover)->values()->all(),
            'serp_features' => $features,
        ];
    }

    /** The latest finished audit by the end of the period. @return array<string, mixed> */
    public function siteAudit(Site $site, CarbonImmutable $end): array
    {
        $audit = SiteAudit::query()->where('site_id', $site->id)->where('status', SiteAudit::DONE)
            ->where('finished_at', '<=', $end->endOfDay())
            ->orderByDesc('finished_at')->orderByDesc('id')->first();
        if (! $audit) {
            return self::unavailable('No website health check had finished by the end of this period.');
        }

        $previous = $audit->previous_audit_id ? SiteAudit::query()->find($audit->previous_audit_id) : null;
        $counts = $audit->counts ?? [];

        return [
            'available' => true,
            'audited_on' => $audit->finished_at->toDateString(),
            'pages_crawled' => $audit->pages_crawled,
            'health_score' => $audit->health_score,
            'previous_health_score' => $previous?->health_score,
            'health_change' => $previous && $previous->health_score !== null && $audit->health_score !== null ? $audit->health_score - $previous->health_score : null,
            'counts' => [
                'critical' => (int) ($counts['critical'] ?? 0),
                'warning' => (int) ($counts['warning'] ?? 0),
                'notice' => (int) ($counts['notice'] ?? 0),
                'total' => (int) ($counts['total'] ?? 0),
            ],
            'fixed' => $audit->comparison['fixed'] ?? null,
            'new' => $audit->comparison['new'] ?? null,
            'top_issues' => collect($this->audits->issues($audit))->take(5)->map(fn (array $i) => [
                'title' => $i['title'], 'severity' => $i['severity'], 'count' => $i['count'], 'how_to_fix' => self::firstSentence($i['how_to_fix']),
            ])->values()->all(),
        ];
    }

    /**
     * Each active keyword's first done check in $order (one per keyword).
     *
     * @return array<int, array{position: ?int, serp: array<string, mixed>}>
     */
    private function positions(Site $site, \Closure $order): array
    {
        $query = RankCheck::query()->toBase()
            ->selectRaw('DISTINCT ON (keyword_id) keyword_id, position, serp_features')
            ->where('site_id', $site->id)->where('status', RankCheck::DONE)
            ->whereIn('keyword_id', RankKeyword::query()->where('site_id', $site->id)->where('is_active', true)->select('id'))
            ->orderBy('keyword_id');
        $order($query);

        return $query->get()->mapWithKeys(fn (object $r) => [(int) $r->keyword_id => [
            'position' => $r->position === null ? null : (int) $r->position,
            'serp' => json_decode((string) $r->serp_features, true) ?: [],
        ]])->all();
    }

    /** @param  Collection<int, ?int>  $positions */
    private static function average(Collection $positions): ?float
    {
        $ranking = $positions->filter(fn ($p) => $p !== null);

        return $ranking->isEmpty() ? null : round($ranking->avg(), 1);
    }

    /** % change, null without a base to compare with. */
    public static function pct(int|float $now, int|float $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }

    private static function firstSentence(string $text): string
    {
        $first = preg_split('/(?<=[.!?])\s+/', trim($text), 2)[0] ?? $text;

        return Str::limit($first, 180);
    }

    /** @return array{available: false, reason: string} */
    private static function unavailable(string $reason): array
    {
        return ['available' => false, 'reason' => $reason];
    }
}
