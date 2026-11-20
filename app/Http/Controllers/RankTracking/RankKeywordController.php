<?php

namespace App\Http\Controllers\RankTracking;

use App\Http\Controllers\Controller;
use App\Http\Requests\RankTracking\BulkRankKeywordsRequest;
use App\Http\Requests\RankTracking\RankKeywordListRequest;
use App\Http\Requests\RankTracking\StoreRankKeywordsRequest;
use App\Http\Requests\RankTracking\UpdateRankKeywordRequest;
use App\Http\Resources\RankKeywordResource;
use App\Models\RankKeyword;
use App\Models\Site;
use App\Services\RankTracking\RankChecker;
use App\Services\RankTracking\RankKeywords;
use App\Services\RankTracking\RankReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * A site's tracked keywords: list, add, change, delete, bulk, check now and
 * one keyword's history.
 */
class RankKeywordController extends Controller
{
    public function __construct(private readonly RankKeywords $keywords, private readonly RankReports $reports) {}

    public function index(RankKeywordListRequest $request, Site $site): AnonymousResourceCollection
    {
        return RankKeywordResource::collection($this->reports->keywords($site, $request->validated(), $request->perPage()));
    }

    /** 201 {created, skipped}. */
    public function store(StoreRankKeywordsRequest $request, Site $site): JsonResponse
    {
        return response()->json($this->keywords->create($site, $request->validated(), $request->user()), 201);
    }

    public function update(UpdateRankKeywordRequest $request, RankKeyword $rankKeyword): RankKeywordResource
    {
        $this->keywords->update($rankKeyword, $request->validated());

        return RankKeywordResource::make($this->reports->row($rankKeyword));
    }

    /** Soft delete: the keyword's history stays. */
    public function destroy(RankKeyword $rankKeyword): Response
    {
        Gate::authorize('delete', $rankKeyword);
        $rankKeyword->delete();

        return response()->noContent();
    }

    public function bulk(BulkRankKeywordsRequest $request, Site $site): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['affected' => $this->keywords->bulk($site, $data['action'], $data['ids'], $data['frequency'] ?? null)]);
    }

    /** 202 + the keyword row; the live result lands as today's check. */
    public function check(RankKeyword $rankKeyword, RankChecker $checker): JsonResponse
    {
        Gate::authorize('update', $rankKeyword);
        $checker->checkNow($rankKeyword);

        return RankKeywordResource::make($this->reports->row($rankKeyword))->response()->setStatusCode(202);
    }

    public function history(Request $request, RankKeyword $rankKeyword): JsonResponse
    {
        Gate::authorize('view', $rankKeyword);
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365']])['days'] ?? 90);

        return response()->json(['data' => $this->reports->history($rankKeyword, $days)]);
    }
}
