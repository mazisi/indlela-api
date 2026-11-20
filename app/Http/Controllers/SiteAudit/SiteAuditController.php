<?php

namespace App\Http\Controllers\SiteAudit;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteAuditResource;
use App\Models\Site;
use App\Models\SiteAudit;
use App\Services\SiteAudit\SiteAudits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * A site's audits: history, run now, one audit's summary (the page polls it
 * while it runs), cancel.
 */
class SiteAuditController extends Controller
{
    public function __construct(private readonly SiteAudits $audits) {}

    /** Newest first. */
    public function index(Request $request, Site $site): AnonymousResourceCollection
    {
        Gate::authorize('viewSiteAudit', $site);
        $perPage = $request->validate(['per_page' => ['nullable', 'integer', 'min:10', 'max:100']])['per_page'] ?? 15;

        return SiteAuditResource::collection($site->audits()->with('triggeredBy')->latest('id')->paginate((int) $perPage));
    }

    /** 202 + the queued audit; 409 audit_running when one is already queued or running. */
    public function store(Request $request, Site $site): JsonResponse
    {
        Gate::authorize('manageSiteAudit', $site);

        $audit = $this->audits->start($site, $request->user());

        return SiteAuditResource::make($audit->load('triggeredBy'))->response()->setStatusCode(202);
    }

    public function show(SiteAudit $audit): SiteAuditResource
    {
        Gate::authorize('view', $audit);

        return SiteAuditResource::make($audit->load('triggeredBy'));
    }

    /** 409 audit_not_running once it has finished. */
    public function cancel(SiteAudit $audit): SiteAuditResource
    {
        Gate::authorize('update', $audit);

        return SiteAuditResource::make($this->audits->cancel($audit)->load('triggeredBy'));
    }
}
