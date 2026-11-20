<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportScheduleRequest;
use App\Http\Resources\ReportResource;
use App\Http\Resources\ReportScheduleResource;
use App\Models\Site;
use App\Services\Reports\Reports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Sites as the Reports page lists them (with their latest report and
 * schedule), and a site's monthly schedule.
 */
class ReportSiteController extends Controller
{
    /** Every site the user can access, by client then name (not paginated: the page's site picker). */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('view_reports');

        $sites = Site::query()->accessibleBy($request->user())
            ->with(['client', 'latestReport.generatedBy', 'reportSchedule'])
            ->join('clients', 'clients.id', '=', 'sites.client_id')
            ->orderBy('clients.name')->orderBy('sites.name')
            ->select('sites.*')
            ->get();

        return response()->json(['data' => $sites->map(fn (Site $site) => [
            'id' => $site->id,
            'name' => $site->name,
            'url' => $site->url,
            'client' => ['id' => $site->client->id, 'name' => $site->client->name],
            'last_report' => $site->latestReport ? ReportResource::make($site->latestReport)->resolve($request) : null,
            'schedule' => $site->reportSchedule ? ReportScheduleResource::make($site->reportSchedule)->resolve($request) : null,
        ])->values()]);
    }

    public function schedule(Site $site): JsonResponse
    {
        Gate::authorize('viewReports', $site);
        $schedule = $site->reportSchedule;

        return response()->json(['data' => $schedule ? ReportScheduleResource::make($schedule)->resolve() : null]);
    }

    /** Creates or replaces the schedule; 200 either way. */
    public function updateSchedule(ReportScheduleRequest $request, Site $site, Reports $reports): JsonResponse
    {
        return ReportScheduleResource::make($reports->saveSchedule($site, $request->validated()))->response()->setStatusCode(200);
    }

    /** "Send now": last month's report, emailed to the schedule's recipients once generated; 409 no_schedule without one. */
    public function sendNow(Request $request, Site $site, Reports $reports): JsonResponse
    {
        Gate::authorize('createReports', $site);

        return ReportResource::make($reports->sendScheduleNow($site, $request->user()))->response()->setStatusCode(202);
    }
}
