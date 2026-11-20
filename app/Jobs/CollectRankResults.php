<?php

namespace App\Jobs;

use App\Models\RankCheck;
use App\Services\RankTracking\SerpApi;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Ask DataForSEO which tasks have finished (tasks_ready) and queue one
 * FetchRankResult per finished task that is one of our pending checks.
 * Spans workspaces (each fetch job carries its check's workspace); tasks
 * that aren't ours (another environment on the same account) are left alone.
 * One at a time: unique until it finishes (5 minutes at most).
 */
class CollectRankResults implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onConnection(config('indlela.rank_tracking.queue_connection'));
    }

    public function uniqueId(): string
    {
        return 'rank-collect';
    }

    public function handle(SerpApi $api): void
    {
        if (! $api->configured()) {
            Log::info('Rank tracking: DataForSEO is not configured, nothing collected.');

            return;
        }

        $ready = collect($api->tasksReady())->pluck('id')->unique()->values();
        if ($ready->isEmpty()) {
            return;
        }

        RankCheck::query()->withoutGlobalScope('workspace')->pending()->where('mode', RankCheck::STANDARD)
            ->whereIn('task_id', $ready->all())
            ->get(['id', 'workspace_id', 'task_id'])
            ->each(fn (RankCheck $check) => FetchRankResult::dispatch($check->id, $check->workspace_id, $check->task_id));
    }
}
