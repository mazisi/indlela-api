<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A role in one workspace (spatie teams: `workspace_id`). Each workspace gets
 * Owner, Manager, Analyst and Client on creation (WorkspaceProvisioner).
 *
 * No global workspace scope here, on purpose: spatie caches every
 * permission with its roles across workspaces, and a scoped query would cache
 * one workspace's view for all. Scope explicitly with inCurrentWorkspace().
 */
class Role extends SpatieRole
{
    use Auditable, SoftDeletes;

    /** Full access, always; can't be renamed, changed or deleted, and a workspace keeps at least one active Owner (workforce's System Administrator). */
    public const OWNER = 'Owner';

    public const MANAGER = 'Manager';

    public const ANALYST = 'Analyst';

    /** Read-only login for an agency's client, limited to their assigned clients. */
    public const CLIENT = 'Client';

    /** Indlela staff, in the platform workspace only (WorkspaceProvisioner): editable like Manager. */
    public const SUPERUSER = 'Superuser';

    // Roles the user CRUD flows guard (last Owner) and Settings › Roles refuses to change.
    const PROTECTED_ROLES = [self::OWNER];

    protected $fillable = ['name', 'guard_name', 'description', 'all_clients', 'workspace_id'];

    protected $guard_name = 'web';

    protected function casts(): array
    {
        return ['all_clients' => 'boolean'];
    }

    public function isSystem(): bool
    {
        return self::isProtected($this->name);
    }

    /**
     * Whether a role name is protected. Case-insensitive so a casing drift in
     * the DB can't silently bypass the guard.
     */
    public static function isProtected(?string $roleName): bool
    {
        if ($roleName === null) {
            return false;
        }

        return in_array(strtolower($roleName), array_map('strtolower', self::PROTECTED_ROLES), true);
    }

    public function scopeInWorkspace(Builder $query, ?int $workspaceId): Builder
    {
        return $query->where($this->qualifyColumn('workspace_id'), $workspaceId);
    }

    public function scopeInCurrentWorkspace(Builder $query): Builder
    {
        return $this->scopeInWorkspace($query, getPermissionsTeamId());
    }
}
