<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToWorkspace;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A client's website: what Search Console, the site audit and rank tracking
 * attach to. Its workspace is always its client's (set on create).
 */
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use Auditable, BelongsToWorkspace, HasFactory, SoftDeletes;

    public const GSC_IDLE = 'idle';

    public const GSC_QUEUED = 'queued';

    public const GSC_SYNCING = 'syncing';

    public const GSC_DONE = 'done';

    public const GSC_FAILED = 'failed';

    public const AUDIT_OFF = 'off';

    public const AUDIT_WEEKLY = 'weekly';

    public const AUDIT_MONTHLY = 'monthly';

    public const AUDIT_SCHEDULES = [self::AUDIT_OFF, self::AUDIT_WEEKLY, self::AUDIT_MONTHLY];

    /** The database defaults, so a model just created answers like a loaded one. */
    protected $attributes = ['is_active' => true, 'gsc_sync_status' => self::GSC_IDLE, 'audit_schedule' => self::AUDIT_OFF, 'audit_page_limit' => 500];

    protected $fillable = ['client_id', 'name', 'url', 'domain', 'is_active'];

    protected static function booted(): void
    {
        // A site lives in its client's workspace, whatever the request's is.
        static::creating(function (Site $site) {
            $site->workspace_id = Client::withoutGlobalScope('workspace')->whereKey($site->client_id)->value('workspace_id');
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'gsc_synced_at' => 'datetime',
            'gsc_data_through' => 'date',
            'audit_page_limit' => 'integer',
        ];
    }

    /** Linking a property is history; the sync's bookkeeping isn't. */
    protected function auditExclude(): array
    {
        return ['gsc_sync_status', 'gsc_synced_at', 'gsc_data_through', 'gsc_sync_error'];
    }

    public function hasSearchConsole(): bool
    {
        return $this->gsc_property !== null;
    }

    /** Sites with a Search Console property linked. */
    public function scopeLinkedToSearchConsole(Builder $query): Builder
    {
        return $query->whereNotNull($this->qualifyColumn('gsc_property'));
    }

    /** History shows the client's name, not its id ("acme.co.za · Acme"). */
    protected function transformAttributesForAudit(array $attributes): array
    {
        if (array_key_exists('client_id', $attributes)) {
            $attributes['client'] = Client::withoutGlobalScope('workspace')->withTrashed()->whereKey($attributes['client_id'])->value('name');
            unset($attributes['client_id']);
        }

        return $attributes;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(SiteAudit::class);
    }

    /** The latest audit that finished its crawl and analysis (status done). */
    public function latestAudit(): HasOne
    {
        return $this->hasOne(SiteAudit::class)->ofMany(['id' => 'max'], fn (Builder $q) => $q->where('status', SiteAudit::DONE));
    }

    /** The audit queued or in progress, if any (at most one). */
    public function runningAudit(): HasOne
    {
        return $this->hasOne(SiteAudit::class)->ofMany(['id' => 'max'], fn (Builder $q) => $q->whereIn('status', SiteAudit::RUNNING));
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /** The newest report (any status). */
    public function latestReport(): HasOne
    {
        return $this->hasOne(Report::class)->latestOfMany('id');
    }

    public function reportSchedule(): HasOne
    {
        return $this->hasOne(ReportSchedule::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Sites of the clients this user may access. */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        $ids = $user->accessibleClientIds();

        return $ids === null ? $query : $query->whereIn($this->qualifyColumn('client_id'), $ids);
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($search)).'%';

        return $query->where(fn (Builder $q) => $q
            ->whereRaw('LOWER(sites.name) LIKE ?', [$like])
            ->orWhereRaw('sites.domain LIKE ?', [$like]));
    }

    /**
     * "https://WWW.Example.co.za/blog?x" → ["https://www.example.co.za/blog", "example.co.za"]:
     * the URL as a home page (scheme added, query and fragment dropped) and the domain the
     * site is known by (lower case, no www.). Null when it isn't a web address.
     *
     * @return array{url: string, domain: string}|null
     */
    public static function normalise(string $input): ?array
    {
        $input = trim($input);
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $input)) {
            $input = 'https://'.$input;
        }

        $parts = parse_url($input);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(rtrim($parts['host'] ?? '', '.'));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ! str_contains($host, '.')
            || ! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host)) {
            return null;
        }

        $path = rtrim($parts['path'] ?? '', '/');
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return [
            'url' => "{$scheme}://{$host}{$port}{$path}",
            'domain' => preg_replace('/^www\./', '', $host),
        ];
    }
}
