<?php

use App\Exceptions\SiteAuditException;
use App\Jobs\CollectRankResults;
use App\Jobs\SyncSearchConsole;
use App\Models\GoogleConnection;
use App\Models\Site;
use App\Models\SiteAudit;
use App\Models\Workspace;
use App\Services\RankTracking\RankChecker;
use App\Services\RankTracking\RankLocations;
use App\Services\RankTracking\RankSpend;
use App\Services\RankTracking\SerpApi;
use App\Services\Reports\Reports;
use App\Services\SiteAudit\SiteAudits;
use App\Support\CurrentWorkspace;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Creates the s3 disk's bucket when it doesn't exist yet (the local `minio`
// Sail service starts empty). Safe to re-run; on real S3 it only checks.
Artisan::command('storage:bucket', function () {
    $disk = config('filesystems.disks.s3');
    $client = Storage::disk('s3')->getClient();

    if ($client->doesBucketExist($disk['bucket'])) {
        $this->info("Bucket {$disk['bucket']} already exists.");

        return;
    }

    $client->createBucket(['Bucket' => $disk['bucket']]);
    $this->info("Created bucket {$disk['bucket']}.");
})->purpose('Create the S3 bucket (MinIO/RustFS locally) if it is missing');

// Queues a Search Console sync (last few days, or the backfill) for every
// active linked site whose workspace has a working Google connection. No
// workspace is current here: the query spans workspaces on purpose and each
// job carries its site's workspace.
Artisan::command('search-console:sync {--site=* : Only these site ids}', function () {
    $sites = Site::query()->withoutGlobalScope('workspace')
        ->active()
        ->linkedToSearchConsole()
        ->whereIn('workspace_id', GoogleConnection::query()->withoutGlobalScope('workspace')->where('status', GoogleConnection::ACTIVE)->select('workspace_id'))
        ->when($this->option('site'), fn ($q, array $ids) => $q->whereKey($ids))
        ->get(['id', 'workspace_id']);

    foreach ($sites as $site) {
        Site::withoutGlobalScope('workspace')->whereKey($site->id)->update(['gsc_sync_status' => Site::GSC_QUEUED]);
        SyncSearchConsole::dispatch($site->id, $site->workspace_id);
    }

    $this->info("Queued {$sites->count()} Search Console sync(s).");
})->purpose('Queue Search Console syncs for linked sites');

// GSC publishes a day's data 2-3 days late; early morning SA time is quiet for Google's quota too.
Schedule::command('search-console:sync')->dailyAt('05:30')->withoutOverlapping()->onOneServer();

// Queues the scheduled site audits that are due (weekly: 7 days after the
// site's last finished audit, monthly: 30), then prunes old audits' detail
// rows (the latest 10 per site keep theirs). Spans workspaces on purpose;
// each audit is created inside its site's workspace and its job carries it.
Artisan::command('site-audit:run-scheduled', function (SiteAudits $audits, CurrentWorkspace $current) {
    $queued = 0;
    foreach ($audits->dueSites() as $site) {
        $workspace = Workspace::find($site->workspace_id);
        if (! $workspace) {
            continue;
        }
        try {
            $current->using($workspace, fn () => $audits->start($site, null, SiteAudit::SCHEDULED));
            $queued++;
        } catch (SiteAuditException) {
            // Started by someone in the meantime.
        }
    }

    $pruned = $audits->prune();
    $this->info("Queued {$queued} scheduled audit(s); pruned {$pruned} old audit(s).");
})->purpose('Queue due scheduled site audits and prune old audit details');

// Night-time SA: quiet for the clients' servers.
Schedule::command('site-audit:run-scheduled')->dailyAt('02:00')->withoutOverlapping()->onOneServer();

// Rank tracking: a pending check for every active keyword due today (daily,
// weekly on its weekday, or never checked) with no check today, posted to
// DataForSEO's Standard queue in batches of 100 (one job per workspace and
// batch). Spans workspaces; each job carries its own. Safe to re-run: a
// keyword gets one check a day. Stops at the platform's monthly spend cap
// (rank_tracking.monthly_spend_cap_usd): nothing is created once it's
// reached, and each batch posts only what still fits (PostRankTasks).
Artisan::command('rank-tracking:post-checks', function (SerpApi $api, RankChecker $checker, RankSpend $spend) {
    if (! $api->configured()) {
        Log::warning('Rank tracking: DataForSEO is not configured (DATAFORSEO_LOGIN, DATAFORSEO_PASSWORD); no checks posted.');
        $this->warn('DataForSEO is not configured; nothing posted.');

        return;
    }

    if ($spend->checksThatFit((float) config('indlela.rank_tracking.price_standard')) === 0) {
        Log::warning('Rank tracking: the monthly spend cap is reached; no checks posted.', ['spend_usd' => $spend->fresh(), 'cap_usd' => $spend->cap()]);
        Cache::put('rank-spend-cap-warned:'.today()->toDateString(), true, now()->endOfDay());
        $this->warn('The monthly spend cap is reached; nothing posted.');

        return;
    }

    ['checks' => $checks, 'batches' => $batches] = $checker->scheduleDue(today());
    $this->info("Queued {$checks} rank check(s) in {$batches} batch(es).");
})->purpose('Create today\'s rank checks and post them to DataForSEO');

// Overnight SA: results are ready by morning (Standard queue).
Schedule::command('rank-tracking:post-checks')->dailyAt('01:00')->withoutOverlapping()->onOneServer();

// Fails checks pending past the timeout, then queues the collection of
// finished DataForSEO tasks (tasks_ready → one FetchRankResult per task).
Artisan::command('rank-tracking:collect', function (SerpApi $api, RankChecker $checker) {
    $failed = $checker->failStale();
    if (! $api->configured()) {
        $this->warn("DataForSEO is not configured; nothing collected ({$failed} stale check(s) failed).");

        return;
    }

    CollectRankResults::dispatch();
    $this->info("Queued the rank result collection; {$failed} stale check(s) failed.");
})->purpose('Collect finished rank checks from DataForSEO');

Schedule::command('rank-tracking:collect')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Downloads DataForSEO's location list (~270k places; the memory limit is
// raised inside). --if-stale only when it's missing or older than 30 days.
// The location search never downloads: it queues RefreshRankLocations.
Artisan::command('rank-tracking:locations {--if-stale}', function (RankLocations $locations) {
    $stored = $this->option('if-stale') ? $locations->refreshIfStale() : $locations->refresh();
    $this->info($stored === null ? 'The location list is up to date.' : "Stored {$stored} location(s).");
})->purpose('Refresh the DataForSEO location list');

Schedule::command('rank-tracking:locations --if-stale')->weeklyOn(0, '03:00')->withoutOverlapping()->onOneServer();

// Monthly client reports: for each active schedule whose day of the month is
// today and that hasn't run this month, the previous calendar month's report,
// emailed to the schedule's recipients once generated. Spans workspaces;
// each report is made in its site's workspace and its job carries it. Safe
// to re-run: a schedule is claimed once a month (Reports::runScheduled).
Artisan::command('reports:run-scheduled', function (Reports $reports) {
    $queued = $reports->runScheduled();
    $this->info("Queued {$queued} scheduled report(s).");
})->purpose('Generate and email the monthly client reports due today');

// Hourly: each schedule picks its own hour (send_hour, SAST); one whose hour
// has passed runs on the next hourly run, so a missed hour catches up.
Schedule::command('reports:run-scheduled')->hourlyAt(0)->timezone('Africa/Johannesburg')->withoutOverlapping()->onOneServer();
