<?php

namespace App\Http\Resources;

use App\Models\MapGrid;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A local grid with its latest scan (any status, so a running one shows)
 * and, on its own page, `scans` (the latest, newest first).
 *
 * @mixin MapGrid
 */
class MapGridResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'keyword' => $this->keyword,
            'business' => $this->business(),
            'center_lat' => $this->center_lat,
            'center_lng' => $this->center_lng,
            'size' => $this->size,
            'spacing_km' => $this->spacing_km,
            'device' => $this->device,
            'schedule' => $this->schedule,
            'is_active' => $this->is_active,
            'next_scan_on' => $this->relationLoaded('latestDoneScan') ? $this->nextScanOn()?->toDateString() : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'latest_scan' => $this->relationLoaded('latestScan') && $this->latestScan ? MapGridScanResource::make($this->latestScan) : null,
            'scans' => MapGridScanResource::collection($this->whenLoaded('scans')),
        ];
    }
}
