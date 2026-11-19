<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
