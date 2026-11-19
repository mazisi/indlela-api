<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToWorkspace;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A workspace's client (an agency's customer). Users whose role doesn't see
 * every client only see the ones assigned to them (client_user).
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use Auditable, BelongsToWorkspace, HasFactory, SoftDeletes;

    /** The database defaults, so a model just created answers like a loaded one. */
    protected $attributes = ['is_active' => true];

    protected $fillable = ['name', 'contact_name', 'contact_email', 'is_active', 'notes'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Limits the query to the clients this user may access (null = all in the workspace). */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        $ids = $user->accessibleClientIds();

        return $ids === null ? $query : $query->whereIn($this->qualifyColumn('id'), $ids);
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($search)).'%';

        return $query->where(fn (Builder $q) => $q
            ->whereRaw('LOWER(name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(contact_name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(contact_email) LIKE ?', [$like]));
    }
}
