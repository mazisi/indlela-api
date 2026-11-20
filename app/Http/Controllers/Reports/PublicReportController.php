<?php

namespace App\Http\Controllers\Reports;

use App\Exceptions\ReportException;
use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\Workspace;
use App\Services\Reports\ReportPdf;
use App\Services\Reports\ReportPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * The share page's data (no sign-in): `/public/reports/{token}`, its PDF and
 * the agency's logo, and the logo behind the app's signed `logo_url`. An
 * unknown, revoked or expired token, or a report not ready, answers the same
 * 404 (nothing to tell them apart). Responses carry no ids, emails or
 * storage paths, and X-Robots-Tag: noindex (NoIndex middleware).
 */
class PublicReportController extends Controller
{
    public function show(string $token, ReportPresenter $presenter): JsonResponse
    {
        return response()->json(['data' => $presenter->public($this->find($token))]);
    }

    public function pdf(string $token): Response
    {
        $report = $this->find($token);
        try {
            return ReportController::pdfResponse($report);
        } catch (ReportException) {
            $this->notFound();
        }
    }

    public function logo(string $token, ReportPresenter $presenter): Response
    {
        return $this->logoResponse($this->find($token), $presenter);
    }

    /** GET /public/report-logos/{report} behind a temporary signed URL (GET /reports/{report}'s branding.logo_url). */
    public function signedLogo(Report $report, ReportPresenter $presenter): Response
    {
        return $this->logoResponse($report, $presenter);
    }

    private function logoResponse(Report $report, ReportPresenter $presenter): Response
    {
        // A logo replaced since the report was generated is gone from storage: show the current one.
        $image = ReportPdf::logoImage($presenter->logoPath($report))
            ?? ReportPdf::logoImage(Workspace::query()->whereKey($report->workspace_id)->value('logo_path'));
        if ($image === null) {
            abort(404);
        }

        return response($image['bytes'], 200, [
            'Content-Type' => $image['mime'],
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            // An SVG logo opened on its own can't run script.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    private function find(string $token): Report
    {
        $report = strlen($token) === 40
            ? Report::query()->withoutGlobalScope('workspace')->where('share_token', $token)->where('status', Report::READY)->first()
            : null;
        if (! $report || ! $report->isShared()) {
            $this->notFound();
        }

        return $report;
    }

    private function notFound(): never
    {
        abort(response()->json(['message' => 'This report link is not valid or has expired.'], 404));
    }
}
