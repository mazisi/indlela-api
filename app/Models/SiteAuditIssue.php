<?php

namespace App\Models;

use App\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One occurrence of an issue type (App\Support\AuditIssues) in an audit: on
 * a page (page_id) or the site as a whole (page_id null).
 */
class SiteAuditIssue extends Model
{
    use BelongsToWorkspace;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'is_new' => 'boolean'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(SiteAuditPage::class, 'page_id');
    }

    public static function fingerprint(string $type, ?string $url, string $key = ''): string
    {
        return sha1($type.'|'.($url ?? '').'|'.$key);
    }
}
