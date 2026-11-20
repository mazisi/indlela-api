<?php

namespace Tests\Feature\RankTracking;

use App\Jobs\CheckKeywordNow;
use App\Models\RankCheck;
use App\Models\RankKeyword;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AuthTestHelpers;
use Tests\Support\RankTrackingHelpers;
use Tests\TestCase;

/** rank-tracking:collect (tasks_ready → task_get) and "check now" (Live). */
class RankCollectionTest extends TestCase
{
    use AuthTestHelpers, RankTrackingHelpers, RefreshDatabase;

    private Site $site;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->travelTo('2026-11-20 08:00:00');
        $this->configureDataForSeo();
        $this->site = $this->rankSite();
        $this->manager = $this->makeUser(Role::MANAGER);
    }

    private function pending(RankKeyword $keyword, string $taskId, array $state = []): RankCheck
    {
        return $this->addCheck($keyword, '2026-11-20', null, ['status' => RankCheck::PENDING, 'task_id' => $taskId, 'matching_urls' => null, 'url' => null, 'completed_at' => null, 'posted_at' => now()->subHours(7), ...$state]);
    }

    public function test_finished_tasks_are_fetched_parsed_and_stored(): void
    {
        $ranks = $this->makeKeyword($this->site, 'coffee beans');
        $outside = $this->makeKeyword($this->site, 'tea');
        $queued = $this->makeKeyword($this->site, 'still queued');
        $refused = $this->makeKeyword($this->site, 'refused');
        $empty = $this->makeKeyword($this->site, 'no results');
        $checks = [
            'a' => $this->pending($ranks, 'task-a'),
            'b' => $this->pending($outside, 'task-b'),
            'c' => $this->pending($queued, 'task-c'),
            'd' => $this->pending($refused, 'task-d'),
            'e' => $this->pending($empty, 'task-e'),
        ];
        $notReady = $this->pending($this->makeKeyword($this->site, 'not ready'), 'task-x');

        $this->dfsRoutes['/v3/serp/google/organic/tasks_ready'] = fn () => $this->envelope([['id' => 'r', 'status_code' => 20000, 'result' => [
            ['id' => 'task-a', 'tag' => (string) $checks['a']->id], ['id' => 'task-b'], ['id' => 'task-c'], ['id' => 'task-d'], ['id' => 'task-e'],
            ['id' => 'someone-elses-task', 'tag' => '999999'],
        ]]]);
        $this->dfsRoutes['/v3/serp/google/organic/task_get/advanced/'] = fn ($r) => $this->envelope([match (basename(parse_url($r->url(), PHP_URL_PATH))) {
            'task-a' => $this->serpTask([
                ['type' => 'featured_snippet', 'url' => 'https://www.karoo.co.za/guide', 'domain' => 'www.karoo.co.za'],
                $this->organic(1, 'https://rival.co.za/'),
                $this->organic(3, 'https://www.karoo.co.za/beans'),
                $this->organic(8, 'https://karoo.co.za/shop/beans'),
                ['type' => 'people_also_ask', 'items' => []],
            ], 'task-a'),
            'task-b' => $this->serpTask([$this->organic(1, 'https://rival.co.za/')], 'task-b'),
            'task-c' => ['id' => 'task-c', 'status_code' => 40602, 'status_message' => 'Task In Queue.', 'cost' => 0, 'result' => null],
            'task-d' => ['id' => 'task-d', 'status_code' => 40501, 'status_message' => 'Invalid Field.', 'cost' => 0, 'result' => null],
            'task-e' => ['id' => 'task-e', 'status_code' => 40102, 'status_message' => 'No Search Results.', 'cost' => 0, 'result' => null],
        }]);

        $this->artisan('rank-tracking:collect')->assertSuccessful();

        $a = $checks['a']->fresh();
        $this->assertSame(RankCheck::DONE, $a->status);
        $this->assertSame(3, $a->position);
        $this->assertSame('https://www.karoo.co.za/beans', $a->url);
        $this->assertSame([['url' => 'https://www.karoo.co.za/beans', 'position' => 3], ['url' => 'https://karoo.co.za/shop/beans', 'position' => 8]], $a->matching_urls);
        $this->assertSame(['present' => true, 'ours' => true], $a->serp_features['featured_snippet']);
        $this->assertSame(['present' => true], $a->serp_features['people_also_ask']);
        $this->assertSame(1250000, $a->result_count);
        $this->assertEqualsWithDelta(0.0006, $a->cost, 1e-9); // posting's cost, task_get is free
        $this->assertNotNull($a->completed_at);

        $b = $checks['b']->fresh();
        $this->assertSame(RankCheck::DONE, $b->status);
        $this->assertNull($b->position);
        $this->assertSame([], $b->matching_urls);

        $this->assertSame(RankCheck::PENDING, $checks['c']->fresh()->status);
        $this->assertSame(RankCheck::FAILED, $checks['d']->fresh()->status);
        $this->assertStringContainsString('Invalid Field. (40501)', $checks['d']->fresh()->error);
        $this->assertSame(RankCheck::DONE, $checks['e']->fresh()->status);
        $this->assertNull($checks['e']->fresh()->position);
        $this->assertSame(RankCheck::PENDING, $notReady->fresh()->status);

        // Only our tasks were fetched.
        $fetched = collect(array_keys($this->dfs))->filter(fn ($k) => str_contains($k, 'task_get'))->values();
        $this->assertCount(5, $fetched);
        $this->assertNotContains('GET /v3/serp/google/organic/task_get/advanced/someone-elses-task', $fetched);
    }

    public function test_checks_pending_too_long_fail_and_replaced_ones_are_left_alone(): void
    {
        $old = $this->addCheck($this->makeKeyword($this->site, 'old'), '2026-11-19', null, ['status' => RankCheck::PENDING, 'task_id' => 't-old', 'posted_at' => now()->subHours(31), 'created_at' => now()->subHours(31)]);
        $neverPosted = $this->addCheck($this->makeKeyword($this->site, 'never posted'), '2026-11-19', null, ['status' => RankCheck::PENDING, 'posted_at' => null, 'created_at' => now()->subHours(25)]);
        $recent = $this->pending($this->makeKeyword($this->site, 'recent'), 't-new');
        $live = $this->pending($this->makeKeyword($this->site, 'live'), 't-live', ['mode' => RankCheck::LIVE]);
        $this->dfsRoutes['/v3/serp/google/organic/tasks_ready'] = fn () => $this->envelope([['id' => 'r', 'status_code' => 20000, 'result' => [['id' => 't-live']]]]);

        $this->artisan('rank-tracking:collect')->expectsOutputToContain('2 stale check(s) failed')->assertSuccessful();

        $this->assertSame(RankCheck::FAILED, $old->fresh()->status);
        $this->assertSame('No result from DataForSEO within 24 hours.', $old->fresh()->error);
        $this->assertSame(RankCheck::FAILED, $neverPosted->fresh()->status);
        $this->assertSame(RankCheck::PENDING, $recent->fresh()->status);
        // A live check's id is never collected by the standard collector.
        $this->assertSame(RankCheck::PENDING, $live->fresh()->status);
        $this->assertCount(0, collect(array_keys($this->dfs))->filter(fn ($k) => str_contains($k, 'task_get')));
    }

    public function test_collection_without_credentials_does_nothing(): void
    {
        config(['services.dataforseo.password' => '']);
        $this->pending($this->makeKeyword($this->site), 'task-a');

        $this->artisan('rank-tracking:collect')->expectsOutputToContain('not configured')->assertSuccessful();
        $this->assertSame([], $this->dfs);
    }

    public function test_check_now_runs_a_live_check_and_stores_it_as_todays_result(): void
    {
        $keyword = $this->makeKeyword($this->site, 'coffee beans', ['location_type' => 'coordinate', 'location_coordinate' => '-26.1076,28.0567,20000', 'location_name' => 'Sandton']);
        $this->addCheck($keyword, '2026-11-19', 9);
        // This morning's standard check is still pending: the live one replaces it, its cost stays in the ledger.
        $standard = $this->pending($keyword, 'task-standard', ['cost' => 0.0006]);
        $this->dfsRoutes['/v3/serp/google/organic/live/advanced'] = fn () => $this->envelope([$this->serpTask([$this->organic(4, 'https://www.karoo.co.za/beans')], 'live-1', cost: 0.002)]);

        $this->actingAs($this->manager, 'sanctum')->postJson("/api/rank-keywords/{$keyword->id}/check")
            ->assertStatus(202)
            ->assertJsonPath('data.id', $keyword->id)
            ->assertJsonPath('data.position', 4)
            ->assertJsonPath('data.previous_position', 9)
            ->assertJsonPath('data.change_1d', 5)
            ->assertJsonPath('data.last_check_status', 'done')
            ->assertJsonPath('data.last_checked_on', '2026-11-20');

        $check = $standard->fresh();
        $this->assertSame(RankCheck::LIVE, $check->mode);
        $this->assertSame(RankCheck::DONE, $check->status);
        $this->assertSame('live-1', $check->task_id);
        $this->assertEqualsWithDelta(0.0026, $check->cost, 1e-9);
        $this->assertSame(1, RankCheck::query()->where('keyword_id', $keyword->id)->where('checked_on', '2026-11-20')->count());

        $body = $this->dfs['POST /v3/serp/google/organic/live/advanced'][0][0];
        $this->assertSame('-26.1076,28.0567,20000', $body['location_coordinate']);
        $this->assertArrayNotHasKey('priority', $body);
        $this->assertSame((string) $check->id, $body['tag']);
    }

    public function test_check_now_409s_while_a_live_check_is_pending_and_when_not_configured(): void
    {
        $keyword = $this->makeKeyword($this->site);
        $this->pending($keyword, 'live-x', ['mode' => RankCheck::LIVE]);
        $as = $this->actingAs($this->manager, 'sanctum');

        $as->postJson("/api/rank-keywords/{$keyword->id}/check")->assertStatus(409)->assertJsonPath('error_type', 'check_running');

        config(['services.dataforseo.login' => null]);
        $as->postJson("/api/rank-keywords/{$keyword->id}/check")->assertStatus(409)->assertJsonPath('error_type', 'rank_checks_paused');
        $this->assertSame([], $this->dfs);
    }

    public function test_a_failed_live_check_is_marked_failed(): void
    {
        $refused = $this->makeKeyword($this->site, 'refused');
        $down = $this->makeKeyword($this->site, 'down');
        $timeout = $this->makeKeyword($this->site, 'timeout');
        $this->dfsRoutes['/v3/serp/google/organic/live/advanced'] = fn ($r) => match ($r->data()[0]['keyword']) {
            'refused' => $this->envelope([['id' => 'l', 'status_code' => 40501, 'status_message' => 'Invalid Field.', 'cost' => 0, 'result' => null]]),
            'down' => Http::response(['status_code' => 50000, 'status_message' => 'Internal Error.'], 500),
            'timeout' => Http::failedConnection('cURL error 28: Operation timed out'),
        };
        $as = $this->actingAs($this->manager, 'sanctum');

        foreach ([$refused, $down, $timeout] as $keyword) {
            $as->postJson("/api/rank-keywords/{$keyword->id}/check")->assertStatus(202)
                ->assertJsonPath('data.last_check_status', 'failed')->assertJsonPath('data.position', null);
        }

        $errors = RankCheck::query()->pluck('error', 'keyword_id');
        $this->assertStringContainsString('Invalid Field. (40501)', $errors[$refused->id]);
        $this->assertStringContainsString('Internal Error.', $errors[$down->id]);
        $this->assertStringContainsString('did not answer', $errors[$timeout->id]);
        // 5xx retried; a timed-out live check is never sent twice.
        $keywords = collect($this->dfs['POST /v3/serp/google/organic/live/advanced'])->map(fn ($b) => $b[0]['keyword'])->countBy();
        $this->assertSame(['refused' => 1, 'down' => 3, 'timeout' => 1], $keywords->all());

        // A job that dies (timeout) marks its check failed too.
        $check = $this->pending($this->makeKeyword($this->site, 'killed'), 'x', ['mode' => RankCheck::LIVE]);
        (new CheckKeywordNow($check->id, $check->workspace_id, $check->keyword_id, 1))->failed(null);
        $this->assertSame(RankCheck::FAILED, $check->fresh()->status);
    }

    public function test_check_now_jobs_are_unique_per_click_not_per_keyword(): void
    {
        Queue::fake();
        $keyword = $this->makeKeyword($this->site);
        $as = $this->actingAs($this->manager, 'sanctum');

        $as->postJson("/api/rank-keywords/{$keyword->id}/check")->assertStatus(202)->assertJsonPath('data.last_check_status', 'pending');
        // The first click's check finished (or failed); a second click later the same day.
        RankCheck::query()->update(['status' => RankCheck::FAILED]);
        $this->travel(5)->minutes();
        $as->postJson("/api/rank-keywords/{$keyword->id}/check")->assertStatus(202);

        Queue::assertPushed(CheckKeywordNow::class, 2);
        $ids = Queue::pushed(CheckKeywordNow::class)->map(fn (CheckKeywordNow $j) => $j->uniqueId());
        $this->assertSame(2, $ids->unique()->count());
        $this->assertStringStartsWith("rank-live-{$keyword->id}-", $ids[0]);
    }
}
