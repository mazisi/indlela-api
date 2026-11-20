<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A site's competitor settings (one row per site): who gets the daily
 * competitor alert email. No recipients = no email.
 */
class SiteCompetitorSetting extends Model
{
    use Auditable, BelongsToWorkspace;

    protected $attributes = ['alert_recipients' => '[]'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (SiteCompetitorSetting $settings) {
            $settings->workspace_id = Site::withoutGlobalScope('workspace')->withTrashed()->whereKey($settings->site_id)->value('workspace_id');
        });
    }

    protected function casts(): array
    {
        return ['alert_recipients' => 'array'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class)->withTrashed();
    }
}
