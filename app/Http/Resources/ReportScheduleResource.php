<?php

namespace App\Http\Resources;

use App\Models\ReportSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A site's monthly report schedule (Schedule in docs/API.md).
 *
 * @mixin ReportSchedule
 */
class ReportScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'site_id' => $this->site_id,
            'is_active' => $this->is_active,
            'day_of_month' => $this->day_of_month,
            'send_hour' => $this->send_hour,
            'recipients' => $this->recipients ?? [],
            'sections' => $this->sections ?? [],
            'compare' => $this->compare,
            'note' => $this->note,
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'next_run_on' => $this->resource->nextRunOn()?->toDateString(),
            'next_run_at' => $this->resource->nextRunAt()?->toIso8601String(),
        ];
    }
}
