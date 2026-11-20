<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A white-label report of a site for a period: generating → ready | failed.
 * `data` is the snapshot frozen at generation (the PDF, the share page and
 * the email all read it), `summary` its plain-English bullets. Shared through
 * a random `share_token` until `share_expires_at` (null = no expiry).
 */
class Report extends Model
{
    use Auditable, BelongsToWorkspace, SoftDeletes;

    public const GENERATING = 'generating';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const MANUAL = 'manual';

    public const SCHEDULED = 'scheduled';

    public const SECTIONS = ['search_console', 'rank_tracking', 'site_audit'];

    public const COMPARES = ['previous', 'year', 'none'];

    protected $attributes = ['status' => self::GENERATING, 'trigger' => self::MANUAL, 'compare' => 'previous'];

    protected $guarded = ['id'];

    protected $hidden = ['share_token'];

    /**
     * History: generated, emailed, shared or unshared, deleted. Not the
     * snapshot, the PDF's path or the generation's progress, and never the
     * share token (a secret): sharing shows as "Shared" yes/no instead.
     */
    protected function auditExclude(): array
    {
        return ['data', 'summary', 'pdf_path', 'status', 'error', 'share_token'];
    }

    protected function transformAttributesForAudit(array $attributes): array
    {
        return $attributes + ['_label' => $attributes['title'] ?? null];
    }

    protected function extraAuditChanges(): array
    {
        if (! $this->exists || ! $this->wasChanged('share_token')) {
            return [];
        }

        return ['shared' => ['old' => $this->getOriginal('share_token') !== null, 'new' => $this->share_token !== null]];
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'sections' => 'array',
            'data' => 'array',
            'summary' => 'array',
            'sent_to' => 'array',
            'sent_at' => 'datetime',
            'share_expires_at' => 'datetime',
        ];
    }

    public function isReady(): bool
    {
        return $this->status === self::READY;
    }

    /** Shared and not expired. */
    public function isShared(): bool
    {
        return $this->share_token !== null && ($this->share_expires_at === null || $this->share_expires_at->isFuture());
    }

    /** The public page: {FRONTEND_URL}/r/{token}. */
    public function shareUrl(): ?string
    {
        return $this->isShared() ? rtrim((string) config('app.frontend_url'), '/').'/r/'.$this->share_token : null;
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class)->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by')->withTrashed();
    }
}
