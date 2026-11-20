<?php

namespace App\Services\Reports;

/**
 * The PDF's charts as small server-built SVGs, embedded as data URIs
 * (dompdf draws SVG images through php-svg-lib). Shapes only: labels and
 * figures are HTML around the image, because SVG text renders unreliably
 * in dompdf.
 */
final class ReportCharts
{
    private const GRID = '#e5e7eb';

    private const MUTED = '#cbd5e1';

    /**
     * A line (with a light area under it) of a daily series.
     *
     * @param  list<int|float>  $values
     */
    public static function line(array $values, string $color, int $width = 640, int $height = 150): string
    {
        $pad = 4;
        $max = max(1, ...($values ?: [0]));
        $n = count($values);
        $grid = '';
        foreach ([0.25, 0.5, 0.75] as $f) {
            $y = round($pad + ($height - 2 * $pad) * $f, 1);
            $grid .= "<line x1=\"0\" y1=\"{$y}\" x2=\"{$width}\" y2=\"{$y}\" stroke=\"".self::GRID.'" stroke-width="1"/>';
        }
        $base = $height - $pad;
        $grid .= "<line x1=\"0\" y1=\"{$base}\" x2=\"{$width}\" y2=\"{$base}\" stroke=\"".self::MUTED.'" stroke-width="1"/>';

        if ($n === 0) {
            return self::svg($width, $height, $grid);
        }

        $points = [];
        foreach ($values as $i => $v) {
            $x = $n === 1 ? $width / 2 : $pad + ($width - 2 * $pad) * $i / ($n - 1);
            $y = $base - ($height - 2 * $pad) * ($v / $max);
            $points[] = round($x, 1).','.round($y, 1);
        }
        $first = explode(',', $points[0])[0];
        $last = explode(',', $points[$n - 1])[0];
        $area = 'M'.$first.','.$base.' L'.implode(' L', $points).' L'.$last.','.$base.' Z';

        return self::svg($width, $height, $grid
            ."<path d=\"{$area}\" fill=\"{$color}\" fill-opacity=\"0.12\" stroke=\"none\"/>"
            .'<polyline points="'.implode(' ', $points)."\" fill=\"none\" stroke=\"{$color}\" stroke-width=\"2\" stroke-linejoin=\"round\" stroke-linecap=\"round\"/>");
    }

    /**
     * Grouped bars: per band, the period's start (grey) next to its end (brand).
     *
     * @param  array<string, int>  $now
     * @param  array<string, int>  $then
     */
    public static function bars(array $now, array $then, string $color, int $width = 640, int $height = 140): string
    {
        $bands = array_keys($now);
        $max = max(1, ...array_values($now), ...array_values($then));
        $slot = $width / max(1, count($bands));
        $bar = min(36, $slot * 0.3);
        $base = $height - 2;
        $body = "<line x1=\"0\" y1=\"{$base}\" x2=\"{$width}\" y2=\"{$base}\" stroke=\"".self::MUTED.'" stroke-width="1"/>';

        foreach ($bands as $i => $band) {
            $center = $slot * $i + $slot / 2;
            foreach ([[$then[$band] ?? 0, self::MUTED, $center - $bar - 2], [$now[$band] ?? 0, $color, $center + 2]] as [$value, $fill, $x]) {
                $h = round(($height - 8) * $value / $max, 1);
                if ($h > 0) {
                    $body .= '<rect x="'.round($x, 1).'" y="'.round($base - $h, 1).'" width="'.round($bar, 1)."\" height=\"{$h}\" fill=\"{$fill}\"/>";
                }
            }
        }

        return self::svg($width, $height, $body);
    }

    /** A semicircular gauge of a 0–100 score. */
    public static function gauge(int $score, string $color, int $size = 160): string
    {
        $score = max(0, min(100, $score));
        $stroke = 16;
        $r = ($size - $stroke) / 2;
        $cx = $size / 2;
        $cy = $size / 2;
        $height = (int) ceil($cy + $stroke / 2);
        $point = function (float $fraction) use ($cx, $cy, $r): string {
            $angle = M_PI * (1 - $fraction);

            return round($cx + $r * cos($angle), 2).','.round($cy - $r * sin($angle), 2);
        };

        $track = 'M'.$point(0).' A'.$r.','.$r.' 0 0 1 '.$point(1);
        $body = "<path d=\"{$track}\" fill=\"none\" stroke=\"".self::GRID."\" stroke-width=\"{$stroke}\"/>";
        if ($score > 0) {
            $arc = 'M'.$point(0).' A'.$r.','.$r.' 0 0 1 '.$point($score / 100);
            $body .= "<path d=\"{$arc}\" fill=\"none\" stroke=\"".self::scoreColor($score, $color)."\" stroke-width=\"{$stroke}\"/>";
        }

        return self::svg($size, $height, $body);
    }

    /** Health colours: red below 50, amber below 80, else the brand accent. */
    public static function scoreColor(int $score, string $brand): string
    {
        return match (true) {
            $score < 50 => '#b91c1c',
            $score < 80 => '#b45309',
            default => $brand,
        };
    }

    public static function dataUri(string $svg): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private static function svg(int|float $width, int|float $height, string $body): string
    {
        return "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$width}\" height=\"{$height}\" viewBox=\"0 0 {$width} {$height}\">{$body}</svg>";
    }
}
