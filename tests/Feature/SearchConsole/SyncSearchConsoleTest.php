<?php

namespace Tests\Feature\SearchConsole;

use App\Exceptions\GoogleApiException;
use App\Jobs\SyncSearchConsole;
use App\Models\GscDailyTotal;
use App\Models\GscQueryPageDaily;
use App\Models\Site;
use App\Services\SearchConsole\SearchConsoleSync;
use App\Support\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AuthTestHelpers;
use Tests\Support\SearchConsoleHelpers;
use Tests\TestCase;

class SyncSearchConsoleTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase, SearchConsoleHelpers;

    /** @var list<array<string, mixed>> request bodies Google received */
    private array $requests = [];

    /** @var array{0: array, 1: array} what the fake serves: [totals, rows] */
    private array $google;

    /** @var list<int|null> the current workspace while each request was made */
    private array $workspaceSeen = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->travelTo('2026-06-15 08:00:00');
        config(['indlela.search_console.row_limit' => 2, 'indlela.search_console.chunk_days' => 600]);
        Http::preventStrayRequests();
    }

    /**
     * Fake searchAnalytics: dimension [date] → $totals; [date, query, page] →
     * $rows served in pages of row_limit.
     */
    private function fakeGoogle(array $totals, array $rows): void
    {
        $registered = isset($this->google);
        $this->google = [$totals, $rows];
        if ($registered) {
            return; // a second Http::fake would sit behind the first
        }

        Http::fake(['www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => function (Request $request) {
            $this->requests[] = $request->data();
            $this->workspaceSeen[] = app(CurrentWorkspace::class)->id();
            [$totals, $rows] = $this->google;
            $source = $request['dimensions'] === ['date'] ? $totals : $rows;

            return Http::response(['rows' => array_slice($source, $request['startRow'], $request['rowLimit'])]);
        }]);
    }

    private function row(array $keys, int $clicks, int $impressions, float $position): array
    {
        return ['keys' => $keys, 'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $impressions ? $clicks / $impressions : 0, 'position' => $position];
    }

    private function sampleRows(int $clicks = 1): array
    {
        return [
            $this->row(['2026-06-10', 'coffee', 'https://karoo.co.za/'], $clicks, 10, 2.5),
            $this->row(['2026-06-10', 'coffee beans', 'https://karoo.co.za/shop'], 2, 30, 6.0),
            $this->row(['2026-06-11', 'coffee', 'https://karoo.co.za/'], 3, 12, 2.0),
            $this->row(['2026-06-12', 'karoo coffee', 'https://karoo.co.za/'], 5, 6, 1.0),
            $this->row(['2026-06-12', 'coffee', 'https://karoo.co.za/'], 0, 4, 3.1),
        ];
    }

    public function test_the_first_sync_backfills_16_months_paging_through_every_row(): void
    {
        $this->connectGoogle();
        $site = $this->linkedSite(property: 'https://karoo.co.za/', state: ['gsc_sync_status' => 'queued']);
        $this->fakeGoogle([$this->row(['2026-06-10'], 7, 50, 4.2), $this->row(['2026-06-12'], 9, 60, 3.0)], $this->sampleRows());

        SyncSearchConsole::dispatchSync($site->id, $site->workspace_id);

        // 1 totals request + 3 pages of 2, 2, 1 rows.
        $this->assertCount(4, $this->requests);
        $this->assertSame(['startDate' => '2025-02-15', 'endDate' => '2026-06-14', 'dimensions' => ['date'], 'type' => 'web', 'rowLimit' => 2, 'startRow' => 0], $this->requests[0]);
        $this->assertSame([0, 2, 4], array_column(array_slice($this->requests, 1), 'startRow'));
        $this->assertSame(['date', 'query', 'page'], $this->requests[1]['dimensions']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/sites/https%3A%2F%2Fkaroo.co.za%2F/searchAnalytics/query'));

        $this->assertSame(5, GscQueryPageDaily::withoutGlobalScopes()->count());
        $this->assertSame(2, GscDailyTotal::withoutGlobalScopes()->count());
        $row = GscQueryPageDaily::withoutGlobalScopes()->where('query', 'coffee beans')->sole();
        $this->assertSame([$site->workspace_id, 2, 30, 6.0, md5('coffee beans'), md5('https://karoo.co.za/shop')], [$row->workspace_id, $row->clicks, $row->impressions, $row->position, $row->query_hash, $row->page_hash]);

        $site->refresh();
        $this->assertSame('done', $site->gsc_sync_status);
        $this->assertSame('2026-06-12', $site->gsc_data_through->toDateString());
        $this->assertNotNull($site->gsc_synced_at);
        $this->assertNull($site->gsc_sync_error);
    }

    public function test_later_syncs_refetch_the_last_days_and_upsert_without_duplicates(): void
    {
        $this->connectGoogle();
        $site = $this->linkedSite();
        $this->fakeGoogle([$this->row(['2026-06-10'], 7, 50, 4.2)], $this->sampleRows());
        SyncSearchConsole::dispatchSync($site->id, $site->workspace_id);
        $this->assertSame('2026-06-10', $site->fresh()->gsc_data_through->toDateString());

        // Google revised a day: same rows, one changed.
        $this->requests = [];
        config(['indlela.search_console.chunk_days' => 5]);
        $this->fakeGoogle([$this->row(['2026-06-10'], 8, 55, 4.0)], $this->sampleRows(clicks: 4));
        SyncSearchConsole::dispatchSync($site->id, $site->workspace_id);

        // From 4 days before the latest date held (5 days in all) up to yesterday.
        $this->assertSame(['2026-06-06', '2026-06-14'], [$this->requests[0]['startDate'], $this->requests[0]['endDate']]);
        // Query rows in 5-day chunks.
        $chunks = collect(array_slice($this->requests, 1))->map(fn (array $r) => $r['startDate'].'..'.$r['endDate'])->unique()->values()->all();
        $this->assertSame(['2026-06-06..2026-06-10', '2026-06-11..2026-06-14'], $chunks);
        $this->assertSame(5, GscQueryPageDaily::withoutGlobalScopes()->count());
        $this->assertSame(4, GscQueryPageDaily::withoutGlobalScopes()->where('date', '2026-06-10')->where('query', 'coffee')->value('clicks'));
        $this->assertSame(['clicks' => 8, 'impressions' => 55], GscDailyTotal::withoutGlobalScopes()->sole()->only(['clicks', 'impressions']));
    }

    public function test_the_window(): void
    {
        $sync = app(SearchConsoleSync::class);
        $site = new Site;

        $this->assertSame(['2025-02-15', '2026-06-14'], array_map(fn ($d) => $d->toDateString(), $sync->window($site)));

        $site->gsc_data_through = '2026-05-01'; // weeks behind: catch up from there
        $this->assertSame(['2026-04-27', '2026-06-14'], array_map(fn ($d) => $d->toDateString(), $sync->window($site)));

        $site->gsc_data_through = '2026-06-14';
        $this->assertSame(['2026-06-10', '2026-06-14'], array_map(fn ($d) => $d->toDateString(), $sync->window($site)));
    }

    public function test_the_job_runs_in_its_workspace_and_ignores_a_mismatched_one(): void
    {
        $this->connectGoogle();
        $site = $this->linkedSite();
        $rival = $this->makeWorkspace('Rival');
        $this->connectGoogle($rival);
        $this->fakeGoogle([], []);

        SyncSearchConsole::dispatchSync($site->id, $rival->id);
        $this->assertSame([], $this->requests, 'a site looked up in another workspace is not found');
        $this->assertSame('done', $site->fresh()->gsc_sync_status);

        SyncSearchConsole::dispatchSync($site->id, $site->workspace_id);
        $this->assertNotEmpty($this->workspaceSeen);
        $this->assertSame([$site->workspace_id], array_values(array_unique($this->workspaceSeen)));
        $this->assertNull(app(CurrentWorkspace::class)->id(), 'restored afterwards');
    }

    public function test_google_errors_fail_the_sync_and_retry_while_no_connection_fails_quietly(): void
    {
        $site = $this->linkedSite();

        SyncSearchConsole::dispatchSync($site->id, $site->workspace_id);
        $this->assertSame('failed', $site->fresh()->gsc_sync_status);
        $this->assertStringContainsString('Connect a Google account', $site->fresh()->gsc_sync_error);

        $this->connectGoogle();
        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['code' => 403, 'message' => 'User does not have sufficient permission for site.']], 403)]);
        try {
            SyncSearchConsole::dispatchSync($site->id, $site->workspace_id);
            $this->fail('A Google error is rethrown so the queue retries.');
        } catch (GoogleApiException $e) {
            $this->assertSame(403, $e->googleStatus);
        }
        $this->assertSame('failed', $site->fresh()->gsc_sync_status);
        $this->assertStringContainsString('sufficient permission', $site->fresh()->gsc_sync_error);
    }

    public function test_the_daily_command_queues_active_linked_sites_of_connected_workspaces(): void
    {
        Queue::fake();
        $this->connectGoogle();
        $linked = $this->linkedSite();
        $this->linkedSite(property: 'sc-domain:inactive.co.za')->forceFill(['is_active' => false])->save();
        Site::factory()->create(['client_id' => $this->makeClient()->id]); // not linked
        $rival = $this->makeWorkspace('No Google');
        $this->linkedSite($this->makeClient($rival), 'sc-domain:rival.co.za');

        $this->artisan('search-console:sync')->expectsOutput('Queued 1 Search Console sync(s).')->assertSuccessful();

        Queue::assertPushed(SyncSearchConsole::class, 1);
        Queue::assertPushed(SyncSearchConsole::class, fn (SyncSearchConsole $job) => $job->siteId === $linked->id);
        $this->assertSame('queued', $linked->fresh()->gsc_sync_status);
    }
}
