<?php

namespace App\Jobs;

use App\Models\SiteCompetitor;
use App\Models\Workspace;
use App\Services\RankTracking\CompetitorTracker;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A competitor added, restored or resumed: its positions in the site's done
 * checks of the last rank_tracking.competitor_backfill_days days (database
 * only, no SERP is fetched). Safe to run twice (upserts). Unique per
 * competitor while queued.
 */
class BackfillCompetitorPositions implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $competitorId, public readonly int $workspaceId)
    {
        $this->onConnection(config('indlela.rank_tracking.queue_connection'));
    }

    public function uniqueId(): string
    {
        return "competitor-backfill-{$this->competitorId}";
    }

    public function handle(CompetitorTracker $tracker, CurrentWorkspace $current): void
    {
        $workspace = Workspace::find($this->workspaceId);
        if (! $workspace) {
            return;
        }

        $current->using($workspace, function () use ($tracker) {
            // Deleted or paused since: nothing to fill in (it is filled again when it comes back).
            $competitor = SiteCompetitor::query()->active()->find($this->competitorId);
            if ($competitor) {
                $tracker->backfill($competitor);
            }
        });
    }
}
