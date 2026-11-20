<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A URL an audit fetched. No workspace column: always reached through its
 * audit (route binding is scoped to the audit, which is workspace-scoped).
 */
class SiteAuditPage extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'redirect_chain' => 'array',
            'extras' => 'array',
            'is_indexable' => 'boolean',
            'in_sitemap' => 'boolean',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(SiteAudit::class, 'audit_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(SiteAuditIssue::class, 'page_id');
    }

    public function isHtml(): bool
    {
        return str_contains(strtolower((string) $this->content_type), 'html');
    }

    public function isNoindex(): bool
    {
        return self::hasNoindex($this->meta_robots) || self::hasNoindex($this->x_robots_tag);
    }

    public static function hasNoindex(?string $directives): bool
    {
        return $directives !== null && preg_match('/\b(noindex|none)\b/i', $directives) === 1;
    }
}
