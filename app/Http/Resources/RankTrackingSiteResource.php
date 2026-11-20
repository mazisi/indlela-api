<?php

namespace App\Http\Resources;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A site as the Rank tracking page lists it, with `figures` from
 * RankReports::siteFigures.
 *
 * @mixin Site
 */
class RankTrackingSiteResource extends JsonResource
{
    /** @var array<string, mixed> */
    public array $figures = [];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'client' => $this->whenLoaded('client', fn () => ['id' => $this->client->id, 'name' => $this->client->name]),
            ...$this->figures,
        ];
    }
}
