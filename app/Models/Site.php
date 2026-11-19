<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToWorkspace;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A client's website: what Search Console, the site audit and rank tracking
 * attach to. Its workspace is always its client's (set on create).
 */
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use Auditable, BelongsToWorkspace, HasFactory, SoftDeletes;

    /** The database defaults, so a model just created answers like a loaded one. */
    protected $attributes = ['is_active' => true];

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
        return ['is_active' => 'boolean'];
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
