<?php

namespace Tests\Feature\RankTracking;

use App\Models\RankCheck;
use App\Models\RankKeyword;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthTestHelpers;
use Tests\Support\RankTrackingHelpers;
use Tests\TestCase;

/** Adding, listing, changing and removing a site's keywords. */
class RankKeywordApiTest extends TestCase
{
    use AuthTestHelpers, RankTrackingHelpers, RefreshDatabase;

    private Site $site;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        // Friday.
        $this->travelTo('2026-11-20 10:00:00');
        $this->site = $this->rankSite();
        $this->manager = $this->makeUser(Role::MANAGER);
    }

    private function store(array $body)
    {
        return $this->actingAs($this->manager, 'sanctum')->postJson("/api/sites/{$this->site->id}/rank-keywords", [
            'locations' => [['type' => 'code', 'code' => 2710, 'name' => 'South Africa']],
            'devices' => ['desktop'],
            'frequency' => 'daily',
            ...$body,
        ]);
    }

    public function test_every_combination_is_created_once(): void
    {
        $this->store([
            'keywords' => ['  Coffee   beans ', 'coffee BEANS', 'espresso machine', ''],
            'locations' => [
                ['type' => 'code', 'code' => 2710, 'name' => 'South Africa'],
                ['type' => 'coordinate', 'coordinate' => '-26.10760001, 28.0567', 'name' => 'Sandton, Gauteng, South Africa'],
            ],
            'devices' => ['desktop', 'mobile'],
            'frequency' => 'weekly',
            'tags' => ['brand', ' Brand ', 'coffee'],
        ])->assertCreated()->assertExactJson(['created' => 8, 'skipped' => 0]);

        $keywords = RankKeyword::query()->get();
        $this->assertCount(8, $keywords);
        $this->assertEqualsCanonicalizing(['Coffee beans', 'espresso machine'], $keywords->pluck('keyword')->unique()->values()->all());
        $sandton = $keywords->firstWhere('location_type', 'coordinate');
        $this->assertSame('-26.1076,28.0567,20000', $sandton->location_coordinate);
        $this->assertNull($sandton->location_code);
        $this->assertSame('Sandton, Gauteng, South Africa', $sandton->location_name);
        $this->assertSame(5, $sandton->weekly_day); // the day it was added
        $this->assertSame(['brand', 'coffee'], $sandton->tags);
        $this->assertSame('en', $sandton->language_code);
        $this->assertSame($this->manager->id, $sandton->created_by);
        $this->assertSame($this->site->workspace_id, $sandton->workspace_id);

        // Existing combinations are skipped, the rest added.
        $this->store(['keywords' => ['COFFEE BEANS', 'grinder'], 'devices' => ['desktop', 'mobile']])
            ->assertCreated()->assertExactJson(['created' => 2, 'skipped' => 2]);
        $this->assertSame(10, RankKeyword::query()->count());
    }

    public function test_validation_and_the_workspace_limit(): void
    {
        $this->store(['keywords' => ['  ', '']])->assertUnprocessable()->assertJsonValidationErrors('keywords');
        $this->store(['keywords' => array_map(fn ($i) => "kw {$i}", range(1, 201))])->assertUnprocessable()->assertJsonValidationErrors('keywords');
        // 201 typed, but duplicates collapse to 200: fine.
        $this->assertSame(0, RankKeyword::query()->count());
        $this->store(['keywords' => ['x'], 'locations' => array_fill(0, 6, ['type' => 'code', 'code' => 2710, 'name' => 'SA'])])->assertJsonValidationErrors('locations');
        $this->store(['keywords' => ['x'], 'locations' => [['type' => 'coordinate', 'coordinate' => 'Sandton', 'name' => 'Sandton']]])->assertJsonValidationErrors('locations.0.coordinate');
        $this->store(['keywords' => ['x'], 'locations' => [['type' => 'coordinate', 'coordinate' => '-126.1,28.05', 'name' => 'Nowhere']]])->assertJsonValidationErrors('locations.0.coordinate');
        $this->store(['keywords' => ['x'], 'locations' => [['type' => 'code', 'name' => 'SA']]])->assertJsonValidationErrors('locations.0.code');
        $this->store(['keywords' => ['x'], 'devices' => ['tablet']])->assertJsonValidationErrors('devices.0');
        $this->store(['keywords' => ['x'], 'devices' => ['mobile', 'mobile']])->assertJsonValidationErrors('devices.0');
        $this->store(['keywords' => ['x'], 'frequency' => 'hourly'])->assertJsonValidationErrors('frequency');
        $this->assertSame(0, RankKeyword::query()->count());

        $this->store(['keywords' => array_merge(['kw 1'], array_map(fn ($i) => "KW {$i}", range(1, 200)))])->assertCreated()->assertJsonPath('created', 200);

        config(['indlela.rank_tracking.workspace_keyword_limit' => 250]);
        $this->store(['keywords' => array_map(fn ($i) => "new {$i}", range(1, 30)), 'devices' => ['desktop', 'mobile']])
            ->assertUnprocessable()->assertJsonValidationErrors(['keywords' => 'This workspace can track up to 250 keywords. You have 50 left.']);
        $this->assertSame(200, RankKeyword::query()->count());
        $this->store(['keywords' => array_map(fn ($i) => "new {$i}", range(1, 25)), 'devices' => ['desktop', 'mobile']])->assertCreated()->assertJsonPath('created', 50);
    }

    public function test_the_list_rows_carry_positions_changes_and_flags(): void
    {
        $k = $this->makeKeyword($this->site, 'coffee beans', ['tags' => ['brand']]);
        // 31 days ago 40, 8 days ago 12, 2 days ago 8, yesterday 6 (other URL), today 3 with two site URLs.
        $this->history($k, [31 => 40, 8 => 12, 2 => 8, 1 => 6]);
        RankCheck::query()->where('keyword_id', $k->id)->where('checked_on', '2026-11-19')->update(['url' => 'https://www.karoo.co.za/old']);
        $this->addCheck($k, '2026-11-20', 3, [
            'url' => 'https://www.karoo.co.za/beans',
            'matching_urls' => [['url' => 'https://www.karoo.co.za/beans', 'position' => 3], ['url' => 'https://www.karoo.co.za/shop', 'position' => 9]],
            'serp_features' => ['featured_snippet' => ['present' => true, 'ours' => false]],
        ]);

        $row = $this->actingAs($this->manager, 'sanctum')->getJson("/api/sites/{$this->site->id}/rank-keywords")
            ->assertOk()->assertJsonPath('meta.per_page', 15)->json('data.0');

        $this->assertSame([
            'id' => $k->id, 'keyword' => 'coffee beans',
            'location' => ['type' => 'code', 'code' => 2710, 'coordinate' => null, 'name' => 'South Africa'],
            'language_code' => 'en', 'device' => 'desktop', 'frequency' => 'daily', 'weekly_day' => 0, 'tags' => ['brand'], 'is_active' => true, 'is_key' => false,
            'position' => 3, 'previous_position' => 6, 'change_1d' => 3, 'change_7d' => 9, 'change_30d' => 37, 'best_position' => 3,
            'url' => 'https://www.karoo.co.za/beans', 'url_changed' => true, 'cannibalised' => true,
            'serp_features' => ['featured_snippet' => ['present' => true, 'ours' => false]],
            'last_checked_on' => '2026-11-20', 'last_check_status' => 'done',
            'history' => [
                ['date' => '2026-10-20', 'position' => 40], ['date' => '2026-11-12', 'position' => 12], ['date' => '2026-11-18', 'position' => 8],
                ['date' => '2026-11-19', 'position' => 6], ['date' => '2026-11-20', 'position' => 3],
            ],
        ], $row);
    }

    public function test_changes_count_dropping_out_as_101_and_need_something_to_compare(): void
    {
        $dropped = $this->makeKeyword($this->site, 'dropped');
        $this->history($dropped, [8 => 5, 0 => null]);
        $entered = $this->makeKeyword($this->site, 'entered');
        $this->history($entered, [7 => null, 0 => 60]);
        $new = $this->makeKeyword($this->site, 'new');
        $this->history($new, [0 => 4]);
        $never = $this->makeKeyword($this->site, 'never checked');
        $failed = $this->makeKeyword($this->site, 'failed today');
        $this->history($failed, [1 => 7]);
        $this->addCheck($failed, '2026-11-20', null, ['status' => RankCheck::FAILED, 'error' => 'x']);

        $rows = collect($this->actingAs($this->manager, 'sanctum')->getJson("/api/sites/{$this->site->id}/rank-keywords")->json('data'))->keyBy('keyword');

        $this->assertSame([null, 5, -96, -96], [$rows['dropped']['position'], $rows['dropped']['previous_position'], $rows['dropped']['change_1d'], $rows['dropped']['change_7d']]);
        $this->assertNull($rows['dropped']['change_30d']);
        $this->assertFalse($rows['dropped']['url_changed']);
        $this->assertSame(41, $rows['entered']['change_7d']);
        $this->assertNull($rows['new']['change_1d']);
        $this->assertNull($rows['new']['change_7d']);
        $this->assertNull($rows['never checked']['position']);
        $this->assertNull($rows['never checked']['last_checked_on']);
        $this->assertSame([], $rows['never checked']['history']);
        // A failed check keeps showing the last result.
        $this->assertSame(7, $rows['failed today']['position']);
        $this->assertSame('failed', $rows['failed today']['last_check_status']);
        $this->assertSame('2026-11-20', $rows['failed today']['last_checked_on']);
    }

    public function test_filters_buckets_and_sorting(): void
    {
        $rows = [
            'a top3' => [2, 'desktop', 'South Africa', ['brand']],
            'b top10' => [7, 'mobile', 'Johannesburg, Gauteng, South Africa', []],
            'c top100' => [55, 'desktop', 'Sandton', ['brand']],
            'd out' => [null, 'mobile', 'South Africa', []],
        ];
        foreach ($rows as $keyword => [$position, $device, $location, $tags]) {
            $k = $this->makeKeyword($this->site, $keyword, ['device' => $device, 'location_name' => $location, 'location_code' => crc32($location) % 100000, 'tags' => $tags]);
            $this->history($k, [7 => 20, 0 => $position]);
        }
        $this->makeKeyword($this->site, 'e unchecked');
        $as = $this->actingAs($this->manager, 'sanctum');
        $list = fn (string $q) => $as->getJson("/api/sites/{$this->site->id}/rank-keywords?{$q}")->assertOk()->json('data.*.keyword');

        $this->assertSame(['a top3'], $list('bucket=top3'));
        $this->assertSame(['a top3', 'b top10'], $list('bucket=top10'));
        $this->assertSame(['a top3', 'b top10', 'c top100'], $list('bucket=top100'));
        $this->assertSame(['d out'], $list('bucket=not_ranking'));
        $this->assertSame(['b top10', 'd out'], $list('device=mobile'));
        $this->assertSame(['b top10'], $list('location=johannes'));
        $this->assertSame(['a top3', 'c top100'], $list('tag=brand'));
        $this->assertSame(['c top100'], $list('search=TOP100'));
        $this->assertSame(['a top3', 'b top10', 'c top100', 'd out', 'e unchecked'], $list('sort=position'));
        $this->assertSame(['c top100', 'b top10', 'a top3', 'd out', 'e unchecked'], $list('sort=-position'));
        // change_7d: 18, 13, -35, -81; unchecked (null) last either way.
        $this->assertSame(['a top3', 'b top10', 'c top100', 'd out', 'e unchecked'], $list('sort=-change_7d'));
        $this->assertSame(['d out', 'c top100', 'b top10', 'a top3', 'e unchecked'], $list('sort=change_7d'));
        $this->assertSame(['e unchecked', 'd out', 'c top100', 'b top10', 'a top3'], $list('sort=-keyword'));
        $as->getJson("/api/sites/{$this->site->id}/rank-keywords?sort=volume")->assertJsonValidationErrors('sort');
        $as->getJson("/api/sites/{$this->site->id}/rank-keywords?per_page=10&page=1")->assertJsonPath('meta.total', 5);
    }

    public function test_update_delete_and_bulk(): void
    {
        $k = $this->makeKeyword($this->site, 'coffee');
        $this->history($k, [1 => 5]);
        $as = $this->actingAs($this->manager, 'sanctum');

        $as->putJson("/api/rank-keywords/{$k->id}", ['frequency' => 'weekly', 'weekly_day' => 1, 'tags' => ['a', 'a', 'b'], 'is_active' => false])
            ->assertOk()->assertJsonPath('data.frequency', 'weekly')->assertJsonPath('data.weekly_day', 1)
            ->assertJsonPath('data.tags', ['a', 'b'])->assertJsonPath('data.is_active', false)->assertJsonPath('data.position', 5);
        $as->putJson("/api/rank-keywords/{$k->id}", ['weekly_day' => 7])->assertJsonValidationErrors('weekly_day');
        $as->putJson("/api/rank-keywords/{$k->id}", ['is_active' => true])->assertOk()->assertJsonPath('data.frequency', 'weekly');

        $as->deleteJson("/api/rank-keywords/{$k->id}")->assertNoContent();
        $this->assertSoftDeleted($k);
        $this->assertSame(1, RankCheck::query()->where('keyword_id', $k->id)->count());
        $as->getJson("/api/rank-keywords/{$k->id}/history")->assertNotFound();
        // The same keyword can be added again.
        $this->store(['keywords' => ['coffee'], 'locations' => [['type' => 'code', 'code' => 2710, 'name' => 'South Africa']]])->assertJsonPath('created', 1);

        $ids = collect(['x', 'y', 'z'])->map(fn ($w) => $this->makeKeyword($this->site, $w)->id)->all();
        $bulk = fn (array $body) => $as->postJson("/api/sites/{$this->site->id}/rank-keywords/bulk", $body);
        $bulk(['action' => 'deactivate', 'ids' => $ids])->assertOk()->assertExactJson(['affected' => 3]);
        $this->assertSame(0, RankKeyword::query()->whereKey($ids)->where('is_active', true)->count());
        $bulk(['action' => 'activate', 'ids' => [$ids[0]]])->assertOk();
        $this->assertTrue(RankKeyword::find($ids[0])->is_active);
        $bulk(['action' => 'set_frequency', 'ids' => $ids])->assertJsonValidationErrors('frequency');
        $bulk(['action' => 'set_frequency', 'ids' => $ids, 'frequency' => 'weekly'])->assertOk();
        $this->assertSame(['weekly'], RankKeyword::query()->whereKey($ids)->pluck('frequency')->unique()->values()->all());
        // A keyword of another site of the workspace isn't this site's.
        $elsewhere = $this->makeKeyword($this->rankSite(url: 'https://else.co.za'), 'else');
        $bulk(['action' => 'delete', 'ids' => [$ids[0], $elsewhere->id]])->assertUnprocessable()->assertJsonValidationErrors('ids');
        $bulk(['action' => 'delete', 'ids' => $ids])->assertOk()->assertExactJson(['affected' => 3]);
        $this->assertSame(0, RankKeyword::query()->whereKey($ids)->count());
        $this->assertNotSoftDeleted($elsewhere);
    }

    public function test_a_keywords_history(): void
    {
        $k = $this->makeKeyword($this->site);
        $this->history($k, [100 => 30, 89 => 20, 3 => null]);
        $this->addCheck($k, '2026-11-19', null, ['status' => RankCheck::FAILED]);
        $this->addCheck($k, '2026-11-20', 4, ['url' => 'https://www.karoo.co.za/a', 'serp_features' => ['ai_overview' => ['present' => true, 'ours' => true]]]);
        $as = $this->actingAs($this->manager, 'sanctum');

        $as->getJson("/api/rank-keywords/{$k->id}/history")->assertOk()->assertExactJson(['data' => [
            ['date' => '2026-08-23', 'position' => 20, 'url' => 'https://www.karoo.co.za/'.$k->id, 'serp_features' => null],
            ['date' => '2026-11-17', 'position' => null, 'url' => null, 'serp_features' => null],
            ['date' => '2026-11-20', 'position' => 4, 'url' => 'https://www.karoo.co.za/a', 'serp_features' => ['ai_overview' => ['present' => true, 'ours' => true]]],
        ]]);
        $as->getJson("/api/rank-keywords/{$k->id}/history?days=7")->assertJsonCount(2, 'data');
        $as->getJson("/api/rank-keywords/{$k->id}/history?days=0")->assertJsonValidationErrors('days');
    }
}
