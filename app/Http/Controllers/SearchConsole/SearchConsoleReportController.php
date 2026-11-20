<?php

namespace App\Http\Controllers\SearchConsole;

use App\Exceptions\SearchConsoleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SearchConsole\SearchConsoleReportRequest;
use App\Http\Resources\SearchConsoleSiteResource;
use App\Models\Site;
use App\Services\SearchConsole\ReportPeriod;
use App\Services\SearchConsole\SearchConsoleReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * A site's Search Console reports. 409 gsc_not_linked for a site without a
 * property; a linked site without data yet answers 200 with empty results,
 * and every report carries `sync` so the page can show "Syncing…".
 */
class SearchConsoleReportController extends Controller
{
    public function __construct(private readonly SearchConsoleReports $reports) {}

    // `Site $site` in each signature binds the route's {site} before the request authorises it.
    public function overview(SearchConsoleReportRequest $request, Site $site): JsonResponse
    {
        $this->ensureLinked($site);

        return response()->json([
            ...$this->reports->overview($site, $request->period()),
            'sync' => SearchConsoleSiteResource::syncState($site),
        ]);
    }

    public function queries(SearchConsoleReportRequest $request, Site $site): AnonymousResourceCollection
    {
        $this->ensureLinked($site);
        $period = $request->period();

        return $this->page($this->reports->dimension($site, 'query', $period, $request->options()), $site, $period);
    }

    public function pages(SearchConsoleReportRequest $request, Site $site): AnonymousResourceCollection
    {
        $this->ensureLinked($site);
        $period = $request->period();

        return $this->page($this->reports->dimension($site, 'page', $period, $request->options()), $site, $period);
    }

    public function quickWins(SearchConsoleReportRequest $request, Site $site): AnonymousResourceCollection
    {
        $this->ensureLinked($site);
        $period = $request->period();

        return $this->page($this->reports->quickWins($site, $period, $request->options()), $site, $period);
    }

    public function lowCtr(SearchConsoleReportRequest $request, Site $site): AnonymousResourceCollection
    {
        $this->ensureLinked($site);
        $period = $request->period();

        return $this->page($this->reports->lowCtr($site, $period, $request->options()), $site, $period);
    }

    /** Based on the site's latest data, not start/end. */
    public function contentDecay(SearchConsoleReportRequest $request, Site $site): AnonymousResourceCollection
    {
        $this->ensureLinked($site);

        return $this->page($this->reports->contentDecay($site, $request->options()), $site);
    }

    private function ensureLinked(Site $site): void
    {
        if (! $site->hasSearchConsole()) {
            throw SearchConsoleException::notLinked();
        }
    }

    private function page(LengthAwarePaginator $rows, Site $site, ?ReportPeriod $period = null): AnonymousResourceCollection
    {
        return JsonResource::collection($rows)->additional([
            ...($period?->toArray() ?? []),
            'sync' => SearchConsoleSiteResource::syncState($site),
        ]);
    }
}
