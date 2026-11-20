<?php

namespace App\Support;

use App\Http\Resources\UserLoginResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What the SPA keeps about the signed-in user, shared by POST /login,
 * GET /me (= /user/permissions) and the workspace switch: workforce's
 * `user, roles, permissions` plus the current `workspace` and the
 * `workspaces` the user can switch to. Call after WorkspaceContext::enter().
 */
final class AccountState
{
    /** @return array{user: UserLoginResource, roles: mixed, permissions: mixed, workspace: ?WorkspaceResource, workspaces: list<array<string, mixed>>} */
    public static function for(User $user): array
    {
        $user->loadMissing('roles');
        $current = app(CurrentWorkspace::class)->get();

        return [
            'user' => new UserLoginResource($user),
            'roles' => $user->getRoleNames(),
            // Platform pages only ever in the platform workspace, whatever a role holds.
            'permissions' => $user->getAllPermissions()->pluck('name')
                ->when(! PlatformWorkspace::is($current), fn ($names) => $names->diff(Permissions::platformOnly()))
                ->values(),
            'workspace' => $current ? new WorkspaceResource($current) : null,
            'workspaces' => self::workspaces($user),
        ];
    }

    /**
     * Every workspace the user belongs to, with their role there, by name.
     *
     * @return list<array{id: int, name: string, slug: string, logo_url: ?string, role: ?string}>
     */
    public static function workspaces(User $user): array
    {
        // Roles across every workspace: straight from the pivot, since spatie's
        // relation only sees the current team.
        $roles = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->id)
            ->whereNull('roles.deleted_at')
            ->pluck('roles.name', 'roles.workspace_id');

        return $user->workspaces()->orderBy('name')->get()
            ->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'slug' => $w->slug,
                'logo_url' => $w->logoUrl(),
                'role' => $roles[$w->id] ?? null,
            ])->values()->all();
    }
}
