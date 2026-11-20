<?php

namespace Tests\Feature\LocalGrid;

use App\Models\AuditLog;
use App\Models\MapGrid;
use App\Models\MapGridScan;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Testing\TestResponse;
use Tests\Support\AuthTestHelpers;
use Tests\Support\LocalGridHelpers;
use Tests\TestCase;

/** Grids through the API: add, list, show, change, delete, scan and its results, business search, usage. */
class LocalGridApiTest extends TestCase
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
        // Found 1st at the northern row, 4th in the middle row, not at all in the south.
        $this->answerMaps(fn (float $lat) => $lat > -26.10 ? 1 : ($lat > -26.11 ? 4 : null));
        $this->site = $this->rankSite();
        $this->manager = $this->makeUser(Role::MANAGER);
    }

    private function as(): static
    {
        return $this->actingAs($this->manager, 'sanctum');
    }

    private function store(array $state = []): TestResponse
    {
        return $this->as()->postJson("/api/sites/{$this->site->id}/local-grids", $this->gridBody($state));
    }

    public function test_adding_a_grid_and_scanning_it_at_once(): void
    {
        $response = $this->store(['scan_now' => true])->assertCreated()->assertJsonMissingPath('scan_error');
        $grid = MapGrid::query()->sole();

        $response->assertJsonPath('data.id', $grid->id)
            ->assertJsonPath('data.site_id', $this->site->id)
            ->assertJsonPath('data.keyword', 'coffee shop sandton')
            ->assertJsonPath('data.business', ['title' => 'Karoo Coffee', 'place_id' => 'place-karoo', 'cid' => '111', 'address' => '1 Main Road', 'lat' => -26.1076, 'lng' => 28.0567])
            ->assertJsonPath('data.size', 3)
            ->assertJsonPath('data.spacing_km', 1)
            ->assertJsonPath('data.device', 'mobile')
            ->assertJsonPath('data.schedule', 'none')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.next_scan_on', null)
            ->assertJsonPath('data.latest_scan.status', 'done')
            ->assertJsonPath('data.latest_scan.ranks', [[1, 1, 1], [4, 4, 4], [null, null, null]]);

        // 9 live calls, one per point, with the point's coordinate.
        $calls = $this->dfs['POST '.self::MAPS_LIVE];
        $this->assertCount(9, $calls);
        $first = $calls[0][0];
        $this->assertSame('coffee shop sandton', $first['keyword']);
        $this->assertSame('mobile', $first['device']);
        $this->assertSame('android', $first['os']);
        $this->assertSame(20, $first['depth']);
        $this->assertMatchesRegularExpression('/^-26\.\d{1,7},28\.\d{1,7},15z$/', $first['location_coordinate']);

        $scan = MapGridScan::query()->sole();
        $this->assertSame(MapGridScan::DONE, $scan->status);
        $this->assertSame([9, 9, 6], [$scan->points_total, $scan->points_done, $scan->points_found]);
        $this->assertEquals(2.5, $scan->arp);              // (1 x 3 + 4 x 3) / 6
        $this->assertEquals(round((3 + 12 + 63) / 9, 2), $scan->atrp); // not found counts as 21
        $this->assertEquals(33.3, $scan->solv);           // 3 of 9 in the top 3
        $this->assertEquals(66.7, $scan->found_pct);
        $this->assertEquals(0.018, $scan->cost);

        $this->assertSame('CREATE', AuditLog::query()->where('table_name', 'map_grids')->sole()->action_type);
    }

    public function test_the_scan_page_has_points_with_their_top_three_and_the_competitors(): void
    {
        $this->store(['scan_now' => true])->assertCreated();
        $scan = MapGridScan::query()->sole();

        $response = $this->as()->getJson("/api/local-grid-scans/{$scan->id}")->assertOk();
        $response->assertJsonPath('data.id', $scan->id)
            ->assertJsonPath('data.status', 'done')
            ->assertJsonCount(9, 'data.points')
            ->assertJsonPath('data.points.0.row', 0)
            ->assertJsonPath('data.points.0.col', 0)
            ->assertJsonPath('data.points.0.rank', 1)
            ->assertJsonPath('data.points.0.top.0', ['rank' => 1, 'title' => 'Karoo Coffee', 'rating' => 4.5, 'votes' => 10, 'domain' => 'karoo.co.za', 'is_target' => true])
            ->assertJsonPath('data.points.0.top.1.is_target', false)
            ->assertJsonPath('data.points.8.rank', null);
        $this->assertArrayNotHasKey('cost', $response->json('data'));

        // Rival 2 is 2nd everywhere: in the top 3 at all 9 points.
        $competitors = $response->json('competitors');
        $this->assertEquals(['title' => 'Rival 2', 'cid' => '902', 'place_id' => 'place-rival-2', 'rating' => 4.5, 'votes' => 20,
            'top3_points' => 9, 'found_points' => 9, 'avg_rank' => 2], $competitors[0]);
        $this->assertNotContains('Karoo Coffee', array_column($competitors, 'title'));
        $this->assertLessThanOrEqual(10, count($competitors));
    }

    public function test_validation_duplicates_and_the_grid_limit(): void
    {
        $this->as()->postJson("/api/sites/{$this->site->id}/local-grids", ['keyword' => 'x', 'size' => 4, 'spacing_km' => 1.5, 'device' => 'tablet', 'schedule' => 'daily'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['keyword', 'business', 'center_lat', 'center_lng', 'device', 'schedule'])
            ->assertJsonValidationErrors(['size' => 'Choose a 3 x 3, 5 x 5 or 7 x 7 grid.', 'spacing_km' => 'Choose a spacing of 0.5, 1, 2, 3 or 5 km.']);

        $this->store()->assertCreated();
        $this->store(['keyword' => 'COFFEE SHOP SANDTON'])->assertUnprocessable()
            ->assertJsonValidationErrors(['keyword' => 'This site already has a grid for this keyword, business and centre.']);
        // Another centre, or another business, is another grid.
        $this->store(['center_lat' => -26.2])->assertCreated();
        $this->store(['business' => ['title' => 'Other', 'place_id' => 'place-other', 'lat' => -26.1, 'lng' => 28.0]])->assertCreated();
        $this->assertSame(3, MapGrid::query()->count());
        $this->assertSame(0, MapGridScan::query()->count(), 'no scan unless asked');

        config(['indlela.local_grid.max_grids_per_workspace' => 3]);
        $this->store(['keyword' => 'flat white'])->assertUnprocessable()
            ->assertJsonValidationErrors(['keyword' => 'This workspace can have up to 3 active local grids. Pause or delete one to add another.']);

        // A paused grid frees its place; resuming it at the limit is refused.
        $grid = MapGrid::query()->orderBy('id')->first();
        $this->as()->putJson("/api/local-grids/{$grid->id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->store(['keyword' => 'flat white'])->assertCreated();
        $this->as()->putJson("/api/local-grids/{$grid->id}", ['is_active' => true])->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active' => 'This workspace can have up to 3 active local grids. Pause or delete one to add another.']);
        $this->as()->getJson('/api/local-grid/usage')->assertOk()->assertJsonPath('data.grids', ['used' => 3, 'limit' => 3]);
    }

    public function test_changing_a_grid_keeps_its_business_and_deleting_keeps_its_scans(): void
    {
        $grid = MapGrid::query()->find($this->store()->json('data.id'));

        $this->as()->putJson("/api/local-grids/{$grid->id}", [
            'keyword' => 'espresso bar', 'center_lat' => -26.2, 'center_lng' => 28.1, 'size' => 7, 'spacing_km' => 0.5,
            'device' => 'desktop', 'schedule' => 'weekly', 'business' => ['title' => 'Hijack', 'place_id' => 'other'],
        ])->assertOk()
            ->assertJsonPath('data.keyword', 'espresso bar')
            ->assertJsonPath('data.size', 7)
            ->assertJsonPath('data.spacing_km', 0.5)
            ->assertJsonPath('data.device', 'desktop')
            ->assertJsonPath('data.schedule', 'weekly')
            ->assertJsonPath('data.next_scan_on', '2026-11-20')  // never scanned: due now
            ->assertJsonPath('data.business.place_id', 'place-karoo');

        $update = AuditLog::query()->where('table_name', 'map_grids')->where('action_type', 'UPDATE')->sole();
        $this->assertEqualsCanonicalizing(['keyword', 'center_lat', 'center_lng', 'size', 'spacing_km', 'device', 'schedule'], array_keys($update->field_changes));

        $this->makeScan($grid->fresh(), [1, 2, 3, 4, 5, 6, 7, 8, 9]);
        $this->as()->deleteJson("/api/local-grids/{$grid->id}")->assertNoContent();
        $this->assertSoftDeleted($grid);
        $this->assertSame(1, MapGridScan::query()->count());
        $this->as()->getJson("/api/local-grids/{$grid->id}")->assertNotFound();
        $this->as()->getJson('/api/local-grid-scans/'.MapGridScan::query()->value('id'))->assertNotFound();
    }

    public function test_lists_the_sites_grids_and_one_grid_with_its_scans(): void
    {
        $grid = $this->makeGrid($this->site, ['schedule' => 'monthly']);
        $this->makeScan($grid, [1, 2, 3, null, 5, 6, 7, 8, 9], ['scanned_on' => '2026-10-31']);
        $latest = $this->makeScan($grid, [1, 1, 1, 1, 1, 1, 1, 1, null], ['scanned_on' => '2026-11-15', 'arp' => 1, 'found_pct' => 88.9]);
        $this->makeScan($grid, [], ['status' => 'running', 'points_total' => 9, 'points_done' => 0]);
        $empty = $this->rankSite(null, 'https://empty.co.za');

        $sites = collect($this->as()->getJson('/api/local-grid/sites')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(1, $sites[$this->site->id]['grids_count']);
        $this->assertSame($latest->id, $sites[$this->site->id]['latest_scan']['id'], 'the latest finished scan');
        $this->assertSame([[1, 1, 1], [1, 1, 1], [1, 1, null]], $sites[$this->site->id]['latest_scan']['ranks']);
        $this->assertSame('karoo.co.za', $sites[$this->site->id]['domain']);
        $this->assertSame(['id' => $this->site->client_id, 'name' => $this->site->client->name], $sites[$this->site->id]['client']);
        $this->assertSame(0, $sites[$empty->id]['grids_count']);
        $this->assertNull($sites[$empty->id]['latest_scan']);

        $this->as()->getJson("/api/sites/{$this->site->id}/local-grids")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.latest_scan.status', 'running')  // any status: a running scan shows
            ->assertJsonPath('data.0.next_scan_on', '2026-12-15');      // a month after the last finished scan

        $this->as()->getJson("/api/local-grids/{$grid->id}")->assertOk()
            ->assertJsonPath('data.latest_scan.ranks', [[null, null, null], [null, null, null], [null, null, null]])
            ->assertJsonCount(3, 'data.scans')
            ->assertJsonPath('data.scans.1.id', $latest->id)
            ->assertJsonPath('data.scans.1.arp', 1)
            ->assertJsonPath('data.scans.1.found_pct', 88.9)
            ->assertJsonMissingPath('data.scans.1.ranks')
            ->assertJsonMissingPath('data.scans.1.cost');
    }

    public function test_business_search_returns_candidates_and_counts_toward_the_day(): void
    {
        $response = $this->as()->postJson("/api/sites/{$this->site->id}/local-grids/business-search", ['query' => 'karoo coffee'])->assertOk();
        $response->assertJsonCount(5, 'data')->assertJsonPath('data.0', [
            'title' => 'Karoo Coffee', 'address' => '1 Main Road, Sandton', 'place_id' => 'place-karoo', 'cid' => '111',
            'lat' => -26.1, 'lng' => 28.05, 'rating' => 4.5, 'votes' => 10, 'category' => 'Marketing agency', 'domain' => 'karoo.co.za',
        ]);
        $task = $this->dfs['POST '.self::MAPS_LIVE][0][0];
        $this->assertSame(2710, $task['location_code']);
        $this->assertSame('karoo coffee', $task['keyword']);

        $this->as()->postJson("/api/sites/{$this->site->id}/local-grids/business-search", ['query' => 'karoo', 'lat' => -26.1, 'lng' => 28.05])->assertOk();
        $this->assertSame('-26.1,28.05,13z', $this->dfs['POST '.self::MAPS_LIVE][1][0]['location_coordinate']);
        $this->as()->postJson("/api/sites/{$this->site->id}/local-grids/business-search", ['query' => 'k', 'lat' => -26.1])
            ->assertUnprocessable()->assertJsonValidationErrors(['query', 'lng']);

        config(['indlela.local_grid.business_search_daily_limit' => 2]);
        $this->as()->postJson("/api/sites/{$this->site->id}/local-grids/business-search", ['query' => 'karoo'])
            ->assertStatus(429)->assertExactJson(['message' => "You've used today's 2 business searches. They reset at midnight.", 'error_type' => 'search_limit']);
        $this->as()->getJson('/api/local-grid/usage')->assertOk()->assertExactJson(['data' => [
            'grids' => ['used' => 0, 'limit' => 10], 'scans_today' => ['used' => 0, 'limit' => 10],
            'searches_today' => ['used' => 2, 'limit' => 2], 'paused' => false,
        ]]);
    }

    public function test_a_failed_business_search_says_nothing_about_the_provider(): void
    {
        $this->dfsRoutes[self::MAPS_LIVE] = fn (Request $r) => $this->envelope([$this->serpTask([], 'x', 40501)]);

        $this->as()->postJson("/api/sites/{$this->site->id}/local-grids/business-search", ['query' => 'karoo coffee'])
            ->assertStatus(502)->assertExactJson(['message' => "The business search couldn't be completed. Try again shortly.", 'error_type' => 'search_failed']);
        $this->as()->getJson('/api/local-grid/usage')->assertJsonPath('data.searches_today.used', 1);
    }
}
