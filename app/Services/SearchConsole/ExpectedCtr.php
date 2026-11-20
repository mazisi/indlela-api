<?php

namespace App\Services\SearchConsole;

/**
 * The CTR a result "should" get at an average position: the curve in
 * config('indlela.search_console.expected_ctr'), looked up at the rounded
 * position (below 1 → 1, past the last entry → the last value).
 */
final class ExpectedCtr
{
    public static function at(float $position): float
    {
        $curve = self::curve();
        $key = max(1, min((int) round($position), array_key_last($curve)));

        return $curve[$key];
    }

    /** The same lookup as a SQL expression over $position (a trusted column expression). */
    public static function sql(string $position): string
    {
        $curve = self::curve();
        $last = array_key_last($curve);
        $cases = '';
        foreach ($curve as $pos => $ctr) {
            $cases .= sprintf(' WHEN %d THEN %F', $pos, $ctr);
        }

        return "(CASE LEAST(GREATEST(ROUND({$position})::int, 1), {$last}){$cases} END)";
    }

    /** @return array<int, float> */
    private static function curve(): array
    {
        $curve = array_map('floatval', config('indlela.search_console.expected_ctr'));
        ksort($curve);

        return $curve;
    }
}
