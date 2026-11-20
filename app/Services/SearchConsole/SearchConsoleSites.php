<?php

namespace App\Services\SearchConsole;

use App\Exceptions\SearchConsoleException;
use App\Jobs\SyncSearchConsole;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Linking sites to Search Console properties and queueing their syncs.
 */
class SearchConsoleSites
{
    public function __construct(
        private readonly GoogleConnectionService $connections,
        private readonly SearchConsoleApi $api,
        private readonly SearchConsoleSync $sync,
    ) {}

    /**
     * Link $site to $property (one the connected Google account can read) and
     * queue its backfill. Linking another property first drops the old one's data.
     */
    public function link(Site $site, string $property): Site
    {
        $connection = $this->connections->requireActive();

        $readable = array_column($this->api->properties($connection), 'site_url');
        if (! in_array($property, $readable, true)) {
            throw ValidationException::withMessages(['property' => 'The connected Google account has no access to this Search Console property.']);
        }

        DB::transaction(function () use ($site, $property) {
            if ($site->gsc_property !== $property) {
                $this->sync->purge($site);
                $site->forceFill(['gsc_property' => $property, 'gsc_synced_at' => null, 'gsc_data_through' => null]);
            }

            $site->forceFill(['gsc_sync_status' => Site::GSC_QUEUED, 'gsc_sync_error' => null])->save();
        });

        SyncSearchConsole::dispatch($site->id, $site->workspace_id);

        // Fresh: on the sync queue driver the job has already run.
        return $site->refresh();
    }

    /** Unlink and delete the site's Search Console data. */
    public function unlink(Site $site): void
    {
        DB::transaction(function () use ($site) {
            $this->sync->purge($site);
            $site->forceFill([
                'gsc_property' => null,
                'gsc_sync_status' => Site::GSC_IDLE,
                'gsc_synced_at' => null,
                'gsc_data_through' => null,
                'gsc_sync_error' => null,
            ])->save();
        });
    }

    /** "Sync now": 409 when the site isn't linked or Google isn't connected. */
    public function queueSync(Site $site): Site
    {
        if (! $site->hasSearchConsole()) {
            throw SearchConsoleException::notLinked();
        }
        $this->connections->requireActive();

        $site->forceFill(['gsc_sync_status' => Site::GSC_QUEUED, 'gsc_sync_error' => null])->save();
        SyncSearchConsole::dispatch($site->id, $site->workspace_id);

        // Fresh: on the sync queue driver the job has already run.
        return $site->refresh();
    }
}
