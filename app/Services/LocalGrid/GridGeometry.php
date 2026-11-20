<?php

namespace App\Services\LocalGrid;

/**
 * Where a grid's points are (pure). Row 0 is the northern row, col 0 the
 * western column; point (r, c) sits ((size-1)/2 - r) x spacing north and
 * (c - (size-1)/2) x spacing east of the centre, with 1 degree of latitude
 * ≈ 110.574 km and 1 degree of longitude ≈ 111.320 x cos(latitude) km.
 * Coordinates are rounded to 7 decimals (DataForSEO's maximum).
 */
final class GridGeometry
{
    public const KM_PER_DEGREE_LAT = 110.574;

    public const KM_PER_DEGREE_LNG = 111.320;

    /** @return list<array{row: int, col: int, lat: float, lng: float}> north-west first, row by row */
    public static function points(float $centerLat, float $centerLng, int $size, float $spacingKm): array
    {
        $half = ($size - 1) / 2;
        $kmPerLng = self::KM_PER_DEGREE_LNG * cos(deg2rad($centerLat));
        $points = [];
        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                $points[] = [
                    'row' => $row,
                    'col' => $col,
                    'lat' => round($centerLat + ($half - $row) * $spacingKm / self::KM_PER_DEGREE_LAT, 7),
                    'lng' => round($centerLng + ($col - $half) * $spacingKm / $kmPerLng, 7),
                ];
            }
        }

        return $points;
    }

    /** DataForSEO's location_coordinate: "lat,lng,15z". */
    public static function coordinate(float $lat, float $lng, int $zoom): string
    {
        return self::decimal($lat).','.self::decimal($lng).','.$zoom.'z';
    }

    private static function decimal(float $value): string
    {
        return rtrim(rtrim(number_format(round($value, 7), 7, '.', ''), '0'), '.');
    }
}
