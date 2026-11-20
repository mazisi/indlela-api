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
 * "Check now": one Live-mode SERP for a keyword, stored as today's check.
 *
 * Not retried (each attempt is billed; transient HTTP errors are retried
 * inside the client). Unique per keyword and click (`$requestedAt`), not
 * per keyword alone: a lock left by an earlier click must never swallow a
 * new one. Two live checks at once are refused where they start (409
 * check_running).
 */
class CheckKeywordNow implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Below the redis connection's retry_after (90 s); the live call itself times out first.
    public int $timeout = 85;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $checkId, public readonly int $workspaceId, public readonly int $keywordId, public readonly int $requestedAt)
    {
        $this->onConnection(config('indlela.rank_tracking.queue_connection'));
    }

    public function uniqueId(): string
    {
        return "rank-live-{$this->keywordId}-{$this->requestedAt}";
    }

    public function handle(SerpApi $api, RankChecker $checker, CurrentWorkspace $current): void
    {
        $workspace = Workspace::find($this->workspaceId);
        if (! $workspace) {
            return;
        }

        $current->using($workspace, function () use ($api, $checker) {
            $check = RankCheck::query()->with('keyword')->find($this->checkId);
            if (! $check || ! $check->keyword || $check->status !== RankCheck::PENDING || $check->mode !== RankCheck::LIVE) {
                return;
            }

            try {
                $task = $api->live($checker->task($check, $check->keyword));
            } catch (DataForSeoException $e) {
                $checker->fail($check, $e->getMessage());

                return;
            }

            $check->forceFill(['task_id' => $task['id']]);
            in_array($task['status_code'], [20000, 40102], true) // 40102: no search results, so not ranking
                ? $checker->complete($check, $task['result'], $task['cost'])
                : $checker->fail($check, "DataForSEO could not check this keyword: {$task['status_message']} ({$task['status_code']}).", $task['cost']);
        });
    }

    public function failed(?Throwable $e): void
    {
        RankCheck::query()->withoutGlobalScope('workspace')->whereKey($this->checkId)->where('mode', RankCheck::LIVE)->pending()
            ->update(['status' => RankCheck::FAILED, 'error' => mb_substr($e?->getMessage() ?: 'The live check stopped unexpectedly.', 0, 500), 'completed_at' => now(), 'updated_at' => now()]);
    }
}
