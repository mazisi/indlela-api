<?php

namespace Tests\Feature\LocalGrid;

use App\Models\MapGridScan;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthTestHelpers;
use Tests\Support\LocalGridHelpers;
use Tests\TestCase;

/** local-grid:run-scheduled: what is due, what is skipped, stale scans. */
class LocalGridSchedulingTest extends TestCase
{
    use AuthTestHelpers, LocalGridHelpers, RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->travelTo('2026-11-20 03:30:00');
        $this->configureDataForSeo();
        $this->answerMaps(fn () => 3);
        $this->site = $this->rankSite();
    }

    public function test_weekly_and_monthly_grids_are_scanned_when_due(): void
    {
        $never = $this->makeGrid($this->site, ['keyword' => 'never scanned', 'schedule' => 'weekly']);
        $weeklyDue = $this->makeGrid($this->site, ['keyword' => 'weekly due', 'schedule' => 'weekly']);
        $this->makeScan($weeklyDue, [1], ['scanned_on' => '2026-11-13']);
        $weeklyNotDue = $this->makeGrid($this->site, ['keyword' => 'weekly not due', 'schedule' => 'weekly']);
        $this->makeScan($weeklyNotDue, [1], ['scanned_on' => '2026-11-14']);
        $monthlyDue = $this->makeGrid($this->site, ['keyword' => 'monthly due', 'schedule' => 'monthly']);
        $this->makeScan($monthlyDue, [1], ['scanned_on' => '2026-10-20']);
        $monthlyNotDue = $this->makeGrid($this->site, ['keyword' => 'monthly not due', 'schedule' => 'monthly']);
        $this->makeScan($monthlyNotDue, [1], ['scanned_on' => '2026-10-21']);
        // A failed scan doesn't count as the last one: the grid is still due.
        $this->makeScan($monthlyNotDue, [], ['scanned_on' => '2026-11-19', 'status' => MapGridScan::FAILED]);
        $manual = $this->makeGrid($this->site, ['keyword' => 'manual only']);
        $paused = $this->makeGrid($this->site, ['keyword' => 'paused', 'schedule' => 'weekly', 'is_active' => false]);
        $inactiveSite = $this->makeGrid($this->rankSite(null, 'https://off.co.za', ['is_active' => false]), ['schedule' => 'weekly']);
        $running = $this->makeGrid($this->site, ['keyword' => 'running', 'schedule' => 'weekly']);
        $this->makeScan($running, [], ['status' => MapGridScan::RUNNING, 'scanned_on' => '2026-11-19', 'created_at' => now()->subHour()]);

        $this->artisan('local-grid:run-scheduled')->expectsOutput('Queued 3 scheduled grid scan(s); skipped 0; 0 stale scan(s) failed.')->assertSuccessful();

        $scheduled = MapGridScan::query()->where('trigger', MapGridScan::SCHEDULED)->get();
        $this->assertEqualsCanonicalizing([$never->id, $weeklyDue->id, $monthlyDue->id], $scheduled->pluck('grid_id')->all());
        $this->assertTrue($scheduled->every(fn (MapGridScan $s) => $s->status === MapGridScan::DONE && $s->workspace_id === $this->workspace()->id));
        $this->assertSame(0, MapGridScan::query()->whereIn('grid_id', [$manual->id, $paused->id, $inactiveSite->id])->count());

        // Scheduled scans don't use the workspace's manual allowance; a second run queues nothing.
        $this->actingAs($this->makeUser(), 'sanctum')->getJson('/api/local-grid/usage')->assertJsonPath('data.scans_today.used', 0);
        $this->artisan('local-grid:run-scheduled')->expectsOutput('Queued 0 scheduled grid scan(s); skipped 0; 0 stale scan(s) failed.');
    }

    public function test_scans_that_do_not_fit_are_skipped_and_stale_ones_fail(): void
    {
        config(['indlela.rank_tracking.monthly_spend_cap_usd' => 0.02]); // 10 points: one 3 x 3 grid
        $first = $this->makeGrid($this->site, ['keyword' => 'first', 'schedule' => 'weekly']);
        $second = $this->makeGrid($this->site, ['keyword' => 'second', 'schedule' => 'weekly']);
        $stuck = $this->makeGrid($this->site, ['keyword' => 'stuck']);
        $stale = $this->makeScan($stuck, [1, null], ['status' => MapGridScan::RUNNING, 'points_total' => 3, 'scanned_on' => '2026-10-31', 'created_at' => now()->subHours(4)]);
        $stale->points()->create(['row' => 2, 'col' => 2, 'lat' => -26.1, 'lng' => 28.0, 'status' => 'pending']);

        $this->artisan('local-grid:run-scheduled')->expectsOutput('Queued 1 scheduled grid scan(s); skipped 1; 1 stale scan(s) failed.');

        $this->assertSame(1, MapGridScan::query()->where('grid_id', $first->id)->count());
        $this->assertSame(0, MapGridScan::query()->where('grid_id', $second->id)->count());
        $stale->refresh();
        $this->assertSame(MapGridScan::DONE, $stale->status);
        $this->assertSame([3, 1], [$stale->points_done, $stale->points_found]);

        // Next month the cap has room again (for one of them).
        $this->travelTo('2026-12-01 03:30:00');
        $first->forceFill(['is_active' => false])->save();
        $this->artisan('local-grid:run-scheduled')->expectsOutput('Queued 1 scheduled grid scan(s); skipped 0; 0 stale scan(s) failed.');
        $this->assertSame(1, MapGridScan::query()->where('grid_id', $second->id)->count());
    }

    public function test_without_credentials_nothing_is_queued(): void
    {
        config(['services.dataforseo.login' => null]);
        $this->makeGrid($this->site, ['schedule' => 'weekly']);

        $this->artisan('local-grid:run-scheduled')->expectsOutput('Queued 0 scheduled grid scan(s); skipped 1; 0 stale scan(s) failed.');
        $this->assertSame(0, MapGridScan::query()->count());
    }
}
