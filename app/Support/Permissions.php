<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The permission catalogue (ported from workforce-api), grouped like the
 * sidebar: one resource per menu page, each with the actions that page has.
 * Permission names are `{action}_{resource}`, e.g. `update_clients_sites`.
 *
 * Permissions are global; roles are per workspace (spatie teams), so the same
 * name means the same page in every workspace. The SEO pages that aren't
 * built yet (Search Console, Site audit, Rank tracking, Reports) are
 * catalogued already so the default roles don't need reshaping when they land.
 *
 * Every other action on a resource needs `view` too; saving a role adds it.
 * The SPA's src/config/modules.ts mirrors these keys: keep the two in step.
 */
final class Permissions
{
    public const ACTIONS = ['view', 'create', 'update', 'delete', 'approve'];

    public const ACTION_LABELS = ['view' => 'View', 'create' => 'Create', 'update' => 'Update', 'delete' => 'Delete', 'approve' => 'Approve'];

    private const V = ['view'];

    private const CRUD = ['view', 'create', 'update', 'delete'];

    private const CRUDA = ['view', 'create', 'update', 'delete', 'approve'];

    /**
     * Only Owners hold these; they can't be given to other roles (workforce's
     * System Administrator pages, plus the workspace itself).
     */
    public const ADMIN_ONLY = ['settings_users', 'settings_roles', 'settings_login_history', 'settings_workspace'];

    /**
     * group => [label, [resource => [label, actions]]], in sidebar order.
     *
     * @var array<string, array{0: string, 1: array<string, array{0: string, 1: list<string>}>}>
     */
    public const GROUPS = [
        'dashboard' => ['Dashboard', ['dashboard' => ['Dashboard', self::V]]],
        'clients' => ['Clients', [
            'clients_clients' => ['Clients', self::CRUD],
            'clients_sites' => ['Sites', self::CRUD],
        ]],
        // MVP 2: Google Search Console. Create = connect a site's property (OAuth), delete = disconnect.
        'search_console' => ['Search Console', ['search_console' => ['Search Console', self::CRUD]]],
        // MVP 3: crawls and PageSpeed. Update = run an audit now, cancel it, audit settings (create/delete unused so far).
        'site_audit' => ['Site audit', ['site_audit' => ['Site audit', self::CRUD]]],
        // MVP 1: DataForSEO. Create = add keywords or "check now" (Live mode, costs more).
        'rank_tracking' => ['Rank tracking', ['rank_tracking' => ['Rank tracking', self::CRUD]]],
        // MVP 4: white-label reports. Approve = approve and send (never auto-sent).
        'reports' => ['Reports', ['reports' => ['Reports', self::CRUDA]]],
        'settings' => ['Settings', [
            'settings_workspace' => ['Workspace', ['view', 'update']],
            'settings_users' => ['Users and access', self::CRUD],
            'settings_roles' => ['Roles and permissions', self::CRUD],
            'settings_login_history' => ['Login history', self::V],
            'settings_email_templates' => ['Email templates', ['view', 'update']],
        ]],
    ];

    /** @return array<string, array{group: string, label: string, actions: list<string>}> resource => details */
    public static function resources(): array
    {
        $out = [];
        foreach (self::GROUPS as $group => [, $resources]) {
            foreach ($resources as $key => [$label, $actions]) {
                $out[$key] = ['group' => $group, 'label' => $label, 'actions' => $actions];
            }
        }

        return $out;
    }

    /** @return list<string> every permission name */
    public static function all(): array
    {
        $names = [];
        foreach (self::resources() as $resource => ['actions' => $actions]) {
            foreach ($actions as $action) {
                $names[] = "{$action}_{$resource}";
            }
        }

        return $names;
    }

    /** @return list<string> the permissions a role other than Owner may hold */
    public static function grantable(): array
    {
        return array_values(array_filter(self::all(), fn (string $name) => ! in_array(self::resourceOf($name), self::ADMIN_ONLY, true)));
    }

    /** @return list<string> the permissions only Owners hold */
    public static function adminOnly(): array
    {
        return array_values(array_diff(self::all(), self::grantable()));
    }

    public static function resourceOf(string $permission): string
    {
        return substr($permission, strpos($permission, '_') + 1);
    }

    /**
     * Adds the rows for any of these catalogue permissions the database
     * doesn't have yet (a page deployed before the seeder ran), so a role can
     * be given them instead of the save failing. Reads the table, not
     * spatie's cache, and clears the cache when it adds any.
     *
     * @param  list<string>  $names  catalogue names (validated)
     */
    public static function ensureExist(array $names): void
    {
        $missing = array_values(array_diff($names, DB::table('permissions')->where('guard_name', 'web')->whereIn('name', $names)->pluck('name')->all()));
        if ($missing === []) {
            return;
        }

        $now = now();
        DB::table('permissions')->insertOrIgnore(array_map(fn (string $name) => ['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now], $missing));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Every action needs view: add view_x wherever another action on x is held.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function withView(array $names): array
    {
        foreach ($names as $name) {
            $names[] = 'view_'.self::resourceOf($name);
        }

        return array_values(array_unique($names));
    }

    /**
     * Expand a group => level map (none / view / edit / approve) into
     * permissions: view → view; edit → every action but approve; approve →
     * every action. Never the Owner-only pages.
     *
     * @param  array<string, string>  $levels
     * @return list<string>
     */
    public static function forGroupLevels(array $levels): array
    {
        $allowed = ['view' => ['view'], 'edit' => ['view', 'create', 'update', 'delete'], 'approve' => self::ACTIONS];
        $names = [];
        foreach (self::resources() as $resource => ['group' => $group, 'actions' => $actions]) {
            foreach (array_intersect($actions, $allowed[$levels[$group] ?? 'none'] ?? []) as $action) {
                $names[] = "{$action}_{$resource}";
            }
        }

        return array_values(array_filter($names, fn (string $name) => in_array($name, self::grantable(), true)));
    }
}
