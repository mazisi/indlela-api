<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspaceProvisioner;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * The permission catalogue (App\Support\Permissions) and every workspace's
 * default roles (WorkspaceProvisioner). Idempotent: re-running re-syncs the
 * catalogue, adds missing default roles, and gives Owner everything; it never
 * overwrites what a workspace has since changed in Settings › Roles.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::all() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Permissions no longer in the catalogue.
        Permission::whereNotIn('name', Permissions::all())->delete();

        $provisioner = app(WorkspaceProvisioner::class);
        Workspace::query()->each(fn (Workspace $workspace) => $provisioner->ensureDefaultRoles($workspace));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
