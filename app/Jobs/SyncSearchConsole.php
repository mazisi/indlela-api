<?php

namespace App\Jobs;

use App\Exceptions\SearchConsoleException;
use App\Models\Site;
use App\Models\Workspace;
use App\Services\SearchConsole\SearchConsoleSync;
use App\Support\CurrentWorkspace;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Sync one site's Search Console data (backfill or the last few days).
 *
 * Carries its workspace and runs inside it: the site is looked up through
 * the workspace scope, so a mismatched pair does nothing. At most one queued
 * per site (unique until processing) and one running per site (overlaps wait
 * their turn), so a re-link during a sync queues the next one.
 *
 * Google errors retry with backoff, up to 3 failures; a missing or revoked
 * Google connection fails at once (retrying can't help). Runs on the
 * redis-long connection (Horizon's supervisor-long).
 */
class SyncSearchConsole implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    // Below the redis-long connection's retry_after (1900 s).
    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $siteId, public readonly int $workspaceId)
    {
        $this->onConnection(config('indlela.search_console.queue_connection'));
        $this->onQueue('long');
    }

    public static function forSite(Site $site): self
    {
        return new self($site->id, $site->workspace_id);
    }

    public function uniqueId(): string
    {
        return (string) $this->siteId;
    }

    /** Waiting behind another sync of the same site counts as an attempt, hence a deadline instead of $tries. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(3);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("gsc-sync:{$this->siteId}"))->releaseAfter(120)->expireAfter($this->timeout + 60)];
    }

    public function handle(SearchConsoleSync $sync, CurrentWorkspace $current): void
    {
        $workspace = Workspace::find($this->workspaceId);
        if (! $workspace) {
            return;
        }

        $current->using($workspace, function () use ($sync) {
            $site = Site::find($this->siteId);
            if (! $site || ! $site->hasSearchConsole()) {
                return;
            }

            try {
                $sync->sync($site);
            } catch (SearchConsoleException $e) {
                $sync->markFailed($site, $e->getMessage());
            } catch (Throwable $e) {
                // Shown until the retry starts (status back to syncing).
                $sync->markFailed($site, $e->getMessage());

                throw $e;
            }
        });
    }
}
