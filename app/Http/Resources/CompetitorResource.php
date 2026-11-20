<?php

namespace App\Http\Resources;

use App\Models\SiteCompetitor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A competitor with its figures over the site's active keywords' latest
 * checks (`latest`, null while paused or before it has any position) and
 * whether any position was recorded for it yet. Set by
 * CompetitorReports::competitors().
 *
 * @mixin SiteCompetitor
 */
class CompetitorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'latest' => $this->latest,
            'has_history' => (bool) $this->has_history,
        ];
    }
}
