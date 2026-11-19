<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RolePermissionsRequest;
use App\Http\Requests\Settings\RoleRequest;
use App\Http\Resources\ChangeHistoryResource;
use App\Http\Resources\RoleResource;
use App\Models\AuditLog;
use App\Models\Role;
use App\Support\CurrentWorkspace;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Settings › Roles and permissions (Owners), ported from workforce-api. Only
 * the current workspace's roles: `{role}` binds within it
 * (AppServiceProvider). The grid is built from the catalogue in
 * App\Support\Permissions, grouped like the sidebar.
 *
 * Changes take effect on the next request: permissions are checked on the
 * server every time, so members don't need to be signed out.
 */
class RoleController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function index(): JsonResponse
    {
        Gate::authorize('view_settings_roles');

        $roles = Role::inCurrentWorkspace()->with('permissions')->withCount('users')->get()
            // Owner first, then alphabetical.
            ->sortBy(fn (Role $r) => [$r->isSystem() ? 0 : 1, mb_strtolower($r->name)])
            ->values();

        return response()->json([
            'roles' => RoleResource::collection($roles),
            // The grid: sidebar groups, one row per page, with the actions it has.
            'groups' => collect(Permissions::GROUPS)->map(fn (array $group, string $key) => [
                'key' => $key,
                'label' => $group[0],
                'resources' => collect($group[1])->map(fn (array $resource, string $resourceKey) => [
                    'key' => $resourceKey,
                    'label' => $resource[0],
                    'actions' => $resource[1],
                    'grantable' => ! in_array($resourceKey, Permissions::ADMIN_ONLY, true),
                ])->values(),
            ])->values(),
        ]);
    }

    // RoleRequest authorises store and update (before its rules run).
    public function store(RoleRequest $request): JsonResponse
    {
        $data = $request->validated();

        // A deleted role with the same name comes back empty, rather than
        // tripping the (workspace_id, name, guard_name) unique index.
        $role = Role::onlyTrashed()->inCurrentWorkspace()->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])->first();
        if ($role) {
            $role->restore();
            $role->update($data);
            $role->syncPermissions([]);
        } else {
            $role = Role::create([...$data, 'guard_name' => 'web', 'workspace_id' => $this->current->id()]);
        }

        return $this->show($role)->response()->setStatusCode(201);
    }

    public function update(RoleRequest $request, Role $role): RoleResource
    {
        $this->refuseSystemRole($role);

        $role->update($request->validated());

        return $this->show($role);
    }

    /**
     * Replace a role's permissions. Logged as one history entry listing each
     * page whose actions changed. Owner always has everything: refused.
     */
    public function updatePermissions(RolePermissionsRequest $request, Role $role): RoleResource|JsonResponse
    {
        $this->refuseSystemRole($role);

        $before = $role->permissions->pluck('name')->all();
        $after = $request->permissionNames();

        try {
            Permissions::ensureExist($after);
            DB::transaction(fn () => $role->syncPermissions($after));
        } catch (Throwable $e) {
            return $this->saveFailed($e, $role, $after);
        }

        $describe = function (array $names, string $resource): string {
            $actions = array_filter(Permissions::ACTIONS, fn (string $a) => in_array("{$a}_{$resource}", $names, true));

            return $actions ? implode(', ', array_map(fn ($a) => Permissions::ACTION_LABELS[$a], $actions)) : 'No access';
        };
        $changes = [];
        foreach (array_keys(Permissions::resources()) as $resource) {
            [$old, $new] = [$describe($before, $resource), $describe($after, $resource)];
            if ($old !== $new) {
                $changes[$resource] = ['old' => $old, 'new' => $new];
            }
        }
        if ($changes) {
            $role->logAuditChange('UPDATE', $changes, ['name' => $role->name]);
        }

        return $this->show($role->fresh());
    }

    /**
     * Saving failed: the error itself in the message (only people who may
     * change roles see it), and what's needed to find out why in the log.
     *
     * @param  list<string>  $names
     */
    private function saveFailed(Throwable $e, Role $role, array $names): JsonResponse
    {
        $context = [
            'exception' => $e::class,
            'error' => mb_substr($e->getMessage(), 0, 1000),
            'at' => str_replace(base_path().'/', '', $e->getFile()).':'.$e->getLine(),
            'role' => ['id' => $role->id, 'name' => $role->name, 'workspace_id' => $role->workspace_id],
        ];
        try {
            $context['missing_permissions'] = array_values(array_diff($names, DB::table('permissions')->where('guard_name', 'web')->whereIn('name', $names)->pluck('name')->all()));
        } catch (Throwable $inner) {
            $context['diagnostics_failed'] = $inner->getMessage();
        }

        Log::error('Saving role permissions failed', $context + ['exception_object' => $e]);

        return response()->json([
            'message' => "Could not save the permissions: {$context['error']}",
            'error_type' => 'role_permissions_failed',
        ], 500);
    }

    public function destroy(Role $role): Response|JsonResponse
    {
        Gate::authorize('delete_settings_roles');
        $this->refuseSystemRole($role);

        $members = $role->users()->count();
        if ($members > 0) {
            return response()->json([
                'message' => "{$role->name} still has {$members} ".($members === 1 ? 'user' : 'users').'. Give them another role first.',
            ], 409);
        }

        $role->delete();

        return response()->noContent();
    }

    /** The page's History drawer: this workspace's roles added, renamed, deleted, and permission changes. */
    public function history(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('view_settings_roles');

        $filters = $request->validate(['per_page' => ['nullable', 'integer', 'min:10', 'max:100']]);

        return ChangeHistoryResource::collection(
            AuditLog::forWorkspace($this->current->id())
                ->forTable((new Role)->getTable())
                ->latest('timestamp')
                ->latest('id')
                ->paginate($filters['per_page'] ?? 15),
        );
    }

    private function show(Role $role): RoleResource
    {
        return RoleResource::make($role->load('permissions')->loadCount('users'));
    }

    private function refuseSystemRole(Role $role): void
    {
        abort_if($role->isSystem(), 403, 'The Owner role always has full access: it cannot be renamed, changed or deleted.');
    }
}
