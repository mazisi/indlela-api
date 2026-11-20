<?php

namespace App\Http\Controllers\RankTracking;

use App\Http\Controllers\Controller;
use App\Http\Requests\RankTracking\CompetitorKeywordsRequest;
use App\Http\Requests\RankTracking\CompetitorSettingsRequest;
use App\Http\Requests\RankTracking\StoreCompetitorRequest;
use App\Http\Requests\RankTracking\UpdateCompetitorRequest;
use App\Http\Resources\CompetitorResource;
use App\Models\Site;
use App\Models\SiteCompetitor;
use App\Services\RankTracking\CompetitorReports;
use App\Services\RankTracking\Competitors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * A site's competitors (rank tracking's Competitors tab): list, suggestions,
 * add, change, remove, share of voice, head-to-head, alerts and the alert
 * emails' recipients.
 */
class CompetitorController extends Controller
{
    public function __construct(private readonly Competitors $competitors, private readonly CompetitorReports $reports) {}

    public function index(Site $site): AnonymousResourceCollection
    {
        Gate::authorize('viewRankTracking', $site);

        return CompetitorResource::collection($this->reports->competitors($site));
    }

    public function suggestions(Site $site): JsonResponse
    {
        Gate::authorize('viewRankTracking', $site);

        return response()->json(['data' => $this->reports->suggestions($site)]);
    }

    /** 201 {data: Competitor}; its positions are filled in from the site's recent checks by a queued job. */
    public function store(StoreCompetitorRequest $request, Site $site): JsonResponse
    {
        $competitor = $this->competitors->create($site, $request->competitor(), $request->user());

        return CompetitorResource::make($this->row($site, $competitor))->response()->setStatusCode(201);
    }

    public function update(UpdateCompetitorRequest $request, SiteCompetitor $competitor): CompetitorResource
    {
        $this->competitors->update($competitor, $request->validated());

        return CompetitorResource::make($this->row($competitor->site, $competitor));
    }

    /** Soft delete: its positions and alerts are kept (undo brings it back). */
    public function destroy(SiteCompetitor $competitor): Response
    {
        Gate::authorize('delete', $competitor);
        $competitor->delete();

        return response()->noContent();
    }

    public function overview(Site $site): JsonResponse
    {
        Gate::authorize('viewRankTracking', $site);

        return response()->json(['data' => $this->reports->overview($site)]);
    }

    public function keywords(CompetitorKeywordsRequest $request, Site $site): AnonymousResourceCollection
    {
        return JsonResource::collection($this->reports->keywords($site, $request->validated(), $request->perPage()));
    }

    public function alerts(Request $request, Site $site): AnonymousResourceCollection
    {
        Gate::authorize('viewRankTracking', $site);
        $perPage = (int) ($request->validate(['per_page' => ['nullable', 'integer', 'min:10', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']])['per_page'] ?? 15);

        return JsonResource::collection($this->reports->alerts($site, $perPage));
    }

    public function settings(Site $site): JsonResponse
    {
        Gate::authorize('viewRankTracking', $site);

        return response()->json(['data' => self::settingsData($this->competitors->settings($site)->alert_recipients)]);
    }

    public function updateSettings(CompetitorSettingsRequest $request, Site $site): JsonResponse
    {
        $settings = $this->competitors->saveSettings($site, $request->validated('alert_recipients'));

        return response()->json(['data' => self::settingsData($settings->alert_recipients)]);
    }

    /** The competitor as listed (with its figures). */
    private function row(Site $site, SiteCompetitor $competitor): SiteCompetitor
    {
        return $this->reports->competitors($site)->firstWhere('id', $competitor->id) ?? $competitor;
    }

    private static function settingsData(?array $recipients): array
    {
        return ['alert_recipients' => array_values($recipients ?? [])];
    }
}
