<?php

namespace App\Http\Controllers\Users;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\BulkUserRequest;
use App\Http\Requests\Users\UserRequest;
use App\Http\Resources\ChangeHistoryResource;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Services\Users\UserManagementService;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Settings › Users and access (`*_settings_users`; Owners): the members of
 * the current workspace. `{user}` only binds members (AppServiceProvider).
 */
class UserController extends Controller
{
    public function __construct(
        private readonly UserManagementService $users,
        private readonly CurrentWorkspace $current,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('view_settings_users');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $members = fn () => User::inWorkspace($this->current->id());

        $users = $members()
            ->with(['roles', 'assignedClients'])
            ->withCount(['workspaces as other_workspaces_count' => fn (Builder $q) => $q->whereKeyNot($this->current->id())])
            ->when($filters['search'] ?? null, function ($q, string $search) {
                // Name, email or role, case-insensitive; the user's own % and _ are literal.
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
                $q->where(fn ($q) => $q
                    ->whereRaw("LOWER(full_name || ' ' || COALESCE(surname, '')) LIKE ?", [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                    ->orWhereHas('roles', fn ($r) => $r->whereRaw('LOWER(name) LIKE ?', [$like])));
            })
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->orderBy('full_name')
            ->orderBy('surname')
            ->paginate($filters['per_page'] ?? 15);

        // Counts for the status tabs (ignore the status filter, keep nothing else).
        $counts = $members()->toBase()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        return UserResource::collection($users)->additional([
            'summary' => [
                'all' => (int) $counts->sum(),
                ...collect(UserStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($counts[$s->value] ?? 0)])->all(),
            ],
        ]);
    }

    /** The workspace's roles (Owner first) and active clients for the add/edit forms. */
    public function options(): JsonResponse
    {
        Gate::authorize('view_settings_users');

        return response()->json([
            'roles' => Role::inCurrentWorkspace()->orderBy('name')->get()
                ->map(fn (Role $r) => [
                    'id' => $r->id, 'name' => $r->name, 'description' => $r->description,
                    'is_system' => $r->isSystem(), 'all_clients' => $r->isSystem() || $r->all_clients,
                ])
                ->sortBy(fn ($r) => [$r['is_system'] ? 0 : 1, $r['name']])->values(),
            'clients' => Client::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(UserRequest $request): JsonResponse
    {
        $user = $this->users->create($request->validated(), $request->user());

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    public function update(UserRequest $request, User $user): UserResource
    {
        return UserResource::make($this->users->update($user, $request->validated(), $request->user()));
    }

    /** Removes the user from this workspace (their account goes only if it was their last). */
    public function destroy(Request $request, User $user): Response
    {
        Gate::authorize('delete_settings_users');

        $this->users->delete($user, $request->user());

        return response()->noContent();
    }

    /** Enable, disable or remove several users; all or nothing. */
    public function bulk(BulkUserRequest $request): JsonResponse
    {
        ['action' => $action, 'ids' => $ids] = $request->validated();
        $users = User::inWorkspace($this->current->id())->whereKey($ids)->get();

        if ($users->count() !== count($ids)) {
            return response()->json([
                'message' => 'Some of the selected users are no longer in this workspace. Refresh and try again.',
                'errors' => ['ids' => ['Some of the selected users are no longer in this workspace.']],
            ], 422);
        }

        $this->users->bulk($action, $users, $request->user());

        return response()->json(['affected' => $users->count()]);
    }

    /** The page's History drawer: users added, changed and removed in this workspace. */
    public function history(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('view_settings_users');

        $filters = $request->validate(['per_page' => ['nullable', 'integer', 'min:10', 'max:100']]);

        return ChangeHistoryResource::collection(
            AuditLog::forWorkspace($this->current->id())
                ->forTable((new User)->getTable())
                ->latest('timestamp')
                ->latest('id')
                ->paginate($filters['per_page'] ?? 15),
        );
    }
}
