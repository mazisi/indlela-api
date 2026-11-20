<?php

namespace App\Jobs;

use App\Exceptions\DataForSeoException;
use App\Models\RankCheck;
use App\Models\Workspace;
use App\Services\RankTracking\RankChecker;
use App\Services\RankTracking\SerpApi;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Fetch one finished task (task_get/advanced) and store it on its check.
 * Still queued at DataForSEO (40601/40602) = left pending for the next
 * collection; no search results (40102) = done, not ranking; any other
 * task error fails the check. Unique per check and
 * task, so the 5-minute collector can't queue it twice.
 */
class FetchRankResult implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout = 60;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $checkId, public readonly int $workspaceId, public readonly string $taskId)
    {
        $this->onConnection(config('indlela.rank_tracking.queue_connection'));
    }

    public function uniqueId(): string
    {
        return "rank-fetch-{$this->checkId}-{$this->taskId}";
    }

    public function handle(SerpApi $api, RankChecker $checker, CurrentWorkspace $current): void
    {
        $workspace = Workspace::find($this->workspaceId);
        if (! $workspace) {
            return;
        }

        $current->using($workspace, function () use ($api, $checker) {
            $check = RankCheck::query()->find($this->checkId);
            // Replaced by a live check, already stored, or gone.
            if (! $check || $check->status !== RankCheck::PENDING || $check->task_id !== $this->taskId) {
                return;
            }

            try {
                $task = $api->taskGet($this->taskId);
            } catch (DataForSeoException $e) {
                if ($e->retryable) {
                    throw $e;
                }
                $checker->fail($check, $e->getMessage());

                return;
            }

            match (true) {
                $task['status_code'] === 20000 => $checker->complete($check, $task['result'], $task['cost']),
                // "No search results": nothing ranks, so the site doesn't either.
                $task['status_code'] === 40102 => $checker->complete($check, null, $task['cost']),
                in_array($task['status_code'], [40601, 40602], true) => null,
                default => $checker->fail($check, "DataForSEO could not check this keyword: {$task['status_message']} ({$task['status_code']}).", $task['cost']),
            };
        });
    }

    public function failed(?Throwable $e): void
    {
        RankCheck::query()->withoutGlobalScope('workspace')->whereKey($this->checkId)->where('task_id', $this->taskId)->pending()
            ->update(['status' => RankCheck::FAILED, 'error' => mb_substr($e?->getMessage() ?: 'Fetching the result failed.', 0, 500), 'completed_at' => now(), 'updated_at' => now()]);
    }
}
