<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A link (or image) found on an audited page. Reached through its audit. */
class SiteAuditLink extends Model
{
    public const LINK = 'link';

    public const IMAGE = 'image';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean', 'nofollow' => 'boolean'];
    }

    public function fromPage(): BelongsTo
    {
        return $this->belongsTo(SiteAuditPage::class, 'from_page_id');
    }
}
