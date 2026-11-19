<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Concerns\SettingsList;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\ClientRequest;
use App\Http\Requests\Settings\BulkActiveRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Clients (`*_clients_clients`). The current workspace's clients (Client's
 * global scope), and of those only the ones the user may access; ClientPolicy
 * checks permission, workspace and access together. Inactive clients stay on
 * their records; deleting is a soft delete, so history remains.
 */
class ClientController extends Controller
{
    use SettingsList;

    /** Sortable columns: request `sort` key → column. Status sorts Active first when ascending. */
    private const SORTABLE = [
        'name' => 'name',
        'contact_name' => 'contact_name',
        'created_at' => 'created_at',
        'status' => 'is_active',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Client::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'sort' => ['nullable', Rule::in(array_keys(self::SORTABLE))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $visible = fn () => Client::query()->accessibleBy($request->user());

        $clients = $visible()
            ->withCount('sites')
            ->when($filters['search'] ?? null, fn ($q, string $search) => $q->search($search))
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('is_active', $status === 'active'))
            ->tap(fn (Builder $q) => ($filters['sort'] ?? null)
                ? $this->sortList($q, self::SORTABLE, $filters['sort'], $filters['direction'] ?? 'asc', 'name')
                : $q->orderByDesc('is_active')->orderBy('name'))
            ->paginate($filters['per_page'] ?? 15);

        return ClientResource::collection($clients)->additional([
            'summary' => [
                'active' => $visible()->active()->count(),
                'total' => $visible()->count(),
            ],
        ]);
    }

    public function show(Client $client): ClientResource
    {
        Gate::authorize('view', $client);

        return ClientResource::make($client->loadCount('sites'));
    }

    // ClientRequest authorises store and update (before its rules run).
    public function store(ClientRequest $request): JsonResponse
    {
        $client = Client::create($request->validated());

        return ClientResource::make($client->loadCount('sites'))->response()->setStatusCode(201);
    }

    public function update(ClientRequest $request, Client $client): ClientResource
    {
        $client->update($request->validated());

        return ClientResource::make($client->loadCount('sites'));
    }

    /** A client with sites stays (409) until its sites are deleted; it can be made inactive. */
    public function destroy(Client $client): Response|JsonResponse
    {
        Gate::authorize('delete', $client);

        if ($message = $this->hasSites(collect([$client]))) {
            return response()->json(['message' => $message], 409);
        }

        $client->delete();

        return response()->noContent();
    }

    /** Activate, deactivate or delete several clients; all or nothing (403 if any is out of reach). */
    public function bulk(BulkActiveRequest $request): JsonResponse
    {
        return $this->bulkActive($request, Client::query(), 'clients', fn (Collection $rows) => $this->hasSites($rows));
    }

    /** The page's History drawer: changes to the clients this user can access. */
    public function history(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Client::class);
        $perPage = $request->validate(['per_page' => ['nullable', 'integer', 'min:10', 'max:100']])['per_page'] ?? 15;
        $ids = $request->user()->accessibleClientIds();

        return $this->historyOf(['clients'], $perPage, $ids === null ? null : Client::withTrashed()->whereKey($ids));
    }

    private function hasSites(Collection $clients): ?string
    {
        $client = Client::whereKey($clients->pluck('id')->all())->whereHas('sites')->orderBy('name')->first();

        return $client ? "{$client->name} has sites, so it can't be deleted. Delete its sites first, or make it inactive." : null;
    }
}
