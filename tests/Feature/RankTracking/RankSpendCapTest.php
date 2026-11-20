<?php

namespace Tests\Feature\RankTracking;

use App\Jobs\PostRankTasks;
use App\Models\RankCheck;
use App\Models\Role;
use App\Models\Site;
use App\Services\RankTracking\RankSpend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\AuthTestHelpers;
use Tests\Support\RankTrackingHelpers;
use Tests\TestCase;

/**
 * The platform's monthly spend cap: summed across every workspace, posting
 * stops at it (what fits is posted, the rest skipped), check-now pauses,
 * and 0 means no cap.
 */
class RankSpendCapTest extends TestCase
{
    use AuthTestHelpers, RankTrackingHelpers, RefreshDatabase;

    private const POST = 'POST /v3/serp/google/organic/task_post';

    private Site $site;

    private Site $rivalSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        // Friday 20 November 2026, 01:00 SAST.
        $this->travelTo('2026-11-20 01:00:00');
        $this->configureDataForSeo();
        $this->acceptPosts();
        $this->dfsRoutes['/v3/serp/google/organic/live/advanced'] = fn () => $this->envelope([$this->serpTask([], 'live', cost: 0.002)]);
        $this->site = $this->rankSite();
        // Another workspace's spend this month (its keyword isn't due: inactive).
        $this->rivalSite = $this->rankSite($this->makeClient($this->makeWorkspace('Rival Agency')), 'https://rival.co.za');
        $rival = $this->makeKeyword($this->rivalSite, 'rival', ['is_active' => false]);
        $this->addCheck($rival, '2026-11-05', 3, ['cost' => 0.01]);
        $this->addCheck($rival, '2026-10-31', 3, ['cost' => 5]); // last month: not counted
    }

    public function test_the_spend_sums_every_workspace_this_month_plus_checks_awaiting_an_answer(): void
    {
        $this->assertEqualsWithDelta(0.01, app(RankSpend::class)->fresh(), 1e-9);

        $keyword = $this->makeKeyword($this->site);
        $this->addCheck($keyword, '2026-11-19', null, ['status' => RankCheck::PENDING, 'mode' => RankCheck::LIVE, 'cost' => 0.0006]);
        $this->addCheck($keyword, '2026-11-18', null, ['status' => RankCheck::PENDING, 'task_id' => null]);  // claimed, not accepted yet
        $this->addCheck($keyword, '2026-11-17', null, ['status' => RankCheck::PENDING, 'task_id' => 't', 'cost' => 0.0006]); // accepted: its cost is in
        $this->addCheck($keyword, '2026-11-16', null, ['status' => RankCheck::PENDING, 'posted_at' => null, 'cost' => 0]); // not posted

        // 0.01 + 0.0006 + 0.002 (live awaiting) + 0.0006 (pending, carries 0.0006 cost already) + 0.0006 (expected) + 0.0006
        $this->assertEqualsWithDelta(0.01 + 0.0006 + 0.002 + 0.0006 + 0.0006 + 0.0006, app(RankSpend::class)->fresh(), 1e-9);
    }

    public function test_posting_stops_mid_batch_at_the_cap_and_warns_once(): void
    {
        // 0.01 spent elsewhere: 3 checks at 0.0006 fit.
        config(['indlela.rank_tracking.monthly_spend_cap_usd' => 0.0118, 'indlela.rank_tracking.batch_size' => 2]);
        foreach (range(1, 5) as $i) {
            $this->makeKeyword($this->site, "keyword {$i}");
        }
        Log::spy();

        $this->artisan('rank-tracking:post-checks')->expectsOutputToContain('Queued 5 rank check(s) in 3 batch(es)')->assertSuccessful();

        $this->assertSame([2, 1], array_map('count', $this->dfs[self::POST]));
        $checks = RankCheck::query()->where('site_id', $this->site->id)->orderBy('id')->get();
        $this->assertSame(['pending', 'pending', 'pending', 'failed', 'failed'], $checks->pluck('status')->all());
        $this->assertSame(3, $checks->whereNotNull('task_id')->count());
        $this->assertSame([PostRankTasks::SKIPPED, PostRankTasks::SKIPPED], $checks->where('status', 'failed')->pluck('error')->values()->all());
        $this->assertEqualsWithDelta(0.0118, app(RankSpend::class)->fresh(), 1e-9);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context = []) => str_contains($message, 'spend cap')
            && $context['cap_usd'] === 0.0118 && $context['skipped'] === 1 && abs($context['spend_usd'] - 0.0118) < 1e-9);
    }

    public function test_nothing_is_created_when_the_cap_is_already_reached(): void
    {
        config(['indlela.rank_tracking.monthly_spend_cap_usd' => 0.01]);
        $this->makeKeyword($this->site);
        Log::spy();

        $this->artisan('rank-tracking:post-checks')->expectsOutputToContain('spend cap')->assertSuccessful();

        $this->assertSame(0, RankCheck::query()->where('site_id', $this->site->id)->count());
        $this->assertArrayNotHasKey(self::POST, $this->dfs);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context = []) => str_contains($message, 'spend cap') && $context['cap_usd'] === 0.01);
    }

    public function test_check_now_is_paused_at_the_cap(): void
    {
        $keyword = $this->makeKeyword($this->site);
        $as = $this->actingAs($this->makeUser(Role::MANAGER), 'sanctum');

        // 0.0115 left 0.0015: not enough for a live check (0.002), but not reached either.
        config(['indlela.rank_tracking.monthly_spend_cap_usd' => 0.0115]);
        $as->postJson("/api/rank-keywords/{$keyword->id}/check")->assertStatus(409)->assertJsonPath('error_type', 'rank_checks_paused');
        $as->getJson('/api/rank-tracking/usage')->assertJsonPath('data.paused', false);

        config(['indlela.rank_tracking.monthly_spend_cap_usd' => 0.01]);
        app(RankSpend::class)->forget();
        $as->postJson("/api/rank-keywords/{$keyword->id}/check")->assertStatus(409)->assertJsonPath('error_type', 'rank_checks_paused');
        $as->getJson('/api/rank-tracking/usage')->assertJsonPath('data.paused', true)->assertJsonPath('data.checks_now_today', 0);
        $this->assertArrayNotHasKey('POST /v3/serp/google/organic/live/advanced', $this->dfs);
        $this->assertSame(0, RankCheck::query()->where('keyword_id', $keyword->id)->count());

        config(['indlela.rank_tracking.monthly_spend_cap_usd' => 0.0121]);
        $as->postJson("/api/rank-keywords/{$keyword->id}/check")->assertStatus(202);
    }

    public function test_a_cap_of_zero_means_no_cap(): void
    {
        config(['indlela.rank_tracking.monthly_spend_cap_usd' => 0]);
        RankCheck::query()->withoutGlobalScopes()->update(['cost' => 9_999]);
        $keywords = collect(range(1, 3))->map(fn ($i) => $this->makeKeyword($this->site, "keyword {$i}"));
        $as = $this->actingAs($this->makeUser(Role::MANAGER), 'sanctum');

        $as->getJson('/api/rank-tracking/usage')->assertJsonPath('data.paused', false);
        $this->artisan('rank-tracking:post-checks')->expectsOutputToContain('Queued 3 rank check(s)')->assertSuccessful();
        $this->assertSame(3, RankCheck::query()->where('site_id', $this->site->id)->whereNotNull('task_id')->count());
        $as->postJson("/api/rank-keywords/{$keywords[0]->id}/check")->assertStatus(202);
    }
}
