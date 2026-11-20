<?php

namespace App\Services\Reports;

use App\Models\Report;
use App\Models\Workspace;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Renders a report's PDF (dompdf, A4) from its snapshot: the agency's logo,
 * name and colour, never Indlela's. View: resources/views/reports/pdf.blade.php.
 */
class ReportPdf
{
    /** The PDF's bytes. */
    public function render(Report $report): string
    {
        $pdf = Pdf::loadView('reports.pdf', $this->viewData($report))->setPaper('a4')
            // Embed only the glyphs used (DejaVu in full is ~800 KB a PDF).
            ->setOption('isFontSubsettingEnabled', true);
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();

        // "Page X of Y" bottom right of every page (CSS counter(pages) isn't supported).
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $text = 'Page {PAGE_NUM} of {PAGE_COUNT}';
        $size = 7;
        $width = $dompdf->getFontMetrics()->getTextWidth('Page 10 of 10', $font, $size);
        // A4 = 595 × 842 pt; the footer sits in the 20 mm bottom margin, 16 mm from the right edge.
        $canvas->page_text($canvas->get_width() - 45.35 - $width, $canvas->get_height() - 40, $text, $font, $size, [0.42, 0.45, 0.5]);

        return $dompdf->output();
    }

    /** @return array<string, mixed> */
    public function viewData(Report $report): array
    {
        $data = $report->data ?? [];
        $color = self::safeColor($data['branding']['brand_color'] ?? null);
        $gsc = $data['search_console'] ?? null;
        $rank = $data['rank_tracking'] ?? null;
        $audit = $data['site_audit'] ?? null;

        return [
            'report' => $report,
            'data' => $data,
            'summary' => $report->summary ?? [],
            'color' => $color,
            'tint' => self::tint($color),
            'logo' => self::logoDataUri($data['branding']['logo'] ?? null),
            'charts' => [
                'clicks' => ($gsc['available'] ?? false) ? ReportCharts::dataUri(ReportCharts::line(array_column($gsc['series'], 'clicks'), $color)) : null,
                'distribution' => ($rank['available'] ?? false) ? ReportCharts::dataUri(ReportCharts::bars($rank['distribution'], $rank['distribution_start'], $color)) : null,
                'health' => ($audit['available'] ?? false) && $audit['health_score'] !== null ? ReportCharts::dataUri(ReportCharts::gauge((int) $audit['health_score'], $color)) : null,
            ],
        ];
    }

    /** "Acme Coffee · SEO report · October 2026" → "acme-coffee-seo-report-october-2026.pdf". */
    public static function fileName(Report $report): string
    {
        return (Str::slug($report->title) ?: 'report').'.pdf';
    }

    public static function safeColor(?string $color): string
    {
        return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : Workspace::DEFAULT_BRAND_COLOR;
    }

    /** The accent mixed with white (backgrounds). */
    public static function tint(string $color, float $amount = 0.92): string
    {
        $rgb = sscanf($color, '#%02x%02x%02x');

        return sprintf('#%02x%02x%02x', ...array_map(fn (int $c) => (int) round($c + (255 - $c) * $amount), $rgb));
    }

    /** The logo as a data URI from the default disk; null when missing or unreadable (the header shows the name only). */
    public static function logoDataUri(?string $path): ?string
    {
        $image = self::logoImage($path);

        return $image ? "data:{$image['mime']};base64,".base64_encode($image['bytes']) : null;
    }

    /**
     * The logo's bytes and type from the default disk (WebP re-encoded as
     * PNG: dompdf and many mail clients can't show it); null when missing,
     * unreadable or not an image type we know.
     *
     * @return array{bytes: string, mime: string}|null
     */
    public static function logoImage(?string $path): ?array
    {
        if (! $path) {
            return null;
        }

        try {
            $bytes = Storage::get($path);
        } catch (Throwable) {
            return null;
        }
        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => null,
        };
        if ($mime === 'image/webp') {
            $image = function_exists('imagecreatefromstring') ? @imagecreatefromstring($bytes) : false;
            if ($image === false) {
                return null;
            }
            ob_start();
            imagepng($image);
            $bytes = (string) ob_get_clean();
            $mime = 'image/png';
        }

        return $mime ? ['bytes' => $bytes, 'mime' => $mime] : null;
    }
}
