<?php

namespace App\Http\Resources;

use App\Models\Report;
use App\Services\Reports\ReportPeriods;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A report as the Reports page lists it (ReportSummaryRow in docs/API.md).
 * The share token appears only inside `share.url`.
 *
 * @mixin Report
 */
class ReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'title' => $this->title,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'period_label' => ReportPeriods::label(CarbonImmutable::parse($this->period_start), CarbonImmutable::parse($this->period_end)),
            'compare' => $this->compare,
            'status' => $this->status,
            'error' => $this->error,
            'summary' => $this->summary ?? [],
            'note' => $this->note,
            'sections' => $this->sections ?? [],
            'trigger' => $this->trigger,
            'generated_by' => $this->generatedBy ? ['id' => $this->generatedBy->id, 'name' => $this->generatedBy->displayName()] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'sent_to' => $this->sent_to ?? [],
            'share' => $this->resource->isShared() ? ['url' => $this->resource->shareUrl(), 'expires_at' => $this->share_expires_at?->toIso8601String()] : null,
        ];
    }
}
