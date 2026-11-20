<?php

namespace Tests\Feature\RankTracking;

use App\Jobs\PostRankTasks;
use App\Models\RankCheck;
use App\Models\RankKeyword;
use App\Services\RankTracking\RankChecker;
use App\Services\RankTracking\SerpApi;
use App\Support\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\AuthTestHelpers;
use Tests\Support\RankTrackingHelpers;
use Tests\TestCase;

/** rank-tracking:post-checks: who is due, the task_post payloads, batching, idempotency. */
class RankSchedulingTest extends TestCase
{
    use AuthTestHelpers, RankTrackingHelpers, RefreshDatabase;

    private const POST = 'POST /v3/serp/google/organic/task_post';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        // Friday 20 November 2026, 01:00 SAST.
        $this->travelTo('2026-11-20 01:00:00');
        $this->configureDataForSeo();
        $this->acceptPosts();
    }

    public function test_due_keywords_get_one_pending_check_each_and_are_posted(): void
    {
        $site = $this->rankSite();
        $other = $this->makeWorkspace('Other agency');
        $otherSite = $this->rankSite($this->makeClient($other), 'https://other.co.za');

        $daily = $this->makeKeyword($site, 'daily kw');
        $weeklyToday = $this->makeKeyword($site, 'weekly friday', ['frequency' => 'weekly', 'weekly_day' => 5]);
        $weeklyNew = $this->makeKeyword($site, 'weekly never checked', ['frequency' => 'weekly', 'weekly_day' => 2]);
        $otherWs = $this->makeKeyword($otherSite, 'other workspace');
        $notDue = [
            $this->makeKeyword($site, 'weekly tuesday', ['frequency' => 'weekly', 'weekly_day' => 2]),
            $this->makeKeyword($site, 'inactive', ['is_active' => false]),
            $this->makeKeyword($this->rankSite(url: 'https://off.co.za', state: ['is_active' => false]), 'inactive site'),
        ];
        $this->addCheck($notDue[0], '2026-11-17', 4);
        $deleted = $this->makeKeyword($site, 'deleted');
        $deleted->delete();
        $alreadyToday = $this->makeKeyword($site, 'checked today (live)');
        $this->addCheck($alreadyToday, '2026-11-20', 3, ['mode' => RankCheck::LIVE]);

        $this->artisan('rank-tracking:post-checks')->expectsOutputToContain('Queued 4 rank check(s) in 2 batch(es)')->assertSuccessful();

        $today = RankCheck::query()->withoutGlobalScopes()->where('checked_on', '2026-11-20')->where('mode', RankCheck::STANDARD)->get();
        $this->assertEqualsCanonicalizing([$daily->id, $weeklyToday->id, $weeklyNew->id, $otherWs->id], $today->pluck('keyword_id')->all());
        $this->assertSame([RankCheck::PENDING], $today->pluck('status')->unique()->values()->all());
        foreach ($today as $check) {
            $this->assertSame('task-'.$check->id, $check->task_id);
            $this->assertNotNull($check->posted_at);
            $this->assertEqualsWithDelta(0.0006, $check->cost, 1e-9);
        }
        $this->assertSame($other->id, $today->firstWhere('keyword_id', $otherWs->id)->workspace_id);
        // One call per workspace batch.
        $this->assertCount(2, $this->dfs[self::POST]);

        // Again the same day: nothing new, nothing posted twice.
        $this->artisan('rank-tracking:post-checks')->expectsOutputToContain('Queued 0 rank check(s) in 0 batch(es)')->assertSuccessful();
        $this->assertCount(2, $this->dfs[self::POST]);
        $this->assertSame(5, RankCheck::query()->withoutGlobalScopes()->where('checked_on', '2026-11-20')->count());
    }

    public function test_the_task_payload_for_a_location_code_and_a_coordinate(): void
    {
        $site = $this->rankSite();
        $code = $this->makeKeyword($site, 'coffee beans', ['device' => 'desktop', 'location_code' => 1003802, 'language_code' => 'en']);
        $coord = $this->makeKeyword($site, 'coffee shop near me', ['device' => 'mobile', 'location_type' => 'coordinate', 'location_coordinate' => '-26.1076,28.0567,20000', 'location_name' => 'Sandton']);

        $this->artisan('rank-tracking:post-checks')->assertSuccessful();

        $tasks = collect($this->dfs[self::POST][0])->keyBy('keyword');
        $codeCheck = RankCheck::query()->withoutGlobalScopes()->where('keyword_id', $code->id)->first();
        $this->assertSame([
            'keyword' => 'coffee beans', 'language_code' => 'en', 'device' => 'desktop', 'os' => 'windows', 'depth' => 100,
            'tag' => (string) $codeCheck->id, 'location_code' => 1003802, 'priority' => 1,
        ], $tasks['coffee beans']);
        $this->assertSame('mobile', $tasks['coffee shop near me']['device']);
        $this->assertSame('android', $tasks['coffee shop near me']['os']);
        $this->assertSame('-26.1076,28.0567,20000', $tasks['coffee shop near me']['location_coordinate']);
        $this->assertArrayNotHasKey('location_code', $tasks['coffee shop near me']);
    }

    public function test_batches_hold_at_most_100_tasks_and_none_is_dropped(): void
    {
        $site = $this->rankSite();
        foreach (range(1, 230) as $i) {
            $this->makeKeyword($site, "keyword {$i}");
        }

        // A unique lock left by some other batch must not swallow these.
        Cache::lock('laravel_unique_job:'.PostRankTasks::class.':rank-post-'.sha1('1,2,3'), 3600)->get();

        $this->artisan('rank-tracking:post-checks')->expectsOutputToContain('Queued 230 rank check(s) in 3 batch(es)')->assertSuccessful();

        $this->assertSame([100, 100, 30], array_map('count', $this->dfs[self::POST]));
        $this->assertSame(230, RankCheck::query()->withoutGlobalScopes()->whereNotNull('task_id')->count());
        $tags = collect($this->dfs[self::POST])->flatten(1)->pluck('tag');
        $this->assertSame(230, $tags->unique()->count());
    }

    public function test_jobs_are_unique_per_batch_not_per_workspace(): void
    {
        Queue::fake();
        $site = $this->rankSite();
        foreach (range(1, 150) as $i) {
            $this->makeKeyword($site, "keyword {$i}");
        }

        $this->artisan('rank-tracking:post-checks')->assertSuccessful();

        Queue::assertPushed(PostRankTasks::class, 2);
        $jobs = Queue::pushed(PostRankTasks::class);
        $this->assertNotSame($jobs[0]->uniqueId(), $jobs[1]->uniqueId());
        $this->assertSame([100, 50], $jobs->map(fn (PostRankTasks $j) => count($j->checkIds))->all());
        $this->assertSame($site->workspace_id, $jobs[0]->workspaceId);
    }

    public function test_tasks_dataforseo_refuses_fail_and_the_rest_are_posted(): void
    {
        $site = $this->rankSite();
        $ok = $this->makeKeyword($site, 'good');
        $bad = $this->makeKeyword($site, 'bad');
        $missing = $this->makeKeyword($site, 'missing');
        $this->dfsRoutes['/v3/serp/google/organic/task_post'] = function ($r) {
            $tasks = collect($r->data())->keyBy('keyword');

            return $this->envelope([
                ['id' => 'task-ok', 'status_code' => 20100, 'status_message' => 'Task Created.', 'cost' => 0.0006, 'data' => $tasks['good']],
                ['id' => null, 'status_code' => 40501, 'status_message' => 'Invalid Field: location_code.', 'cost' => 0, 'data' => $tasks['bad']],
            ]);
        };

        $this->artisan('rank-tracking:post-checks')->assertSuccessful();

        $checks = RankCheck::query()->withoutGlobalScopes()->get()->keyBy('keyword_id');
        $this->assertSame(RankCheck::PENDING, $checks[$ok->id]->status);
        $this->assertSame('task-ok', $checks[$ok->id]->task_id);
        $this->assertSame(RankCheck::FAILED, $checks[$bad->id]->status);
        $this->assertStringContainsString('Invalid Field: location_code. (40501)', $checks[$bad->id]->error);
        $this->assertSame(RankCheck::FAILED, $checks[$missing->id]->status);
        $this->assertSame('DataForSEO did not accept the check.', $checks[$missing->id]->error);
    }

    public function test_a_failed_post_releases_its_claim_for_the_retry_and_fails_the_checks_at_the_end(): void
    {
        Queue::fake();
        $site = $this->rankSite();
        $this->makeKeyword($site, 'a');
        $this->makeKeyword($site, 'b');
        $this->artisan('rank-tracking:post-checks')->assertSuccessful();
        $job = Queue::pushed(PostRankTasks::class)->first();

        $this->dfsRoutes['/v3/serp/google/organic/task_post'] = fn () => Http::response(['status_code' => 50000, 'status_message' => 'Internal Error.'], 500);

        try {
            app()->call([$job, 'handle']);
            $this->fail('The job should throw to be retried.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Internal Error.', $e->getMessage());
        }
        // 1 + 2 retries inside the client.
        $this->assertCount(3, $this->dfs[self::POST]);
        $checks = RankCheck::query()->withoutGlobalScopes()->get();
        $this->assertSame([null], $checks->pluck('posted_at')->unique()->values()->all());
        $this->assertSame([RankCheck::PENDING], $checks->pluck('status')->unique()->values()->all());

        // The retry claims them again and posts once.
        $this->acceptPosts();
        app()->call([$job, 'handle']);
        $this->assertCount(4, $this->dfs[self::POST]);
        $this->assertSame(2, RankCheck::query()->withoutGlobalScopes()->whereNotNull('task_id')->count());

        // A duplicate run of the same batch posts nothing.
        app()->call([$job, 'handle']);
        $this->assertCount(4, $this->dfs[self::POST]);

        // Out of retries: whatever is still unposted fails.
        RankCheck::query()->withoutGlobalScopes()->update(['task_id' => null, 'posted_at' => null]);
        $job->failed(new RuntimeException('DataForSEO is down.'));
        $this->assertSame([RankCheck::FAILED], RankCheck::query()->withoutGlobalScopes()->pluck('status')->unique()->values()->all());
        $this->assertSame('DataForSEO is down.', RankCheck::query()->withoutGlobalScopes()->value('error'));
    }

    public function test_a_refusal_that_retrying_cannot_fix_fails_the_batch_at_once(): void
    {
        $site = $this->rankSite();
        $this->makeKeyword($site, 'a');
        $this->dfsRoutes['/v3/serp/google/organic/task_post'] = fn () => $this->envelope([], 40210);

        $this->artisan('rank-tracking:post-checks')->assertSuccessful();

        $check = RankCheck::query()->withoutGlobalScopes()->first();
        $this->assertSame(RankCheck::FAILED, $check->status);
        $this->assertStringContainsString('40210', $check->error);
        $this->assertCount(1, $this->dfs[self::POST]);
    }

    public function test_without_credentials_nothing_is_created_or_posted(): void
    {
        config(['services.dataforseo.login' => null]);
        $this->makeKeyword($this->rankSite());

        $this->artisan('rank-tracking:post-checks')->expectsOutputToContain('not configured')->assertSuccessful();

        $this->assertSame(0, RankCheck::query()->withoutGlobalScopes()->count());
        $this->assertSame([], $this->dfs);
        $this->assertFalse(app(SerpApi::class)->configured());
    }

    public function test_a_live_check_taken_meanwhile_is_not_posted_again(): void
    {
        Queue::fake();
        $site = $this->rankSite();
        $keyword = $this->makeKeyword($site);
        app(CurrentWorkspace::class)->using($site->workspace, fn () => app(RankChecker::class)->scheduleDue(today()));
        Queue::assertPushed(PostRankTasks::class, 1);

        // The day's row became a live check before the batch ran.
        RankCheck::query()->withoutGlobalScopes()->update(['mode' => RankCheck::LIVE, 'posted_at' => now()]);
        app()->call([Queue::pushed(PostRankTasks::class)->first(), 'handle']);

        $this->assertArrayNotHasKey(self::POST, $this->dfs);
        $this->assertSame(RankKeyword::DAILY, $keyword->frequency);
    }
}
