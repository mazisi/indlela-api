<?php

namespace App\Http\Controllers\RankTracking;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\RankTracking\RankReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/** A site's rank tracking reports: summary and cannibalisation. */
class RankTrackingReportController extends Controller
{
    public function __construct(private readonly RankReports $reports) {}

    public function summary(Site $site): JsonResponse
    {
        Gate::authorize('viewRankTracking', $site);

        return response()->json(['data' => $this->reports->summary($site)]);
    }

    public function cannibalisation(Request $request, Site $site): AnonymousResourceCollection
    {
        Gate::authorize('viewRankTracking', $site);
        $perPage = (int) ($request->validate(['per_page' => ['nullable', 'integer', 'min:10', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']])['per_page'] ?? 15);

        return JsonResource::collection($this->reports->cannibalisation($site, $perPage));
    }
}
