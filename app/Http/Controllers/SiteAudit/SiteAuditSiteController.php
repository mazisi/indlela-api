<?php

namespace App\Http\Controllers\SiteAudit;

use App\Http\Controllers\Controller;
use App\Http\Requests\SiteAudit\AuditSettingsRequest;
use App\Http\Resources\SiteAuditSiteResource;
use App\Models\Site;
use App\Services\SiteAudit\SiteAudits;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Sites as the Site audit page lists them, and their audit settings.
 */
class SiteAuditSiteController extends Controller
{
    private const RELATIONS = ['client', 'latestAudit.triggeredBy', 'runningAudit.triggeredBy'];

    public function __construct(private readonly SiteAudits $audits) {}

    /** Every site the user can access, by client then name (not paginated: the page's site picker). */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('view_site_audit');

        $sites = Site::query()->accessibleBy($request->user())
            ->with(self::RELATIONS)
            ->join('clients', 'clients.id', '=', 'sites.client_id')
            ->orderBy('clients.name')->orderBy('sites.name')
            ->select('sites.*')
            ->get();

        return SiteAuditSiteResource::collection($sites);
    }

    public function updateSettings(AuditSettingsRequest $request, Site $site): SiteAuditSiteResource
    {
        $site = $this->audits->updateSettings($site, $request->validated());

        return SiteAuditSiteResource::make($site->load(self::RELATIONS));
    }
}
