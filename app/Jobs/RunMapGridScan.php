<?php

namespace App\Jobs;

use App\Models\MapGridScan;
use App\Models\Workspace;
use App\Services\LocalGrid\GridScanner;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One round of a local grid scan (up to `concurrency` points at once, live
 * Maps SERPs), then the next round as a new job, so no job runs longer than
 * one live call. The last round finishes the scan with its metrics.
 *
 * Not retried (each call is billed). Unique per scan until it starts
 * processing, so a round can queue the next one.
 */
class RunMapGridScan implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Below the redis connection's retry_after (90 s); the live calls time out first.
    public int $timeout = 85;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $scanId, public readonly int $workspaceId)
    {
        $this->onConnection(config('indlela.local_grid.queue_connection'));
    }

    public function uniqueId(): string
    {
        return "map-grid-scan-{$this->scanId}";
    }

    public function handle(GridScanner $scanner, CurrentWorkspace $current): void
    {
        $workspace = Workspace::find($this->workspaceId);
        if (! $workspace) {
            return;
        }

        $more = $current->using($workspace, function () use ($scanner) {
            $scan = MapGridScan::query()->find($this->scanId);

            return $scan !== null && $scanner->runRound($scan);
        });

        if ($more) {
            self::dispatch($this->scanId, $this->workspaceId);
        }
    }

    public function failed(?Throwable $e): void
    {
        $scan = MapGridScan::query()->withoutGlobalScope('workspace')->find($this->scanId);
        if ($scan?->inProgress()) {
            app(GridScanner::class)->abandon($scan, mb_substr($e?->getMessage() ?: 'The scan stopped unexpectedly.', 0, 500));
        }
    }
}
