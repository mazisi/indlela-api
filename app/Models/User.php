<?php

namespace App\Models;

use App\Enums\MfaChannel;
use App\Enums\UserStatus;
use App\Support\CurrentWorkspace;
use App\Traits\Auditable;
use App\Traits\HasSlug;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * An account. It can belong to several workspaces (workspace_user) and acts
 * in `current_workspace_id`; its roles, and so its permissions, are those it
 * holds in the current workspace (spatie teams, set by the `workspace`
 * middleware). Status, password and PIN channel are the account's, shared by
 * every workspace.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, HasRoles, HasSlug, Notifiable, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'full_name',
        'surname',
        'email',
        'mobile',
        'status',
        'mfa_channel',
        'password',
        'slug',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'status' => UserStatus::class,
            'mfa_channel' => MfaChannel::class,
            'password' => 'hashed',
            'failed_login_attempts' => 'integer',
            'current_workspace_id' => 'integer',
            'locked_at' => 'datetime',
            'last_login_at' => 'datetime',
            'email_verified_at' => 'datetime',
        ];
    }

    /** Emails are matched case-insensitively everywhere; store them lower-cased. */
    protected function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower(trim($value));
    }

    public function scopeWhereEmail(Builder $query, string $email): Builder
    {
        return $query->where('email', strtolower(trim($email)));
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')->withTimestamps();
    }

    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    /** Client assignments in every workspace; use assignedClients() for the current one. */
    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class)->withTimestamps();
    }

    /** The clients assigned to this user in the current workspace (Client's global scope). */
    public function assignedClients(): BelongsToMany
    {
        return $this->clients();
    }

    public function belongsToWorkspace(Workspace|int $workspace): bool
    {
        $id = $workspace instanceof Workspace ? $workspace->getKey() : $workspace;

        return $this->workspaces()->whereKey($id)->exists();
    }

    /**
     * Whether the account is also in another workspace: an account shared with other
     * workspaces keeps its own details (see UserManagementService).
     */
    public function belongsToOtherWorkspaces(Workspace|int $workspace): bool
    {
        $id = $workspace instanceof Workspace ? $workspace->getKey() : $workspace;

        return $this->workspaces()->whereKeyNot($id)->exists();
    }

    /**
     * Change history leaves out secrets and what moves on its own with every
     * sign-in (Login history covers those).
     */
    protected function auditExclude(): array
    {
        return [
            'password', 'remember_token', 'slug', 'failed_login_attempts', 'locked_at',
            'last_login_at', 'email_verified_at', 'current_workspace_id',
        ];
    }

    public function displayName(): string
    {
        return trim($this->full_name.' '.$this->surname);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /** Owner of the current workspace. */
    public function isOwner(): bool
    {
        return $this->hasRole(Role::OWNER);
    }

    /** The user's role in the current workspace (one per workspace). */
    public function currentRole(): ?Role
    {
        return $this->roles->first();
    }

    /**
     * Whether the user sees every client of the current workspace: their role
     * says so (Owner always; Manager by default), else only assigned clients.
     */
    public function seesAllClients(): bool
    {
        $role = $this->currentRole();

        return $role !== null && ($role->isSystem() || $role->all_clients);
    }

    /**
     * Ids of the current workspace's clients this user may access, or null
     * when unrestricted (within the workspace; the Client scope still applies).
     *
     * @return list<int>|null
     */
    public function accessibleClientIds(): ?array
    {
        if ($this->seesAllClients()) {
            return null;
        }

        return $this->assignedClients()->pluck('clients.id')->all();
    }

    public function canAccessClient(Client $client): bool
    {
        if ((int) $client->workspace_id !== app(CurrentWorkspace::class)->id()) {
            return false;
        }

        return $this->seesAllClients()
            || $this->clients()->whereKey($client->getKey())->exists();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', UserStatus::Active);
    }

    /** Members of a workspace. */
    public function scopeInWorkspace(Builder $query, Workspace|int $workspace): Builder
    {
        $id = $workspace instanceof Workspace ? $workspace->getKey() : $workspace;

        return $query->whereHas('workspaces', fn (Builder $q) => $q->whereKey($id));
    }
}
