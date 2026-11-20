<?php

namespace App\Http\Resources;

use App\Models\SiteAudit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An audit's summary (AuditSummary in docs/API.md): status and progress,
 * health score, issue counts, the comparison with the previous audit and the
 * Core Web Vitals state.
 *
 * @mixin SiteAudit
 */
class SiteAuditResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $counts = $this->counts ?? [];

        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'status' => $this->status,
            'trigger' => $this->trigger,
            'triggered_by' => $this->triggeredBy ? ['id' => $this->triggeredBy->id, 'name' => $this->triggeredBy->displayName()] : null,
            'page_limit' => $this->page_limit,
            'pages_crawled' => $this->pages_crawled,
            'pages_found' => $this->pages_found,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'error' => $this->error,
            'health_score' => $this->health_score,
            'counts' => [
                'critical' => (int) ($counts['critical'] ?? 0),
                'warning' => (int) ($counts['warning'] ?? 0),
                'notice' => (int) ($counts['notice'] ?? 0),
                'total' => (int) ($counts['total'] ?? 0),
            ],
            'comparison' => $this->comparison,
            'vitals_status' => $this->vitals_status,
            'vitals_message' => $this->vitals_message,
            // Older audits keep only this summary (pages, links and issues pruned).
            'details_pruned' => $this->pruned_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
