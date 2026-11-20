<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A competitor of a site: a domain (bare host) followed on the site's
 * tracked keywords, from the SERPs the rank checks fetch anyway. Matches its
 * subdomains too. Its workspace is its site's.
 */
class SiteCompetitor extends Model
{
    use Auditable, BelongsToWorkspace, SoftDeletes;

    protected $attributes = ['is_active' => true];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // A competitor lives in its site's workspace, whatever the request's is.
        static::creating(function (SiteCompetitor $competitor) {
            $competitor->workspace_id = Site::withoutGlobalScope('workspace')->withTrashed()->whereKey($competitor->site_id)->value('workspace_id');
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** History names a competitor by its domain. */
    protected function transformAttributesForAudit(array $attributes): array
    {
        return $attributes + ['_label' => $attributes['domain'] ?? null];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class)->withTrashed();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_active'), true);
    }
}
