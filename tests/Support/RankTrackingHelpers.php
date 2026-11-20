<?php

namespace Tests\Support;

use App\Models\Client;
use App\Models\RankCheck;
use App\Models\RankKeyword;
use App\Models\Site;
use App\Models\SiteCompetitor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * A fake DataForSEO for the rank-tracking tests: credentials set, every
 * request recorded in `$this->dfs` ("METHOD path" => list of bodies) and
 * answered by `$this->dfsRoutes` (path prefix => callable(Request): response
 * body array or Http response), else a 404 envelope. The real
 * DataForSeoSerpApi runs.
 */
trait RankTrackingHelpers
{
    /** @var array<string, list<mixed>> */
    protected array $dfs = [];

    /** @var array<string, callable(Request): mixed> */
    protected array $dfsRoutes = [];

    protected function configureDataForSeo(): void
    {
        config(['services.dataforseo.login' => 'agency@example.com', 'services.dataforseo.password' => 'secret', 'services.dataforseo.base_url' => 'https://sandbox.dataforseo.com']);
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $this->dfs[$request->method().' '.$path][] = $request->data();
            foreach ($this->dfsRoutes as $prefix => $handler) {
                if (str_starts_with($path, $prefix)) {
                    $answer = $handler($request);

                    return is_array($answer) ? Http::response($answer) : $answer;
                }
            }

            return Http::response(['status_code' => 40400, 'status_message' => 'Not Found.'], 404);
        });
    }

    /** @param  list<array<string, mixed>>  $tasks */
    protected function envelope(array $tasks, int $code = 20000): array
    {
        return ['version' => '0.1', 'status_code' => $code, 'status_message' => $code === 20000 ? 'Ok.' : 'Error.', 'cost' => 0, 'tasks_count' => count($tasks), 'tasks_error' => 0, 'tasks' => $tasks];
    }

    /** task_post answering every task as created, ids "task-{tag}", cost 0.0006 each. */
    protected function acceptPosts(): void
    {
        $this->dfsRoutes['/v3/serp/google/organic/task_post'] = fn (Request $r) => $this->envelope(array_map(fn (array $t) => [
            'id' => 'task-'.$t['tag'], 'status_code' => 20100, 'status_message' => 'Task Created.', 'cost' => 0.0006, 'data' => $t, 'result' => null,
        ], $r->data()));
    }

    /** A result of one task (task_get / live): the SERP $items. */
    protected function serpTask(array $items, string $id = 'task-1', int $status = 20000, float $cost = 0.0, int $resultsCount = 1250000): array
    {
        return ['id' => $id, 'status_code' => $status, 'status_message' => $status === 20000 ? 'Ok.' : 'Error.', 'cost' => $cost,
            'result' => $status === 20000 ? [['keyword' => 'kw', 'se_results_count' => $resultsCount, 'items_count' => count($items), 'items' => $items]] : null];
    }

    protected function organic(int $rank, string $url): array
    {
        return ['type' => 'organic', 'rank_group' => $rank, 'rank_absolute' => $rank + 2, 'url' => $url, 'domain' => parse_url($url, PHP_URL_HOST), 'title' => 'T'];
    }

    protected function rankSite(?Client $client = null, string $url = 'https://www.karoo.co.za', array $state = []): Site
    {
        $parts = Site::normalise($url);

        return Site::factory()->create(['client_id' => ($client ?? $this->makeClient())->id, 'url' => $parts['url'], 'domain' => $parts['domain'], 'name' => $parts['domain'], ...$state]);
    }

    protected function makeKeyword(Site $site, string $keyword = 'coffee beans', array $state = []): RankKeyword
    {
        $type = $state['location_type'] ?? RankKeyword::CODE;
        $code = $type === RankKeyword::CODE ? ($state['location_code'] ?? 2710) : null;
        $coordinate = $state['location_coordinate'] ?? null;

        return RankKeyword::query()->withoutGlobalScopes()->create([
            'site_id' => $site->id, 'keyword' => $keyword, 'location_type' => $type, 'location_code' => $code,
            'location_coordinate' => $coordinate, 'location_name' => 'South Africa',
            'location_key' => RankKeyword::locationKey($type, $code, $coordinate), 'device' => RankKeyword::DESKTOP,
            'frequency' => RankKeyword::DAILY, 'weekly_day' => 0, 'tags' => [], 'is_active' => true,
            ...$state,
        ]);
    }

    /** A check of $keyword on $date (done by default). */
    protected function addCheck(RankKeyword $keyword, string $date, ?int $position, array $state = []): RankCheck
    {
        return RankCheck::query()->withoutGlobalScopes()->create([
            'workspace_id' => $keyword->workspace_id, 'site_id' => $keyword->site_id, 'keyword_id' => $keyword->id,
            'checked_on' => $date, 'mode' => RankCheck::STANDARD, 'status' => RankCheck::DONE, 'position' => $position,
            'url' => $position ? 'https://www.karoo.co.za/'.$keyword->id : null, 'matching_urls' => $position ? [['url' => 'https://www.karoo.co.za/'.$keyword->id, 'position' => $position]] : [],
            'serp_features' => null, 'cost' => 0.0006, 'posted_at' => now(), 'completed_at' => now(),
            ...$state,
        ]);
    }

    /** A competitor saved directly (no backfill). */
    protected function makeCompetitor(Site $site, string $domain, array $state = []): SiteCompetitor
    {
        return SiteCompetitor::query()->create(['site_id' => $site->id, 'domain' => $domain, 'name' => $domain, 'is_active' => true, ...$state]);
    }

    /**
     * rank_checks.organic_domains from position => host (gaps null).
     *
     * @param  array<int, string>  $hosts
     * @return list<?string>
     */
    protected function hosts(array $hosts): array
    {
        $out = [];
        for ($p = 1; $p <= ($hosts === [] ? 0 : max(array_keys($hosts))); $p++) {
            $out[] = $hosts[$p] ?? null;
        }

        return $out;
    }

    /**
     * Done checks: $positions maps "days ago" => position (null = not ranking).
     *
     * @param  array<int, ?int>  $positions
     */
    protected function history(RankKeyword $keyword, array $positions, array $state = []): void
    {
        foreach ($positions as $daysAgo => $position) {
            $this->addCheck($keyword, today()->subDays($daysAgo)->toDateString(), $position, $state);
        }
    }
}
