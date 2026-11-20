<?php

namespace Tests\Feature\RankTracking;

use App\Models\RankCheck;
use App\Models\RankKeyword;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\AuthTestHelpers;
use Tests\Support\RankTrackingHelpers;
use Tests\TestCase;

/**
 * The beta limits a workspace sees: keywords per workspace (with its own
 * override), the weekly default, "check now" a day, and that nothing a
 * workspace is shown mentions the provider, money or credentials.
 */
class RankLimitsTest extends TestCase
{
    use AuthTestHelpers, RankTrackingHelpers, RefreshDatabase;

    private const LIVE = '/v3/serp/google/organic/live/advanced';

    private Site $site;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->travelTo('2026-11-20 10:00:00');
        $this->configureDataForSeo();
        $this->dfsRoutes[self::LIVE] = fn () => $this->envelope([$this->serpTask([$this->organic(3, 'https://www.karoo.co.za/')], 'live', cost: 0.002)]);
        $this->site = $this->rankSite();
        $this->manager = $this->makeUser(Role::MANAGER);
    }

    private function store(array $body, ?Site $site = null): TestResponse
    {
        return $this->actingAs($this->manager, 'sanctum')->postJson('/api/sites/'.($site ?? $this->site)->id.'/rank-keywords', [
            'locations' => [['type' => 'code', 'code' => 2710, 'name' => 'South Africa']],
            'devices' => ['desktop'],
            ...$body,
        ]);
    }

    private function check(RankKeyword $keyword, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->manager, 'sanctum')->postJson("/api/rank-keywords/{$keyword->id}/check");
    }

    public function test_frequency_defaults_to_weekly_and_daily_stays_allowed(): void
    {
        $this->store(['keywords' => ['coffee beans']])->assertCreated();
        $this->store(['keywords' => ['espresso'], 'frequency' => 'daily'])->assertCreated();

        $this->assertSame(['coffee beans' => 'weekly', 'espresso' => 'daily'], RankKeyword::query()->pluck('frequency', 'keyword')->all());
    }

    public function test_adding_keywords_respects_the_workspace_limit_counting_only_new_combinations(): void
    {
        config(['indlela.rank_tracking.workspace_keyword_limit' => 5]);
        $other = $this->rankSite(null, 'https://other.co.za');
        $this->makeKeyword($other, 'on another site');
        $this->makeKeyword($other, 'paused', ['is_active' => false]);  // inactive: doesn't count
        $this->makeKeyword($other, 'removed')->delete();               // deleted: doesn't count
        // Another workspace's keywords don't count either.
        $rivalSite = $this->rankSite($this->makeClient($this->makeWorkspace('Rival Agency')), 'https://rival.co.za');
        foreach (range(1, 6) as $i) {
            $this->makeKeyword($rivalSite, "rival {$i}");
        }

        // 1 used: 2 keywords x 2 devices = 4 fit exactly.
        $this->store(['keywords' => ['a', 'b'], 'devices' => ['desktop', 'mobile']])->assertCreated()->assertExactJson(['created' => 4, 'skipped' => 0]);

        // At the limit: one new combination is too many; nothing is created.
        $this->store(['keywords' => ['a', 'c']])->assertUnprocessable()
            ->assertJsonValidationErrors(['keywords' => 'This workspace can track up to 5 keywords. You have 0 left.']);
        $this->assertSame(5, RankKeyword::query()->where('is_active', true)->count());

        // Only existing combinations: skipped, nothing new, so no limit hit.
        $this->store(['keywords' => ['A', 'b']])->assertCreated()->assertExactJson(['created' => 0, 'skipped' => 2]);
        $this->getJsonAs('/api/rank-tracking/usage')->assertJsonPath('data.keywords_tracked', 5)->assertJsonPath('data.keyword_limit', 5);
    }

    public function test_a_workspace_override_replaces_the_default_limit(): void
    {
        $this->workspace()->forceFill(['rank_keyword_limit' => 2])->save();

        $this->store(['keywords' => ['a', 'b', 'c']])->assertUnprocessable()
            ->assertJsonValidationErrors(['keywords' => 'This workspace can track up to 2 keywords. You have 2 left.']);
        $this->store(['keywords' => ['a', 'b']])->assertCreated();
        $this->getJsonAs('/api/rank-tracking/usage')->assertJsonPath('data.keyword_limit', 2)->assertJsonPath('data.keywords_tracked', 2);

        $this->workspace()->forceFill(['rank_keyword_limit' => null])->save();
        $this->getJsonAs('/api/rank-tracking/usage')->assertJsonPath('data.keyword_limit', 500);
    }

    public function test_reactivating_respects_the_limit_and_changes_nothing_when_over(): void
    {
        config(['indlela.rank_tracking.workspace_keyword_limit' => 2]);
        $on = $this->makeKeyword($this->site, 'on');
        $off = [$this->makeKeyword($this->site, 'off 1', ['is_active' => false]), $this->makeKeyword($this->site, 'off 2', ['is_active' => false])];
        $as = $this->actingAs($this->manager, 'sanctum');

        // One place left: activating two at once is refused whole.
        $as->postJson("/api/sites/{$this->site->id}/rank-keywords/bulk", ['action' => 'activate', 'ids' => [$on->id, $off[0]->id, $off[1]->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['ids' => 'This workspace can track up to 2 keywords. You have 1 left.']);
        $this->assertFalse($off[0]->fresh()->is_active);
        $this->assertFalse($off[1]->fresh()->is_active);

        // One fits (the already active one takes no new place).
        $as->postJson("/api/sites/{$this->site->id}/rank-keywords/bulk", ['action' => 'activate', 'ids' => [$on->id, $off[0]->id]])->assertOk();
        $as->putJson("/api/rank-keywords/{$off[1]->id}", ['is_active' => true, 'frequency' => 'weekly'])->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active' => 'This workspace can track up to 2 keywords. You have 0 left.']);
        $this->assertFalse($off[1]->fresh()->is_active);
        $this->assertSame(RankKeyword::DAILY, $off[1]->fresh()->frequency); // the rest of the change didn't happen either

        // Other changes to active keywords and deactivating still work at the limit.
        $as->putJson("/api/rank-keywords/{$on->id}", ['is_active' => true, 'tags' => ['x']])->assertOk();
        $as->putJson("/api/rank-keywords/{$on->id}", ['is_active' => false])->assertOk();
        $as->putJson("/api/rank-keywords/{$off[1]->id}", ['is_active' => true])->assertOk();
    }

    public function test_check_now_allows_20_a_day_and_the_21st_is_429(): void
    {
        $keyword = $this->makeKeyword($this->site);

        foreach (range(1, 20) as $i) {
            $this->check($keyword)->assertStatus(202);
        }
        $this->check($keyword)->assertStatus(429)->assertExactJson([
            'message' => "You've used today's 20 instant checks. They reset at midnight.",
            'error_type' => 'check_limit_reached',
        ]);

        $this->assertCount(20, $this->dfs['POST '.self::LIVE]);
        $this->assertSame(20, RankCheck::query()->value('live_requests'));
        $this->getJsonAs('/api/rank-tracking/usage')->assertJsonPath('data.checks_now_today', 20)->assertJsonPath('data.check_now_limit', 20);
    }

    public function test_failed_live_checks_count_and_other_workspaces_are_independent(): void
    {
        config(['indlela.rank_tracking.check_now_daily_limit' => 2]);
        $this->dfsRoutes[self::LIVE] = fn () => Http::response(['status_code' => 50000, 'status_message' => 'Internal Error.'], 500);
        $a = $this->makeKeyword($this->site, 'a');
        $b = $this->makeKeyword($this->site, 'b');

        $this->check($a)->assertStatus(202)->assertJsonPath('data.last_check_status', 'failed');
        $this->check($b)->assertStatus(202)->assertJsonPath('data.last_check_status', 'failed');
        $this->check($a)->assertStatus(429);
        $this->assertSame(RankCheck::FAILED, RankCheck::query()->where('keyword_id', $a->id)->value('status'));

        $rival = $this->makeWorkspace('Rival Agency');
        $rivalKeyword = $this->makeKeyword($this->rankSite($this->makeClient($rival), 'https://rival.co.za'));
        $this->check($rivalKeyword, $this->ownerOf($rival))->assertStatus(202);
        $this->actingAs($this->ownerOf($rival), 'sanctum')->getJson('/api/rank-tracking/usage')->assertJsonPath('data.checks_now_today', 1);
    }

    public function test_the_check_now_limit_resets_at_midnight_in_south_africa(): void
    {
        config(['indlela.rank_tracking.check_now_daily_limit' => 1]);
        $keyword = $this->makeKeyword($this->site);

        $this->travelTo(Carbon::parse('2026-11-20 23:59:00', 'Africa/Johannesburg'));
        $this->check($keyword)->assertStatus(202);
        $this->check($keyword)->assertStatus(429);

        // 00:30 SAST is still the 20th in UTC: SAST's day counts.
        $this->travelTo(Carbon::parse('2026-11-21 00:30:00', 'Africa/Johannesburg'));
        $this->check($keyword)->assertStatus(202);
        $this->check($keyword)->assertStatus(429);
        $this->assertSame([1, 1], RankCheck::query()->orderBy('checked_on')->pluck('live_requests')->all());
    }

    public function test_workspace_facing_responses_never_mention_the_provider_money_or_credentials(): void
    {
        config(['indlela.rank_tracking.workspace_keyword_limit' => 1, 'indlela.rank_tracking.check_now_daily_limit' => 1]);
        $keyword = $this->makeKeyword($this->site);
        $off = $this->makeKeyword($this->site, 'off', ['is_active' => false]);
        $this->addCheck($keyword, '2026-11-19', 4, ['cost' => 0.5]);
        $as = $this->actingAs($this->manager, 'sanctum');

        $responses = [
            'usage' => $as->getJson('/api/rank-tracking/usage')->assertOk(),
            'sites' => $as->getJson('/api/rank-tracking/sites')->assertOk(),
            'store over the limit' => $this->store(['keywords' => ['new']])->assertUnprocessable(),
            'reactivate' => $as->putJson("/api/rank-keywords/{$off->id}", ['is_active' => true])->assertUnprocessable(),
            'bulk activate' => $as->postJson("/api/sites/{$this->site->id}/rank-keywords/bulk", ['action' => 'activate', 'ids' => [$off->id]])->assertUnprocessable(),
            'check now' => $this->check($keyword)->assertStatus(202),
            'daily limit' => $this->check($keyword)->assertStatus(429),
        ];
        RankCheck::query()->where('keyword_id', $keyword->id)->update(['status' => RankCheck::PENDING]);
        $responses['running'] = $this->check($keyword)->assertStatus(409)->assertJsonPath('error_type', 'check_running');

        $this->dfsRoutes['/v3/serp/google/locations'] = fn () => Http::response(['status_code' => 40100, 'status_message' => 'You are not authorized. Check your credentials and balance.'], 401);
        $responses['provider down'] = $as->getJson('/api/rank-tracking/locations?search=jo')->assertStatus(502)
            ->assertExactJson(['message' => "Rank checks couldn't be reached. Try again shortly.", 'error_type' => 'dataforseo_error']);

        config(['services.dataforseo.login' => null]);
        $responses['paused check'] = $this->check($keyword)->assertStatus(409)->assertExactJson([
            'message' => 'Rank checks are paused for now. Your keywords are safe and checks resume automatically.',
            'error_type' => 'rank_checks_paused',
        ]);
        $responses['paused locations'] = $as->getJson('/api/rank-tracking/locations')->assertStatus(409)->assertJsonPath('error_type', 'rank_checks_paused');
        $responses['paused usage'] = $as->getJson('/api/rank-tracking/usage')->assertOk()->assertJsonPath('data.paused', true);

        foreach ($responses as $name => $response) {
            $json = $response->json();
            // error_type keeps its machine-readable value; everything a person reads is checked.
            unset($json['error_type']);
            $text = json_encode($json);
            foreach (['dataforseo', '$', 'balance', 'credential', 'cost', 'sandbox', 'monthly'] as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $text, "{$name} mentions {$word}");
            }
        }
    }

    private function getJsonAs(string $url): TestResponse
    {
        return $this->actingAs($this->manager, 'sanctum')->getJson($url);
    }
}
