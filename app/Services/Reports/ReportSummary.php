<?php

namespace App\Services\Reports;

/**
 * The plain-English bullets at the top of a report ("Search traffic up 14%
 * (5,190 clicks)", "6 keywords entered the top 10", "Site health 88/100, up
 * 4 points", "2 critical issues need attention"), from the snapshot only, so
 * they are deterministic.
 *
 * Rules:
 *  - Candidates in a fixed order: search traffic, keywords that entered the
 *    top 10, reached the top 3, dropped out of the top 10, site health,
 *    critical issues, search impressions, issues fixed.
 *  - Significant ones come first (keeping that order): a change of at least
 *    summary_pct_threshold % (5) in traffic or impressions, at least
 *    summary_keyword_threshold (3) keywords in or out of a band, at least
 *    summary_health_threshold (5) health points, and any critical issue.
 *  - At most 5 bullets; at least 2 when there is anything to say (the number
 *    of keywords tracked fills in).
 *  - Drops are stated plainly ("Search traffic down 8% (…)"); no jargon
 *    ("clicks", "top 10" and "site health" only); whole numbers with
 *    thousands separators (5,190), percentages rounded to whole numbers.
 */
final class ReportSummary
{
    private const MAX = 5;

    private const MIN = 2;

    /**
     * @param  array<string, mixed>  $data  the snapshot (ReportSnapshot::build)
     * @return list<string>
     */
    public static function for(array $data): array
    {
        $pct = (float) config('indlela.reports.summary_pct_threshold', 5);
        $kw = (int) config('indlela.reports.summary_keyword_threshold', 3);
        $hp = (int) config('indlela.reports.summary_health_threshold', 5);

        $gsc = ($data['search_console']['available'] ?? false) ? $data['search_console'] : null;
        $rank = ($data['rank_tracking']['available'] ?? false) ? $data['rank_tracking'] : null;
        $audit = ($data['site_audit']['available'] ?? false) ? $data['site_audit'] : null;

        /** @var list<array{0: string, 1: bool}> $candidates [text, significant] */
        $candidates = [];

        if ($gsc) {
            $clicks = (int) $gsc['totals']['clicks'];
            $change = $gsc['changes']['clicks_pct'] ?? null;
            $candidates[] = match (true) {
                $change === null => [self::count($clicks, 'click').' from Google search', false],
                $change >= $pct => ['Search traffic up '.self::percent($change).' ('.self::count($clicks, 'click').')', true],
                $change <= -$pct => ['Search traffic down '.self::percent($change).' ('.self::count($clicks, 'click').')', true],
                default => ['Search traffic steady at '.self::count($clicks, 'click'), false],
            };
        }

        if ($rank) {
            $in10 = (int) $rank['entered_top10']['count'];
            $in3 = (int) $rank['entered_top3']['count'];
            $out10 = (int) $rank['left_top10']['count'];
            if ($in10 > 0) {
                $candidates[] = [self::count($in10, 'keyword').' entered the top 10', $in10 >= $kw];
            }
            if ($in3 > 0) {
                $candidates[] = [self::count($in3, 'keyword').' reached the top 3', $in3 >= $kw];
            }
            if ($out10 > 0) {
                $candidates[] = [self::count($out10, 'keyword').' dropped out of the top 10', $out10 >= $kw];
            }
        }

        if ($audit && $audit['health_score'] !== null) {
            $score = (int) $audit['health_score'];
            $change = $audit['health_change'];
            $candidates[] = match (true) {
                $change === null => ["Site health {$score}/100", false],
                $change > 0 => ["Site health {$score}/100, up ".self::count($change, 'point'), $change >= $hp],
                $change < 0 => ["Site health {$score}/100, down ".self::count(-$change, 'point'), -$change >= $hp],
                default => ["Site health steady at {$score}/100", false],
            };
        }
        if ($audit && ($critical = (int) $audit['counts']['critical']) > 0) {
            $candidates[] = [self::count($critical, 'critical issue').' '.($critical === 1 ? 'needs' : 'need').' attention', true];
        }

        if ($gsc && ($change = $gsc['changes']['impressions_pct'] ?? null) !== null && abs($change) >= $pct) {
            $times = self::number((int) $gsc['totals']['impressions']).' '.((int) $gsc['totals']['impressions'] === 1 ? 'time' : 'times');
            $candidates[] = ["Shown in Google search {$times}, ".($change > 0 ? 'up ' : 'down ').self::percent($change), true];
        }

        if ($audit && ($fixed = (int) ($audit['fixed'] ?? 0)) > 0) {
            $candidates[] = [self::count($fixed, 'issue').' fixed since the previous check', false];
        }

        $ordered = [
            ...array_filter($candidates, fn (array $c) => $c[1]),
            ...array_filter($candidates, fn (array $c) => ! $c[1]),
        ];
        $bullets = array_slice(array_column($ordered, 0), 0, self::MAX);

        if ($bullets !== [] && count($bullets) < self::MIN && $rank) {
            $bullets[] = self::count((int) $rank['keywords_tracked'], 'keyword').' tracked in Google';
        }

        return $bullets === [] ? ['No search, ranking or website health data was available for this period.'] : array_values($bullets);
    }

    /** 5190 → "5,190". */
    public static function number(int|float $n): string
    {
        return number_format($n, 0, '.', ',');
    }

    /** "1 keyword", "6 keywords", "5,190 clicks". */
    public static function count(int $n, string $noun): string
    {
        return self::number($n).' '.($n === 1 ? $noun : $noun.'s');
    }

    /** −8.4 → "8%". */
    private static function percent(float $change): string
    {
        return self::number(round(abs($change))).'%';
    }
}
