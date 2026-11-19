<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Concerns\SettingsList;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\SiteRequest;
use App\Http\Requests\Settings\BulkActiveRequest;
use App\Http\Resources\SiteResource;
use App\Models\Client;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Sites (`*_clients_sites`): the websites Search Console, the site audit and
 * rank tracking attach to. The current workspace's sites of the clients the
 * user may access; SitePolicy checks permission, workspace and client access.
 */
class SiteController extends Controller
{
    use SettingsList;

    private const SORTABLE = [
        'name' => 'sites.name',
        'domain' => 'sites.domain',
        'created_at' => 'sites.created_at',
        'status' => 'sites.is_active',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'sort' => ['nullable', Rule::in(array_keys(self::SORTABLE))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $client = isset($filters['client_id']) ? Client::findOrFail($filters['client_id']) : null;
        Gate::authorize('viewAny', [Site::class, $client]);

        $visible = fn () => Site::query()->accessibleBy($request->user())
            ->when($client, fn ($q) => $q->where('client_id', $client->id));

        $sites = $visible()
            ->with('client')
            ->when($filters['search'] ?? null, fn ($q, string $search) => $q->search($search))
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('is_active', $status === 'active'))
            ->tap(fn (Builder $q) => ($filters['sort'] ?? null)
                ? $this->sortList($q, self::SORTABLE, $filters['sort'], $filters['direction'] ?? 'asc', 'sites.name', ['sites.is_active'])
                : $q->orderByDesc('is_active')->orderBy('name'))
            ->paginate($filters['per_page'] ?? 15);

        return SiteResource::collection($sites)->additional([
            'summary' => [
                'active' => $visible()->active()->count(),
                'total' => $visible()->count(),
            ],
        ]);
    }

    public function show(Site $site): SiteResource
    {
        Gate::authorize('view', $site);

        return SiteResource::make($site->load('client'));
    }

    // SiteRequest authorises store and update (before its rules run).
    public function store(SiteRequest $request): JsonResponse
    {
        $site = Site::create($request->siteData());

        return SiteResource::make($site->load('client'))->response()->setStatusCode(201);
    }

    public function update(SiteRequest $request, Site $site): SiteResource
    {
        $site->update($request->siteData());

        return SiteResource::make($site->load('client'));
    }

    public function destroy(Site $site): Response
    {
        Gate::authorize('delete', $site);

        $site->delete();

        return response()->noContent();
    }

    /** Activate, deactivate or delete several sites; all or nothing (403 if any is out of reach). */
    public function bulk(BulkActiveRequest $request): JsonResponse
    {
        return $this->bulkActive($request, Site::query(), 'sites');
    }

    /** The page's History drawer: changes to the sites of the clients this user can access. */
    public function history(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Site::class);
        $perPage = $request->validate(['per_page' => ['nullable', 'integer', 'min:10', 'max:100']])['per_page'] ?? 15;
        $ids = $request->user()->accessibleClientIds();

        return $this->historyOf(['sites'], $perPage, $ids === null ? null : Site::withTrashed()->whereIn('client_id', $ids));
    }
}
