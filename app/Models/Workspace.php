<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A tenant: an agency or an SME. Everything else (clients, sites, roles,
 * email templates, change history) belongs to one. Create workspaces through
 * App\Services\Workspaces\WorkspaceProvisioner, which seeds the default roles.
 */
class Workspace extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = ['name', 'slug', 'logo_path'];

    protected function casts(): array
    {
        return ['rank_keyword_limit' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace) {
            $workspace->slug ??= static::uniqueSlug($workspace->name);
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        for ($i = 2; static::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /** The white-label logo on the default (s3) disk, or null. */
    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::url($this->logo_path) : null;
    }

    /** Active rank-tracking keywords this workspace may have: its own limit, else the beta default. */
    public function rankKeywordLimit(): int
    {
        return $this->rank_keyword_limit ?? (int) config('indlela.rank_tracking.workspace_keyword_limit');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')->withTimestamps();
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /** The workspace's own record is its own history. */
    protected function auditWorkspaceId(): ?int
    {
        return $this->getKey();
    }
}
