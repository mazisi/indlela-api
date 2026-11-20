<?php

use App\Jobs\SyncSearchConsole;
use App\Models\GoogleConnection;
use App\Models\Site;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
