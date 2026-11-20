<?php

namespace App\Services\SearchConsole;

use App\Models\GscDailyTotal;
use App\Models\GscQueryPageDaily;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Pulls a site's Search Console data into gsc_daily_totals (dimension
 * [date]) and gsc_query_page_daily ([date, query, page]). Upserts, so a
 * re-sync of the same days changes nothing but revised numbers.
 *
 * Runs inside the site's workspace (SyncSearchConsole wraps it in
 * CurrentWorkspace::using()).
 */
class SearchConsoleSync
{
    public function __construct(
        private readonly SearchConsoleApi $api,
        private readonly GoogleConnectionService $connections,
    ) {}

    /**
     * The days to fetch, up to yesterday: the full 16 months the first time,
     * after that from a few days before the latest date held (GSC lags 2-3
     * days and revises recent days), which also fills any gap since.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(Site $site): array
    {
        $config = config('indlela.search_console');
        $end = CarbonImmutable::yesterday();
        $earliest = $end->subMonthsNoOverflow($config['backfill_months'])->addDay();

        if ($site->gsc_data_through === null) {
            return [$earliest, $end];
        }

        $latest = CarbonImmutable::parse($site->gsc_data_through)->min($end);

        return [$latest->subDays($config['refetch_days'] - 1)->max($earliest), $end];
    }

    /**
     * False when the site was unlinked or linked to another property while
     * this ran: it stops at the next chunk (and clears what it wrote if the
     * site was unlinked); the job queued by the new link does the rest.
     */
    public function sync(Site $site): bool
    {
        $connection = $this->connections->requireActive();
        $property = $site->gsc_property;
        $site->forceFill(['gsc_sync_status' => Site::GSC_SYNCING])->save();

        [$start, $end] = $this->window($site);
        $rowLimit = (int) config('indlela.search_console.row_limit');

        // Totals: one row a day, so one request covers 16 months.
        $totals = $this->api->searchAnalytics($connection, $property, $this->body($start, $end, ['date'], $rowLimit, 0));
        $this->upsertTotals($site, $totals);

        // Query x page rows: date chunks, each paged until a short page.
        for ($from = $start; $from->lte($end); $from = $from->addDays(config('indlela.search_console.chunk_days'))) {
            $to = $from->addDays(config('indlela.search_console.chunk_days') - 1)->min($end);

            for ($startRow = 0; ; $startRow += $rowLimit) {
                $rows = $this->api->searchAnalytics($connection, $property, $this->body($from, $to, ['date', 'query', 'page'], $rowLimit, $startRow));
                $this->upsertQueryPages($site, $rows);

                if (count($rows) < $rowLimit) {
                    break;
                }
            }

            if (! $this->stillLinkedTo($site, $property)) {
                return false;
            }
        }

        $site->forceFill([
            'gsc_sync_status' => Site::GSC_DONE,
            'gsc_synced_at' => now(),
            'gsc_data_through' => GscDailyTotal::query()->where('site_id', $site->id)->max('date'),
            'gsc_sync_error' => null,
        ])->save();

        return true;
    }

    /** Deletes every Search Console row of the site (unlinking, or a different property). */
    public function purge(Site $site): void
    {
        GscQueryPageDaily::query()->where('site_id', $site->id)->delete();
        GscDailyTotal::query()->where('site_id', $site->id)->delete();
    }

    private function stillLinkedTo(Site $site, string $property): bool
    {
        $current = Site::query()->whereKey($site->id)->value('gsc_property');
        if ($current === $property) {
            return true;
        }
        if ($current === null) {
            $this->purge($site);
        }

        return false;
    }

    public function markFailed(Site $site, string $message): void
    {
        $site->forceFill([
            'gsc_sync_status' => Site::GSC_FAILED,
            'gsc_sync_error' => Str::limit($message, 480),
        ])->save();
    }

    /** @return array<string, mixed> */
    private function body(CarbonImmutable $from, CarbonImmutable $to, array $dimensions, int $rowLimit, int $startRow): array
    {
        return [
            'startDate' => $from->toDateString(),
            'endDate' => $to->toDateString(),
            'dimensions' => $dimensions,
            'type' => 'web',
            'rowLimit' => $rowLimit,
            'startRow' => $startRow,
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function upsertTotals(Site $site, array $rows): void
    {
        $records = array_map(fn (array $row) => [
            'workspace_id' => $site->workspace_id,
            'site_id' => $site->id,
            'date' => $row['keys'][0],
            ...$this->metrics($row),
        ], $rows);

        foreach (array_chunk($records, 1000) as $chunk) {
            GscDailyTotal::query()->upsert($chunk, ['site_id', 'date'], ['clicks', 'impressions', 'ctr', 'position']);
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function upsertQueryPages(Site $site, array $rows): void
    {
        foreach (array_chunk($rows, 1000) as $chunk) {
            $records = [];
            foreach ($chunk as $row) {
                [$date, $query, $page] = $row['keys'];
                // Keyed by the unique columns: one statement can't upsert the same row twice.
                $records[$date."\0".$query."\0".$page] = [
                    'workspace_id' => $site->workspace_id,
                    'site_id' => $site->id,
                    'date' => $date,
                    'query' => $query,
                    'page' => $page,
                    'query_hash' => GscQueryPageDaily::hash($query),
                    'page_hash' => GscQueryPageDaily::hash($page),
                    ...$this->metrics($row),
                ];
            }

            GscQueryPageDaily::query()->upsert(array_values($records), ['site_id', 'date', 'query_hash', 'page_hash'], ['clicks', 'impressions', 'ctr', 'position']);
        }
    }

    /** @return array{clicks: int, impressions: int, ctr: float, position: float} */
    private function metrics(array $row): array
    {
        return [
            'clicks' => (int) $row['clicks'],
            'impressions' => (int) $row['impressions'],
            'ctr' => (float) $row['ctr'],
            'position' => (float) $row['position'],
        ];
    }
}
