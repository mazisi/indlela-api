<?php

namespace App\Http\Controllers\Reports;

use App\Exceptions\ReportException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\BulkReportsRequest;
use App\Http\Requests\Reports\SendReportRequest;
use App\Http\Requests\Reports\StoreReportRequest;
use App\Http\Resources\ReportResource;
use App\Models\Report;
use App\Models\Site;
use App\Services\Reports\ReportPdf;
use App\Services\Reports\ReportPresenter;
use App\Services\Reports\Reports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * A site's reports: list, generate, one report with its snapshot, the PDF,
 * email, share links and delete.
 */
class ReportController extends Controller
{
    public function __construct(private readonly Reports $reports) {}

    /** Newest first. */
    public function index(Request $request, Site $site): AnonymousResourceCollection
    {
        Gate::authorize('viewReports', $site);
        $perPage = $request->validate(['per_page' => ['nullable', 'integer', 'min:10', 'max:100']])['per_page'] ?? 15;

        return ReportResource::collection($site->reports()->with('generatedBy')->latest('id')->paginate((int) $perPage));
    }

    /** `{affected}`: reports deleted, or links revoked. */
    public function bulk(BulkReportsRequest $request, Site $site): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['affected' => $this->reports->bulk($site, $data['action'], array_map('intval', $data['ids']))]);
    }

    /** 202 + the report, generating (the page polls GET /reports/{id}). */
    public function store(StoreReportRequest $request, Site $site): JsonResponse
    {
        $data = $request->validated();
        $report = $this->reports->create($site, $data, $request->user(), Report::MANUAL, $data['send_to'] ?? []);

        return ReportResource::make($report->load('generatedBy'))->response()->setStatusCode(202);
    }

    public function show(Request $request, Report $report, ReportPresenter $presenter): JsonResponse
    {
        Gate::authorize('view', $report);

        return response()->json(['data' => $presenter->detail($report->load('generatedBy'), $request)]);
    }

    public function pdf(Report $report): Response
    {
        Gate::authorize('view', $report);

        return self::pdfResponse($report);
    }

    /** 409 report_not_ready until it has generated. */
    public function send(SendReportRequest $request, Report $report): ReportResource
    {
        $data = $request->validated();

        return ReportResource::make($this->reports->send($report, $data['recipients'], $request->user(), $data['message'] ?? null)->load('generatedBy'));
    }

    /** A new link (rotates any old one): expires_in_days 7 | 30 | 90 | null (no expiry). */
    public function share(Request $request, Report $report): ReportResource
    {
        Gate::authorize('create', $report);
        $days = $request->validate(['expires_in_days' => ['nullable', 'integer', Rule::in(config('indlela.reports.share_days'))]])['expires_in_days'] ?? null;

        return ReportResource::make($this->reports->share($report, $days === null ? null : (int) $days)->load('generatedBy'));
    }

    public function unshare(Report $report): ReportResource
    {
        Gate::authorize('create', $report);

        return ReportResource::make($this->reports->revokeShare($report)->load('generatedBy'));
    }

    public function destroy(Report $report): Response
    {
        Gate::authorize('delete', $report);
        $this->reports->delete($report);

        return response()->noContent();
    }

    /** The stored PDF as a download; 409 report_not_ready while it's missing. */
    public static function pdfResponse(Report $report): Response
    {
        $bytes = $report->isReady() && $report->pdf_path ? Storage::get($report->pdf_path) : null;
        if (! is_string($bytes) || $bytes === '') {
            throw ReportException::notReady();
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.ReportPdf::fileName($report).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
