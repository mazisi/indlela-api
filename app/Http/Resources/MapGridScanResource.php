<?php

namespace App\Http\Resources;

use App\Models\MapGridScan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A local grid scan (never its cost). `ranks` (size x size, row 0 = north)
 * only where the list loaded them (a grid's latest scan, for the small
 * heatmap).
 *
 * @mixin MapGridScan
 */
class MapGridScanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'grid_id' => $this->grid_id,
            'status' => $this->status,
            'trigger' => $this->trigger,
            'size' => $this->size,
            'spacing_km' => $this->spacing_km,
            'center_lat' => $this->center_lat,
            'center_lng' => $this->center_lng,
            'keyword' => $this->keyword,
            'device' => $this->device,
            'points_total' => $this->points_total,
            'points_done' => $this->points_done,
            'points_found' => $this->points_found,
            'arp' => $this->arp,
            'atrp' => $this->atrp,
            'solv' => $this->solv,
            'found_pct' => $this->found_pct,
            'error' => $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'ranks' => $this->when($this->resource->getAttribute('ranks') !== null, fn () => $this->resource->getAttribute('ranks')),
        ];
    }
}
