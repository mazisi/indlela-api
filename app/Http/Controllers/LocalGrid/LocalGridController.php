<?php

namespace App\Http\Controllers\LocalGrid;

use App\Http\Controllers\Controller;
use App\Http\Resources\LocalGridSiteResource;
use App\Services\LocalGrid\LocalGrids;
use App\Services\LocalGrid\LocalGridUsage;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** The Local grid page's workspace-level data: sites with their latest scan, and usage. */
class LocalGridController extends Controller
{
    /** Every site the user can access, by client then name (not paginated). */
    public function sites(Request $request, LocalGrids $grids): AnonymousResourceCollection
    {
        Gate::authorize('view_local_grid');

        return LocalGridSiteResource::collection($grids->sites($request->user()));
    }

    /** The workspace's grid, scan and search allowances and whether scans are paused (never money). */
    public function usage(LocalGridUsage $usage, CurrentWorkspace $workspace): JsonResponse
    {
        Gate::authorize('view_local_grid');

        return response()->json(['data' => $usage->summary($workspace->id())]);
    }
}
