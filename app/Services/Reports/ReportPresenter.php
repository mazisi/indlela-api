<?php

namespace App\Services\Reports;

use App\Http\Resources\ReportResource;
use App\Models\Report;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * A report's snapshot as the app and the public share page receive it. The
 * snapshot's `branding` (it holds the logo's storage path) is replaced by
 * `{name, logo_url, brand_color}`; the logo is served by a route, never the
 * storage URL.
 */
class ReportPresenter
{
    /** GET /reports/{report}: the row + the snapshot + branding (logo: a signed URL, 1 hour). */
    public function detail(Report $report, Request $request): array
    {
        $hasLogo = $this->logoPath($report) !== null;

        return ReportResource::make($report)->resolve($request) + [
            'data' => $this->data($report),
            'branding' => $this->branding($report, $hasLogo ? URL::temporarySignedRoute('reports.logo', now()->addHour(), ['report' => $report->id]) : null),
        ];
    }

    /** GET /public/reports/{token}: the snapshot only (no ids, emails or storage paths). */
    public function public(Report $report): array
    {
        $hasLogo = $this->logoPath($report) !== null;

        return [
            'title' => $report->title,
            'period_label' => $report->data['period']['label'] ?? null,
            'summary' => $report->summary ?? [],
            'note' => $report->note,
            'data' => $this->data($report),
            'branding' => $this->branding($report, $hasLogo ? route('public.reports.logo', ['token' => $report->share_token]) : null),
            'generated_at' => $report->updated_at?->toIso8601String(),
        ];
    }

    /** The logo to show: the snapshot's, else (still generating) the workspace's current one. */
    public function logoPath(Report $report): ?string
    {
        if ($report->data !== null) {
            return $report->data['branding']['logo'] ?? null;
        }

        return Workspace::query()->whereKey($report->workspace_id)->value('logo_path');
    }

    /** @return array<string, mixed>|null */
    private function data(Report $report): ?array
    {
        if ($report->data === null) {
            return null;
        }
        $data = $report->data;
        unset($data['branding']);

        return $data;
    }

    /** @return array{name: string, logo_url: ?string, brand_color: string} */
    private function branding(Report $report, ?string $logoUrl): array
    {
        $branding = $report->data['branding'] ?? null;
        if ($branding === null) {
            $workspace = Workspace::query()->find($report->workspace_id);
            $branding = ['name' => $workspace?->name ?? '', 'brand_color' => $workspace?->brandColor()];
        }

        return ['name' => (string) $branding['name'], 'logo_url' => $logoUrl, 'brand_color' => ReportPdf::safeColor($branding['brand_color'] ?? null)];
    }
}
