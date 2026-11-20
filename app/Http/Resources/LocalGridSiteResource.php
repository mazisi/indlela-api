<?php

namespace App\Http\Resources;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A site as the Local grid page lists it: its grid count and latest
 * finished scan (LocalGrids::sites).
 *
 * @mixin Site
 */
class LocalGridSiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $latest = $this->relationLoaded('latestGridScan') ? $this->getRelation('latestGridScan') : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'domain' => $this->domain,
            'client' => $this->whenLoaded('client', fn () => ['id' => $this->client->id, 'name' => $this->client->name]),
            'grids_count' => (int) $this->grids_count,
            'latest_scan' => $latest ? MapGridScanResource::make($latest) : null,
        ];
    }
}
