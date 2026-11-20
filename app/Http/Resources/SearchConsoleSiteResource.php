<?php

namespace App\Http\Resources;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A site as the Search Console page sees it: its property and sync state.
 *
 * @mixin Site
 */
class SearchConsoleSiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'client' => $this->whenLoaded('client', fn () => ['id' => $this->client->id, 'name' => $this->client->name]),
            'gsc_property' => $this->gsc_property,
            'gsc_sync_status' => $this->gsc_sync_status,
            'gsc_synced_at' => $this->gsc_synced_at?->toIso8601String(),
            'gsc_data_through' => $this->gsc_data_through?->toDateString(),
            'gsc_sync_error' => $this->gsc_sync_error,
        ];
    }

    /** The sync state alone, added to report responses so the page can show "Syncing…". */
    public static function syncState(Site $site): array
    {
        return [
            'status' => $site->gsc_sync_status,
            'synced_at' => $site->gsc_synced_at?->toIso8601String(),
            'data_through' => $site->gsc_data_through?->toDateString(),
            'error' => $site->gsc_sync_error,
        ];
    }
}
