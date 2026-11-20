<?php

namespace App\Http\Controllers\LocalGrid;

use App\Exceptions\LocalGridException;
use App\Http\Controllers\Controller;
use App\Http\Requests\LocalGrid\BusinessSearchRequest;
use App\Http\Requests\LocalGrid\StoreMapGridRequest;
use App\Http\Requests\LocalGrid\UpdateMapGridRequest;
use App\Http\Resources\MapGridResource;
use App\Http\Resources\MapGridScanResource;
use App\Models\MapGrid;
use App\Models\Site;
use App\Services\LocalGrid\BusinessSearch;
use App\Services\LocalGrid\GridScanner;
use App\Services\LocalGrid\LocalGrids;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** A site's local grids: list, business search, add, show, change, delete and "scan now". */
class MapGridController extends Controller
{
    public function __construct(private readonly LocalGrids $grids, private readonly GridScanner $scanner) {}

    public function index(Site $site): AnonymousResourceCollection
    {
        Gate::authorize('viewLocalGrid', $site);

        return MapGridResource::collection($this->grids->forSite($site));
    }

    /** {data: candidates[]}: up to 10 Google Maps listings for the query. */
    public function search(BusinessSearchRequest $request, Site $site, BusinessSearch $search): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $search->search(
            $site, trim($data['query']),
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
            $request->user()->id,
        )]);
    }

    /**
     * 201 {data: Grid}. With scan_now the first scan is queued too; when it
     * can't start (paused, daily limit) the grid is still saved and
     * `scan_error` {message, error_type} says why.
     */
    public function store(StoreMapGridRequest $request, Site $site): JsonResponse
    {
        $data = $request->validated();
        $grid = $this->grids->create($site, $data, $request->user());

        $scanError = null;
        if ($data['scan_now'] ?? false) {
            try {
                $this->scanner->scanNow($grid, $request->user()->id);
            } catch (LocalGridException $e) {
                $scanError = ['message' => $e->getMessage(), 'error_type' => $e->errorType];
            }
        }

        return MapGridResource::make($this->grids->load($grid))
            ->additional($scanError ? ['scan_error' => $scanError] : [])
            ->response()->setStatusCode(201);
    }

    /** The grid with its latest scans (newest first). */
    public function show(MapGrid $grid): MapGridResource
    {
        Gate::authorize('view', $grid);
        $this->grids->load($grid)->setRelation('scans', $this->grids->scans($grid));

        return MapGridResource::make($grid);
    }

    public function update(UpdateMapGridRequest $request, MapGrid $grid): MapGridResource
    {
        $this->grids->update($grid, $request->validated());

        return MapGridResource::make($this->grids->load($grid));
    }

    /** Soft delete: its scans are kept. */
    public function destroy(MapGrid $grid): Response
    {
        Gate::authorize('delete', $grid);
        $grid->delete();

        return response()->noContent();
    }

    /** 202 {data: Scan} (queued). */
    public function scan(Request $request, MapGrid $grid): JsonResponse
    {
        Gate::authorize('update', $grid);
        $scan = $this->scanner->scanNow($grid, $request->user()->id);

        return MapGridScanResource::make($scan)->response()->setStatusCode(202);
    }
}
