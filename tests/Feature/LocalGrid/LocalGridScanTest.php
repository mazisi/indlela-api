<?php

namespace Tests\Feature\LocalGrid;

use App\Jobs\RunMapGridScan;
use App\Models\MapGrid;
use App\Models\MapGridPoint;
use App\Models\MapGridScan;
use App\Models\MapGridSearch;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\RankTracking\RankSpend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\AuthTestHelpers;
use Tests\Support\LocalGridHelpers;
use Tests\TestCase;

/** Running scans: rounds, matching, failures, the daily limit, pausing and the platform spend cap. */
class LocalGridScanTest extends TestCase
{
    use AuthTestHelpers, LocalGridHelpers, RefreshDatabase;

    private Site $site;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->travelTo('2026-11-20 10:00:00');
        $this->configureDataForSeo();
        $this->answerMaps(fn () => 2);
        $this->site = $this->rankSite();
        $this->manager = $this->makeUser(Role::MANAGER);
    }

    private function scanNow(MapGrid $grid): TestResponse
    {
        return $this->actingAs($this->manager, 'sanctum')->postJson("/api/local-grids/{$grid->id}/scan");
    }

    public function test_a_scan_runs_in_rounds_and_finishes_with_its_metrics(): void
    {
        config(['indlela.local_grid.concurrency' => 2]);
        $grid = $this->makeGrid($this->site);

        $this->scanNow($grid)->assertStatus(202)
            ->assertJsonPath('data.grid_id', $grid->id)
            ->assertJsonPath('data.points_total', 9)
            ->assertJsonPath('data.trigger', 'manual')
            ->assertJsonMissingPath('data.cost');

        $scan = MapGridScan::query()->sole();
        $this->assertSame(MapGridScan::DONE, $scan->status);
        $this->assertCount(9, $this->dfs['POST '.self::MAPS_LIVE]);
        $this->assertSame(9, $scan->points()->where('status', 'done')->where('rank', 2)->count());
        $this->assertEquals([2, 2, 100, 100], [$scan->arp, $scan->atrp, $scan->solv, $scan->found_pct]);
        $this->assertNotNull($scan->started_at);
        $this->assertNotNull($scan->finished_at);
        $this->assertSame($this->manager->id, $scan->requested_by);
    }

    public function test_without_a_place_id_or_cid_the_sites_domain_finds_the_business(): void
    {
        $grid = $this->makeGrid($this->site, ['business_place_id' => null, 'business_cid' => null]);
        $this->scanNow($grid)->assertStatus(202);

        $this->assertSame(9, MapGridScan::query()->sole()->points_found);
    }

    public function test_failed_points_are_left_out_and_a_scan_with_none_checked_fails_neutrally(): void
    {
        $grid = $this->makeGrid($this->site);
        $calls = 0;
        $this->dfsRoutes[self::MAPS_LIVE] = function (Request $r) use (&$calls) {
            $calls++;

            return $calls % 3 === 0
                ? Http::response(['status_code' => 50000, 'status_message' => 'Internal Error.'], 500)
                : $this->envelope([$this->serpTask([$this->listing(1, 'Karoo Coffee', 'place-karoo')], 'x', cost: 0.002)]);
        };

        $this->scanNow($grid)->assertStatus(202);
        $scan = MapGridScan::query()->sole();
        $this->assertSame(MapGridScan::DONE, $scan->status);
        $this->assertSame([9, 6], [$scan->points_done, $scan->points_found]);
        $this->assertSame(3, $scan->points()->where('status', MapGridPoint::FAILED)->count());
        $this->assertEquals(100, $scan->found_pct, 'over the points checked');

        $this->dfsRoutes[self::MAPS_LIVE] = fn () => $this->envelope([$this->serpTask([], 'x', 40501)]);
        $this->scanNow($grid)->assertStatus(202);
        $failed = MapGridScan::query()->latest('id')->first();
        $this->assertSame(MapGridScan::FAILED, $failed->status);
        $this->assertSame("None of the grid's points could be checked. Try again later.", $failed->error);
        $this->actingAs($this->manager, 'sanctum')->getJson("/api/local-grid-scans/{$failed->id}")->assertOk()
            ->assertDontSee('DataForSEO')->assertJsonPath('data.points.0.status', 'failed');
    }

    public function test_one_scan_at_a_time_and_ten_manual_scans_a_day(): void
    {
        $grid = $this->makeGrid($this->site);
        $this->makeScan($grid, [], ['status' => MapGridScan::RUNNING]);
        $this->scanNow($grid)->assertStatus(409)->assertExactJson(['message' => 'A scan of this grid is already running.', 'error_type' => 'scan_running']);

        MapGridScan::query()->update(['status' => MapGridScan::DONE, 'trigger' => MapGridScan::SCHEDULED]);
        config(['indlela.local_grid.scan_now_daily_limit' => 2]);
        $this->scanNow($grid)->assertStatus(202);
        $this->scanNow($grid)->assertStatus(202);
        $this->scanNow($grid)->assertStatus(429)->assertExactJson(['message' => "You've used today's 2 grid scans. They reset at midnight.", 'error_type' => 'scan_limit']);
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/local-grid/usage')->assertJsonPath('data.scans_today', ['used' => 2, 'limit' => 2]);

        $this->travelTo('2026-11-21 00:05:00');
        $this->scanNow($grid)->assertStatus(202);

        $grid->forceFill(['is_active' => false])->save();
        $this->scanNow($grid)->assertStatus(409)->assertJsonPath('error_type', 'grid_inactive');
    }

    public function test_a_grid_saved_while_scans_cannot_start_is_kept_and_says_why(): void
    {
        config(['indlela.local_grid.scan_now_daily_limit' => 0]);

        $this->actingAs($this->manager, 'sanctum')->postJson("/api/sites/{$this->site->id}/local-grids", $this->gridBody(['scan_now' => true]))
            ->assertCreated()
            ->assertJsonPath('data.latest_scan', null)
            ->assertJsonPath('scan_error.error_type', 'scan_limit');
        $this->assertSame(1, MapGrid::query()->count());
    }

    public function test_without_credentials_scans_and_searches_are_paused(): void
    {
        config(['services.dataforseo.login' => null]);
        $grid = $this->makeGrid($this->site);
        $paused = ['message' => 'Local grid scans are paused for now. Your grids are safe and scans resume automatically.', 'error_type' => 'local_grid_paused'];

        $this->scanNow($grid)->assertStatus(409)->assertExactJson($paused);
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/sites/{$this->site->id}/local-grids/business-search", ['query' => 'karoo'])
            ->assertStatus(409)->assertExactJson($paused);
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/local-grid/usage')->assertJsonPath('data.paused', true);
        $this->assertSame(0, MapGridScan::query()->count());
        $this->assertSame(0, MapGridSearch::query()->count());
    }

    public function test_a_scan_starts_only_when_all_its_points_fit_under_the_spend_cap(): void
    {
        // 0.02 cap at 0.002 a point = 10 points; a rank check already spent 0.004 → 8 left, a 3 x 3 needs 9.
        config(['indlela.rank_tracking.monthly_spend_cap_usd' => 0.02]);
        $keyword = $this->makeKeyword($this->site);
        $this->addCheck($keyword, '2026-11-19', 3, ['cost' => 0.004]);
        $grid = $this->makeGrid($this->site);

        $this->scanNow($grid)->assertStatus(409)->assertJsonPath('error_type', 'local_grid_paused');
        $this->assertSame(0, MapGridScan::query()->count());

        config(['indlela.rank_tracking.monthly_spend_cap_usd' => 0.03]);
        $this->scanNow($grid)->assertStatus(202);
        // Charged: the rank check + 9 points; nothing pending.
        $this->assertEquals(['charged' => 0.022, 'pending' => 0.0], app(RankSpend::class)->breakdown());

        // Searches count too, and an exhausted cap pauses them.
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/sites/{$this->site->id}/local-grids/business-search", ['query' => 'karoo'])->assertOk();
        $this->assertEquals(0.024, app(RankSpend::class)->fresh());
        config(['indlela.rank_tracking.monthly_spend_cap_usd' => 0.025]);
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/sites/{$this->site->id}/local-grids/business-search", ['query' => 'karoo'])
            ->assertStatus(409)->assertJsonPath('error_type', 'local_grid_paused');
    }

    public function test_points_of_scans_in_progress_count_as_pending_spend(): void
    {
        Queue::fake();
        $grid = $this->makeGrid($this->site, ['size' => 5]);

        $this->scanNow($grid)->assertStatus(202);
        Queue::assertPushed(RunMapGridScan::class, 1);
        $this->assertEquals(['charged' => 0.0, 'pending' => 0.05], app(RankSpend::class)->breakdown());

        // Last month's finished points aren't this month's, but a scan still running is pending wherever it began.
        $old = $this->makeScan($grid, array_fill(0, 9, 1), ['scanned_on' => '2026-10-31']);
        $this->assertEquals(['charged' => 0.0, 'pending' => 0.05], app(RankSpend::class)->breakdown());
        $old->points()->limit(2)->update(['status' => 'pending']);
        $old->forceFill(['status' => MapGridScan::RUNNING])->save();
        $this->assertEquals(0.054, app(RankSpend::class)->breakdown()['pending']);
    }

    public function test_a_job_that_dies_finishes_its_scan_with_what_it_has(): void
    {
        Queue::fake();
        $grid = $this->makeGrid($this->site);
        $this->scanNow($grid)->assertStatus(202);
        $scan = MapGridScan::query()->sole();
        $scan->points()->limit(4)->update(['status' => 'done', 'rank' => 1]);

        (new RunMapGridScan($scan->id, $scan->workspace_id))->failed(new RuntimeException('Timed out'));

        $scan->refresh();
        $this->assertSame(MapGridScan::DONE, $scan->status);
        $this->assertSame([9, 4], [$scan->points_done, $scan->points_found]);
        $this->assertSame(5, $scan->points()->where('status', 'failed')->count());
    }
}
