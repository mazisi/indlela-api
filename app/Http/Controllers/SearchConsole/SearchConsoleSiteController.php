<?php

namespace App\Http\Controllers\SearchConsole;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchConsole\LinkSearchConsoleRequest;
use App\Http\Resources\SearchConsoleSiteResource;
use App\Models\Site;
use App\Services\SearchConsole\SearchConsoleSites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Sites and their Search Console properties: list, link, unlink, sync now.
 */
class SearchConsoleSiteController extends Controller
{
    public function __construct(private readonly SearchConsoleSites $sites) {}

    /** Every site the user can access (not paginated: the page's site picker). */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('view_search_console');

        $sites = Site::query()->accessibleBy($request->user())
            ->with('client')
            ->join('clients', 'clients.id', '=', 'sites.client_id')
            ->orderBy('clients.name')->orderBy('sites.name')
            ->select('sites.*')
            ->get();

        return SearchConsoleSiteResource::collection($sites);
    }

    /** Link a property and queue the 16-month backfill. */
    public function update(LinkSearchConsoleRequest $request, Site $site): SearchConsoleSiteResource
    {
        return SearchConsoleSiteResource::make($this->sites->link($site, $request->validated('property'))->load('client'));
    }

    /** Unlink; the site's Search Console data is deleted. */
    public function destroy(Site $site): Response
    {
        Gate::authorize('manageSearchConsole', $site);
        $this->sites->unlink($site);

        return response()->noContent();
    }

    public function sync(Site $site): JsonResponse
    {
        Gate::authorize('manageSearchConsole', $site);

        return SearchConsoleSiteResource::make($this->sites->queueSync($site)->load('client'))->response()->setStatusCode(202);
    }
}
