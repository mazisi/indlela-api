<?php

namespace App\Services\Workspaces;

use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates workspaces with their default roles, and keeps those roles in
 * place (RolePermissionSeeder calls ensureDefaultRoles for every workspace).
 *
 * The default roles, by sidebar group level (none / view / edit / approve,
 * expanded by Permissions::forGroupLevels as in workforce's seeder):
 *  - Owner: everything, always (workforce's System Administrator): can't be
 *    renamed, changed or deleted, and a workspace keeps one active Owner.
 *  - Manager: every client and every SEO tool; edits email templates; no
 *    users, roles, login history or workspace settings.
 *  - Analyst: the SEO tools for their assigned clients.
 *  - Client: read-only, their assigned clients only (an agency's client login).
 */
class WorkspaceProvisioner
{
    /** @var array<string, array{description: string, all_clients: bool, levels: array<string, string>}> */
    public const ROLES = [
        Role::MANAGER => [
            'description' => 'Every client and every SEO tool; no users or roles',
            'all_clients' => true,
            'levels' => [
                'dashboard' => 'approve', 'clients' => 'approve', 'search_console' => 'approve', 'site_audit' => 'approve',
                'rank_tracking' => 'approve', 'reports' => 'approve', 'settings' => 'edit',
            ],
        ],
        Role::ANALYST => [
            'description' => 'Runs the SEO tools for the clients assigned to them',
            'all_clients' => false,
            'levels' => [
                'dashboard' => 'view', 'clients' => 'view', 'search_console' => 'edit', 'site_audit' => 'edit',
                'rank_tracking' => 'edit', 'reports' => 'edit',
            ],
        ],
        Role::CLIENT => [
            'description' => 'Read-only login for a client: their own sites and reports',
            'all_clients' => false,
            'levels' => [
                'dashboard' => 'view', 'clients' => 'view', 'search_console' => 'view', 'site_audit' => 'view',
                'rank_tracking' => 'view', 'reports' => 'view',
            ],
        ],
    ];

    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * A new workspace with its default roles, and $owner as its Owner (now
     * acting in it).
     */
    public function create(string $name, User $owner): Workspace
    {
        return DB::transaction(function () use ($name, $owner) {
            $workspace = Workspace::create(['name' => $name]);
            $workspace->users()->attach($owner->id);

            $this->current->using($workspace, function () use ($workspace, $owner) {
                $this->ensureDefaultRoles($workspace);
                $owner->unsetRelation('roles')->unsetRelation('permissions');
                $owner->assignRole(Role::inWorkspace($workspace->id)->where('name', Role::OWNER)->firstOrFail());
                $owner->logAuditChange('UPDATE', ['role' => ['old' => null, 'new' => Role::OWNER]], ['name' => $owner->displayName()]);
            });

            $owner->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();

            return $workspace;
        });
    }

    /**
     * Adds any default role the workspace is missing (with its default
     * permissions) and gives Owner every permission. Never changes what a
     * workspace has since done to its other roles in Settings › Roles.
     */
    public function ensureDefaultRoles(Workspace $workspace): void
    {
        $this->current->using($workspace, function () use ($workspace) {
            $owner = Role::inWorkspace($workspace->id)->firstOrCreate(
                ['name' => Role::OWNER, 'guard_name' => 'web', 'workspace_id' => $workspace->id],
                ['description' => 'Full access: users, roles, workspace settings and every client', 'all_clients' => true],
            );
            $owner->syncPermissions(Permissions::all());

            foreach (self::ROLES as $name => $role) {
                $model = Role::withTrashed()->inWorkspace($workspace->id)->firstOrCreate(
                    ['name' => $name, 'guard_name' => 'web', 'workspace_id' => $workspace->id],
                    ['description' => $role['description'], 'all_clients' => $role['all_clients']],
                );

                if ($model->wasRecentlyCreated) {
                    $model->syncPermissions(Permissions::forGroupLevels($role['levels']));
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
