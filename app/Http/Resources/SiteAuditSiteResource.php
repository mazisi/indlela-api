<?php

namespace App\Http\Resources;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A site as the Site audit page sees it: its audit settings, latest finished
 * (done) audit and the audit queued or running, if any.
 *
 * @mixin Site
 */
class SiteAuditSiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'client' => $this->whenLoaded('client', fn () => ['id' => $this->client->id, 'name' => $this->client->name]),
            'audit_schedule' => $this->audit_schedule,
            'audit_page_limit' => $this->audit_page_limit,
            'latest_audit' => $this->latestAudit ? SiteAuditResource::make($this->latestAudit) : null,
            'running_audit' => $this->runningAudit ? SiteAuditResource::make($this->runningAudit) : null,
        ];
    }
}
