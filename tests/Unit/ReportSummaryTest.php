<?php

namespace Tests\Unit;

use App\Services\Reports\ReportSummary;
use Tests\TestCase;

/** The summary bullets' rules (pure: snapshot in, sentences out). */
class ReportSummaryTest extends TestCase
{
    private function gsc(int $clicks, ?float $pct, int $impressions = 1000, ?float $impressionsPct = null): array
    {
        return ['available' => true, 'totals' => ['clicks' => $clicks, 'impressions' => $impressions], 'changes' => ['clicks_pct' => $pct, 'impressions_pct' => $impressionsPct]];
    }

    private function rank(int $in10 = 0, int $out10 = 0, int $in3 = 0, int $tracked = 20): array
    {
        $band = fn (int $n) => ['count' => $n, 'keywords' => []];

        return ['available' => true, 'keywords_tracked' => $tracked, 'entered_top10' => $band($in10), 'left_top10' => $band($out10), 'entered_top3' => $band($in3), 'left_top3' => $band(0)];
    }

    private function audit(int $score, ?int $change, int $critical = 0, int $fixed = 0): array
    {
        return ['available' => true, 'health_score' => $score, 'health_change' => $change, 'counts' => ['critical' => $critical], 'fixed' => $fixed];
    }

    public function test_traffic_up_down_steady_and_without_a_comparison(): void
    {
        $this->assertSame('Search traffic up 14% (5,190 clicks)', ReportSummary::for(['search_console' => $this->gsc(5190, 14.2)])[0]);
        $this->assertSame('Search traffic down 8% (4,210 clicks)', ReportSummary::for(['search_console' => $this->gsc(4210, -8.4)])[0]);
        $this->assertSame('Search traffic steady at 1,000 clicks', ReportSummary::for(['search_console' => $this->gsc(1000, 4.9)])[0]);
        $this->assertSame('1 click from Google search', ReportSummary::for(['search_console' => $this->gsc(1, null)])[0]);
    }

    public function test_the_thresholds_are_inclusive(): void
    {
        $this->assertSame('Search traffic up 5% (100 clicks)', ReportSummary::for(['search_console' => $this->gsc(100, 5.0)])[0]);
        $this->assertSame('Search traffic down 5% (100 clicks)', ReportSummary::for(['search_console' => $this->gsc(100, -5.0)])[0]);
    }

    public function test_significant_changes_lead_and_the_rest_follow_in_order(): void
    {
        $bullets = ReportSummary::for([
            'search_console' => $this->gsc(900, 2.0),           // steady: not significant
            'rank_tracking' => $this->rank(in10: 6, out10: 1),   // 6 in: significant; 1 out: not
            'site_audit' => $this->audit(88, 4),                 // +4: below 5 points
        ]);

        $this->assertSame([
            '6 keywords entered the top 10',
            'Search traffic steady at 900 clicks',
            '1 keyword dropped out of the top 10',
            'Site health 88/100, up 4 points',
        ], $bullets);
    }

    public function test_drops_are_honest_and_critical_issues_always_count(): void
    {
        $bullets = ReportSummary::for([
            'search_console' => $this->gsc(4210, -8.0),
            'rank_tracking' => $this->rank(out10: 3),
            'site_audit' => $this->audit(61, -7, critical: 1),
        ]);

        $this->assertSame([
            'Search traffic down 8% (4,210 clicks)',
            '3 keywords dropped out of the top 10',
            'Site health 61/100, down 7 points',
            '1 critical issue needs attention',
        ], $bullets);
    }

    public function test_plurals_and_steady_health(): void
    {
        $bullets = ReportSummary::for(['rank_tracking' => $this->rank(in10: 1, in3: 2), 'site_audit' => $this->audit(90, 0, critical: 2, fixed: 1)]);

        $this->assertSame(['2 critical issues need attention', '1 keyword entered the top 10', '2 keywords reached the top 3', 'Site health steady at 90/100', '1 issue fixed since the previous check'], $bullets);
        $this->assertSame('Site health 90/100, up 1 point', ReportSummary::for(['site_audit' => $this->audit(90, 1)])[0]);
    }

    public function test_at_most_five_and_at_least_two(): void
    {
        $five = ReportSummary::for([
            'search_console' => $this->gsc(5190, 14.0, 120400, 9.0),
            'rank_tracking' => $this->rank(in10: 6, out10: 3, in3: 4),
            'site_audit' => $this->audit(88, 6, critical: 2, fixed: 3),
        ]);
        $this->assertCount(5, $five);
        $this->assertSame('Search traffic up 14% (5,190 clicks)', $five[0]);

        // One thing to say: the keyword count fills in.
        $this->assertSame(['Search traffic steady at 300 clicks', '1,250 keywords tracked in Google'], ReportSummary::for([
            'search_console' => $this->gsc(300, 1.0),
            'rank_tracking' => $this->rank(tracked: 1250),
        ]));
    }

    public function test_impressions_only_when_they_moved(): void
    {
        // Significant, so it leads.
        $this->assertSame(['Shown in Google search 120,400 times, up 9%', 'Search traffic steady at 10 clicks'], ReportSummary::for(['search_console' => $this->gsc(10, 0.0, 120400, 9.0)]));
        $this->assertSame(['Search traffic steady at 10 clicks'], ReportSummary::for(['search_console' => $this->gsc(10, 0.0, 120400, 2.0)]));
    }

    public function test_unavailable_sections_say_nothing(): void
    {
        $this->assertSame(
            ['No search, ranking or website health data was available for this period.'],
            ReportSummary::for(['search_console' => ['available' => false, 'reason' => 'x'], 'rank_tracking' => ['available' => false, 'reason' => 'y']]),
        );
    }

    public function test_numbers_use_thousands_separators(): void
    {
        $this->assertSame('1,234,567', ReportSummary::number(1234567));
        $this->assertSame('5,190 clicks', ReportSummary::count(5190, 'click'));
        $this->assertSame('1 keyword', ReportSummary::count(1, 'keyword'));
    }
}
