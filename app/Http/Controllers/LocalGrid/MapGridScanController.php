<?php

namespace App\Http\Controllers\LocalGrid;

use App\Http\Controllers\Controller;
use App\Http\Resources\MapGridPointResource;
use App\Http\Resources\MapGridScanResource;
use App\Models\MapGridPoint;
use App\Models\MapGridScan;
use App\Services\LocalGrid\LocalGrids;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** One scan with its points and the competitors across them (the SPA polls it while running). */
class MapGridScanController extends Controller
{
    public function show(Request $request, MapGridScan $scan, LocalGrids $grids): JsonResponse
    {
        abort_if($scan->grid === null || $scan->grid->trashed(), 404);
        Gate::authorize('view', $scan->grid);

        $points = $scan->points()->orderBy('row')->orderBy('col')->get();
        $target = ['place_id' => $scan->business_place_id, 'cid' => $scan->business_cid, 'domain' => $scan->business_domain];

        return response()->json([
            'data' => [
                ...MapGridScanResource::make($scan)->resolve($request),
                'points' => $points->map(function (MapGridPoint $point) use ($target, $request) {
                    $resource = MapGridPointResource::make($point);
                    $resource->target = $target;

                    return $resource->resolve($request);
                })->values(),
            ],
            'competitors' => $grids->competitors($scan, $points),
        ]);
    }
}
