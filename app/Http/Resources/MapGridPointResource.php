<?php

namespace App\Http\Resources;

use App\Models\MapGridPoint;
use App\Services\LocalGrid\MapsParser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One point of a scan with its top 3 (the tracked business flagged). The
 * resource is made with the scan's target: {place_id, cid, domain}.
 *
 * @mixin MapGridPoint
 */
class MapGridPointResource extends JsonResource
{
    /** @var array{place_id: ?string, cid: ?string, domain: ?string} */
    public array $target = ['place_id' => null, 'cid' => null, 'domain' => null];

    public function toArray(Request $request): array
    {
        return [
            'row' => $this->row,
            'col' => $this->col,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'status' => $this->status,
            'rank' => $this->rank,
            'top' => array_map(fn (array $listing) => [
                'rank' => $listing['rank'],
                'title' => $listing['title'],
                'rating' => $listing['rating'] ?? null,
                'votes' => $listing['votes'] ?? null,
                'domain' => $listing['domain'] ?? null,
                'is_target' => MapsParser::matches($listing, $this->target),
            ], $this->top ?? []),
        ];
    }
}
