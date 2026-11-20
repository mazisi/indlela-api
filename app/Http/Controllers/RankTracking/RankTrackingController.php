<?php

namespace App\Http\Controllers\RankTracking;

use App\Http\Controllers\Controller;
use App\Http\Resources\RankTrackingSiteResource;
use App\Models\Site;
use App\Services\RankTracking\RankLocations;
use App\Services\RankTracking\RankReports;
use App\Services\RankTracking\RankUsage;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The Rank tracking page's workspace-level data: sites with their figures,
 * the location search and the workspace's usage against its limits.
 */
class RankTrackingController extends Controller
{
    /** Every site the user can access, by client then name (not paginated: the page's site picker). */
    public function sites(Request $request, RankReports $reports): JsonResponse
    {
        Gate::authorize('view_rank_tracking');

        $sites = Site::query()->accessibleBy($request->user())
            ->with('client')
            ->join('clients', 'clients.id', '=', 'sites.client_id')
            ->orderBy('clients.name')->orderBy('sites.name')
            ->select('sites.*')
            ->get();
        $figures = $reports->siteFigures($sites->pluck('id')->all());

        return response()->json(['data' => $sites->map(function (Site $site) use ($figures, $request) {
            $resource = RankTrackingSiteResource::make($site);
            $resource->figures = $figures->get($site->id, []);

            return $resource->resolve($request);
        })->values()]);
    }

    /** 409 rank_checks_paused while the platform's rank checks aren't set up (the list comes from DataForSEO). */
    public function locations(Request $request, RankLocations $locations): JsonResponse
    {
        Gate::authorize('view_rank_tracking');
        $search = $request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? null;

        return response()->json(['data' => $locations->search($search)]);
    }

    /** The workspace's keyword and check-now limits and whether rank checks are paused (never money). */
    public function usage(RankUsage $usage, CurrentWorkspace $workspace): JsonResponse
    {
        Gate::authorize('view_rank_tracking');

        return response()->json(['data' => $usage->summary($workspace->id())]);
    }
}
