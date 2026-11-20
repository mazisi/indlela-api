<?php

namespace App\Http\Controllers\SiteAudit;

use App\Http\Controllers\Controller;
use App\Http\Requests\SiteAudit\AuditListRequest;
use App\Http\Resources\SiteAuditPageResource;
use App\Models\SiteAudit;
use App\Models\SiteAuditPage;
use App\Services\SiteAudit\AuditReports;
use App\Support\AuditIssues;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * One audit's results: issues by type, an issue type's URLs, pages, a page,
 * Core Web Vitals and the comparison with the previous audit.
 */
class SiteAuditReportController extends Controller
{
    public function __construct(private readonly AuditReports $reports) {}

    public function issues(SiteAudit $audit): JsonResponse
    {
        Gate::authorize('view', $audit);

        return response()->json(['data' => $this->reports->issues($audit)]);
    }

    /** 404 for a type not in the catalogue. */
    public function issueUrls(AuditListRequest $request, SiteAudit $audit, string $type): AnonymousResourceCollection
    {
        abort_unless(AuditIssues::exists($type), 404, 'Unknown issue type.');

        return JsonResource::collection($this->reports->issueUrls($audit, $type, $request->like(), $request->perPage()))
            ->additional(['issue' => AuditIssues::describe($type)]);
    }

    public function pages(AuditListRequest $request, SiteAudit $audit): AnonymousResourceCollection
    {
        return SiteAuditPageResource::collection($this->reports->pages($audit, $request->validated(), $request->like(), $request->perPage()));
    }

    // {page} is bound through $audit->pages() (scoped binding): another audit's page 404s.
    public function page(SiteAudit $audit, SiteAuditPage $page): JsonResponse
    {
        Gate::authorize('view', $audit);

        $resource = SiteAuditPageResource::make($page->loadCount('issues'));
        $resource->detail = true;

        return response()->json(['data' => [...$resource->resolve(request()), ...$this->reports->pageDetail($audit, $page)]]);
    }

    public function vitals(SiteAudit $audit): JsonResponse
    {
        Gate::authorize('view', $audit);

        return response()->json(['data' => $this->reports->vitals($audit)]);
    }

    public function comparison(AuditListRequest $request, SiteAudit $audit): AnonymousResourceCollection
    {
        $filter = $request->validated('filter') ?? 'new';

        return JsonResource::collection($this->reports->comparison($audit, $filter, $request->like(), $request->perPage()))
            ->additional(['filter' => $filter, 'comparison' => $audit->comparison]);
    }
}
