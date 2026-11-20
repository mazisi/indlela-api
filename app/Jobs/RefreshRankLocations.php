<?php

namespace App\Jobs;

use App\Services\RankTracking\RankLocations;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Download DataForSEO's location list into rank_locations (platform-wide, not
 * per workspace). ~270k places: minutes and ~400 MB, so it runs on the long
 * connection (Horizon's supervisor-long, 512 MB workers raise their limit in
 * RankLocations::refresh) and never inside a request.
 */
class RefreshRankLocations implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [300];

    public int $timeout = 900;

    public int $uniqueFor = 1800;

    public function __construct()
    {
        $this->onConnection(config('indlela.rank_tracking.locations_queue_connection'));
        $this->onQueue('long');
    }

    public function uniqueId(): string
    {
        return 'rank-locations';
    }

    public function handle(RankLocations $locations): void
    {
        $locations->refresh();
    }
}
