<?php

namespace Tests\Feature\RankTracking;

use App\Jobs\RefreshRankLocations;
use App\Models\RankKeyword;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AuthTestHelpers;
use Tests\Support\RankTrackingHelpers;
use Tests\TestCase;

/** Summary, sites list, cannibalisation, usage and the location search. */
class RankReportsTest extends TestCase
{
    use AuthTestHelpers, RankTrackingHelpers, RefreshDatabase;

    private Site $site;

    private User $manager;

    /** @var array<string, RankKeyword> */
    private array $k = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->travelTo('2026-11-20 10:00:00');
        $this->site = $this->rankSite();
        $this->manager = $this->makeUser(Role::MANAGER);
    }

    /** Five checked active keywords, one never checked, one inactive (see each line). */
    private function scenario(): void
    {
        $make = fn (string $name, array $state = []) => $this->k[$name] = $this->makeKeyword($this->site, $name, $state);

        $this->history($make('k1', ['tags' => ['Zulu']]), [7 => 10]);                   // 10 → 2: +8
        $this->addCheck($this->k['k1'], '2026-11-20', 2, ['serp_features' => ['featured_snippet' => ['present' => true, 'ours' => true]]]);
        $this->history($make('k2'), [7 => 3]);                        // 3 → 15: −12
        $this->addCheck($this->k['k2'], '2026-11-20', 15, ['serp_features' => ['ai_overview' => ['present' => true, 'ours' => false]]]);
        $this->history($make('k3'), [7 => 50, 0 => null]);            // 50 → out: −51
        $this->history($make('k4'), [7 => null, 0 => 30]);            // out → 30: +71
        $this->addCheck($make('k5'), '2026-11-20', 1, [               // new: no change
            'serp_features' => ['local_pack' => ['present' => true, 'ours' => true]],
            'matching_urls' => [['url' => 'https://www.karoo.co.za/a', 'position' => 1], ['url' => 'https://www.karoo.co.za/b', 'position' => 6]],
        ]);
        $make('k6', ['frequency' => 'weekly']);                       // never checked
        $this->history($make('k7', ['is_active' => false, 'location_name' => 'Johannesburg, Gauteng, South Africa', 'location_code' => 1003802, 'tags' => ['local', 'brand']]), [0 => 1]); // inactive: left out of the figures
        $make('k8', ['tags' => ['Zulu', 'brand'], 'location_name' => 'Pretoria'])->delete(); // deleted: left out everywhere
    }

    public function test_the_summary_maths(): void
    {
        $this->scenario();

        $data = $this->actingAs($this->manager, 'sanctum')->getJson("/api/sites/{$this->site->id}/rank-tracking/summary")->assertOk()->json('data');

        $this->assertSame(6, $data['keywords_count']);
        $this->assertEquals(12.0, $data['avg_position']); // (2 + 15 + 30 + 1) / 4
        $this->assertSame(['top3' => 2, 'top10' => 0, 'top20' => 1, 'top100' => 1, 'not_ranking' => 1], $data['distribution']);
        $this->assertSame(['top3' => 1, 'top10' => 1, 'top20' => 0, 'top100' => 1, 'not_ranking' => 1], $data['distribution_7d_ago']);
        // (0.155 + 0.008 + 0 + 0 + 0.28) / (5 × 0.28) = 31.6 %
        $this->assertEquals(31.6, $data['visibility']);

        $this->assertSame([
            ['keyword_id' => $this->k['k4']->id, 'keyword' => 'k4', 'location_name' => 'South Africa', 'device' => 'desktop', 'from' => null, 'to' => 30, 'change' => 71],
            ['keyword_id' => $this->k['k1']->id, 'keyword' => 'k1', 'location_name' => 'South Africa', 'device' => 'desktop', 'from' => 10, 'to' => 2, 'change' => 8],
        ], $data['gains']);
        $this->assertSame([['k3', 50, null, -51], ['k2', 3, 15, -12]], array_map(fn ($d) => [$d['keyword'], $d['from'], $d['to'], $d['change']], $data['drops']));

        $this->assertSame([
            'featured_snippet' => ['present' => 1, 'ours' => 1],
            'local_pack' => ['present' => 1, 'ours' => 1],
            'ai_overview' => ['present' => 1, 'ours' => 0],
        ], $data['serp_features']);
        $this->assertSame(1, $data['cannibalised_count']);
        // Filter choices: every keyword's incl. inactive (not deleted), distinct, sorted ignoring case.
        $this->assertSame(['Johannesburg, Gauteng, South Africa', 'South Africa'], $data['locations']);
        $this->assertSame(['brand', 'local', 'Zulu'], $data['tags']);

        $series = collect($data['visibility_series'])->keyBy('date');
        $this->assertCount(90, $series);
        $this->assertSame('2026-08-23', $data['visibility_series'][0]['date']);
        $this->assertSame(['date' => '2026-08-23', 'visibility' => null, 'avg_position' => null], $data['visibility_series'][0]);
        // 13 Nov: k1 10, k2 3, k3 50, k4 out → (0.023 + 0.11) / (4 × 0.28) = 11.9 %, average of 10, 3, 50.
        $this->assertEquals(['date' => '2026-11-13', 'visibility' => 11.9, 'avg_position' => 21.0], $series['2026-11-13']);
        $this->assertEquals($series['2026-11-13']['visibility'], $series['2026-11-19']['visibility']); // carried forward
        $this->assertEquals(['date' => '2026-11-20', 'visibility' => 31.6, 'avg_position' => 12.0], $series['2026-11-20']);
    }

    public function test_an_empty_site_summary(): void
    {
        $this->actingAs($this->manager, 'sanctum')->getJson("/api/sites/{$this->site->id}/rank-tracking/summary")->assertOk()
            ->assertJsonPath('data.keywords_count', 0)->assertJsonPath('data.avg_position', null)->assertJsonPath('data.visibility', 0)
            ->assertJsonPath('data.gains', [])->assertJsonPath('data.cannibalised_count', 0)
            ->assertJsonPath('data.locations', [])->assertJsonPath('data.tags', [])->assertJsonCount(90, 'data.visibility_series');
    }

    public function test_the_sites_list_figures(): void
    {
        $this->scenario();
        $empty = $this->rankSite($this->site->client, 'https://another.co.za');

        $sites = collect($this->actingAs($this->manager, 'sanctum')->getJson('/api/rank-tracking/sites')->assertOk()->json('data'))->keyBy('id');

        $this->assertEquals([
            'id' => $this->site->id, 'name' => 'karoo.co.za', 'url' => 'https://www.karoo.co.za',
            'client' => ['id' => $this->site->client_id, 'name' => $this->site->client->name],
            'keywords_count' => 6, 'avg_position' => 12, 'top3' => 2, 'top10' => 2, 'top100' => 4,
            'change_7d_avg' => 4, // (8 − 12 − 51 + 71) / 4
            'last_checked_on' => '2026-11-20',
        ], $sites[$this->site->id]);
        $this->assertSame(0, $sites[$empty->id]['keywords_count']);
        $this->assertNull($sites[$empty->id]['avg_position']);
        $this->assertNull($sites[$empty->id]['last_checked_on']);
        $this->assertSame(0, $sites[$empty->id]['keywords_count']);
        $this->assertArrayNotHasKey('monthly_checks', $sites[$this->site->id]);
        $this->assertArrayNotHasKey('monthly_cost_estimate', $sites[$this->site->id]);
    }

    public function test_cannibalisation_lists_keywords_with_two_or_more_site_urls(): void
    {
        $this->scenario();
        $three = $this->makeKeyword($this->site, 'three urls', ['device' => 'mobile']);
        $this->addCheck($three, '2026-11-20', 4, ['matching_urls' => [
            ['url' => 'https://www.karoo.co.za/x', 'position' => 4], ['url' => 'https://www.karoo.co.za/y', 'position' => 12], ['url' => 'https://karoo.co.za/z', 'position' => 40],
        ]]);
        // Cannibalised last week only: not now.
        $old = $this->makeKeyword($this->site, 'was cannibalised');
        $this->addCheck($old, '2026-11-13', 5, ['matching_urls' => [['url' => 'a', 'position' => 5], ['url' => 'b', 'position' => 7]]]);
        $this->addCheck($old, '2026-11-20', 5);

        $this->actingAs($this->manager, 'sanctum')->getJson("/api/sites/{$this->site->id}/rank-tracking/cannibalisation")->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0', ['keyword_id' => $three->id, 'keyword' => 'three urls', 'location_name' => 'South Africa', 'device' => 'mobile', 'urls' => [
                ['url' => 'https://www.karoo.co.za/x', 'position' => 4], ['url' => 'https://www.karoo.co.za/y', 'position' => 12], ['url' => 'https://karoo.co.za/z', 'position' => 40],
            ]])
            ->assertJsonPath('data.1.keyword_id', $this->k['k5']->id);
    }

    public function test_usage_shows_counts_limits_and_paused_never_money(): void
    {
        $this->scenario();
        $this->addCheck($this->makeKeyword($this->site, 'last month'), '2026-10-31', 3, ['cost' => 0.5]);
        $this->makeKeyword($this->site, 'deleted')->delete();
        $this->configureDataForSeo();
        $as = $this->actingAs($this->manager, 'sanctum');

        // k1–k6 and "last month" active; k7 inactive and "deleted" don't count.
        $as->getJson('/api/rank-tracking/usage')->assertOk()->assertExactJson(['data' => [
            'keywords_tracked' => 7, 'keyword_limit' => 500, 'checks_now_today' => 0, 'check_now_limit' => 20, 'paused' => false,
        ]]);
        $this->assertArrayNotHasKey('GET /v3/appendix/user_data', $this->dfs); // no balance call

        config(['services.dataforseo.login' => null]);
        $as->getJson('/api/rank-tracking/usage')->assertOk()->assertJsonPath('data.paused', true);
    }

    public function test_the_location_search(): void
    {
        $as = $this->actingAs($this->manager, 'sanctum');
        $as->getJson('/api/rank-tracking/locations?search=jo')->assertStatus(409)->assertJsonPath('error_type', 'rank_checks_paused');

        $this->configureDataForSeo();
        $this->dfsRoutes['/v3/serp/google/locations'] = fn () => $this->envelope([['id' => 'l', 'status_code' => 20000, 'result' => [
            ['location_code' => 2710, 'location_name' => 'South Africa', 'location_code_parent' => null, 'country_iso_code' => 'ZA', 'location_type' => 'Country'],
            ['location_code' => 20844, 'location_name' => 'Gauteng,South Africa', 'location_code_parent' => 2710, 'country_iso_code' => 'ZA', 'location_type' => 'Region'],
            ['location_code' => 1003802, 'location_name' => 'Johannesburg,Gauteng,South Africa', 'location_code_parent' => 20844, 'country_iso_code' => 'ZA', 'location_type' => 'City'],
            ['location_code' => 1003806, 'location_name' => 'Sandton,Gauteng,South Africa', 'location_code_parent' => 20844, 'country_iso_code' => 'ZA', 'location_type' => 'City'],
            ['location_code' => 1014044, 'location_name' => 'Johannesburg,California,United States', 'location_code_parent' => 21137, 'country_iso_code' => 'US', 'location_type' => 'City'],
            ['location_code' => 2840, 'location_name' => 'United States', 'location_code_parent' => null, 'country_iso_code' => 'US', 'location_type' => 'Country'],
        ]]]);

        // First use: the search never downloads in the request; it queues the
        // download (sync in tests) and asks the user to try again.
        $as->getJson('/api/rank-tracking/locations?search=johannesburg')->assertStatus(409)
            ->assertJsonPath('error_type', 'locations_loading')
            ->assertJsonPath('message', 'Locations are still loading. Try again in a few minutes.');
        $this->assertCount(1, $this->dfs['GET /v3/serp/google/locations']);

        $as->getJson('/api/rank-tracking/locations?search=johannesburg')->assertOk()->assertExactJson(['data' => [
            ['code' => 1003802, 'name' => 'Johannesburg, Gauteng, South Africa', 'type' => 'City', 'country_iso' => 'ZA'],
            ['code' => 1014044, 'name' => 'Johannesburg, California, United States', 'type' => 'City', 'country_iso' => 'US'],
        ]]);
        $this->assertSame(['Gauteng, South Africa', 'Sandton, Gauteng, South Africa', 'Johannesburg, Gauteng, South Africa'],
            $as->getJson('/api/rank-tracking/locations?search=GAUTENG')->json('data.*.name'));
        $this->assertSame(['South Africa', 'Gauteng, South Africa'], $as->getJson('/api/rank-tracking/locations')->json('data.*.name'));
        $this->assertSame([], $as->getJson('/api/rank-tracking/locations?search=100%25')->json('data'));
        $this->assertCount(1, $this->dfs['GET /v3/serp/google/locations']); // downloaded once

        // Stale: the current list still answers, and a refresh is queued.
        Queue::fake();
        $this->travel(31)->days();
        $as->getJson('/api/rank-tracking/locations?search=sandton')->assertOk()->assertJsonCount(1, 'data');
        Queue::assertPushed(RefreshRankLocations::class);
        $this->assertCount(1, $this->dfs['GET /v3/serp/google/locations']);
    }

    public function test_the_weekly_location_refresh_only_downloads_when_stale(): void
    {
        $this->configureDataForSeo();
        $this->dfsRoutes['/v3/serp/google/locations'] = fn () => $this->envelope([['id' => 'l', 'status_code' => 20000, 'result' => [
            ['location_code' => 2710, 'location_name' => 'South Africa', 'location_code_parent' => null, 'country_iso_code' => 'ZA', 'location_type' => 'Country'],
        ]]]);

        $this->artisan('rank-tracking:locations --if-stale')->expectsOutput('Stored 1 location(s).')->assertSuccessful();
        $this->artisan('rank-tracking:locations --if-stale')->expectsOutput('The location list is up to date.')->assertSuccessful();
        $this->travel(31)->days();
        $this->artisan('rank-tracking:locations --if-stale')->expectsOutput('Stored 1 location(s).')->assertSuccessful();
        $this->assertCount(2, $this->dfs['GET /v3/serp/google/locations']);
    }
}
