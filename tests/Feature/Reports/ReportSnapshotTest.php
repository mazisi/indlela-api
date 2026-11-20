<?php

namespace Tests\Feature\Reports;

use App\Models\Report;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AuthTestHelpers;
use Tests\Support\ReportHelpers;
use Tests\TestCase;

/** What a report freezes, per section, and what it says when a section has nothing. */
class ReportSnapshotTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase, ReportHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        Storage::fake('s3');
        $this->travelTo('2026-11-20 10:00:00');
    }

    private function snapshot(Site $site, array $input = []): array
    {
        $report = $this->makeReport($site, $input);
        $this->assertSame(Report::READY, $report->status, (string) $report->error);

        return $report->data;
    }

    public function test_the_header_period_and_branding(): void
    {
        $this->workspace()->forceFill(['brand_color' => '#aa3300'])->save();
        $data = $this->snapshot($this->fullSite());

        $this->assertSame(['name' => $this->workspace()->name, 'logo' => null, 'brand_color' => '#aa3300'], $data['branding']);
        $this->assertSame(['name' => 'Karoo Coffee'], $data['client']);
        $this->assertSame(['name' => 'karoo.co.za', 'url' => 'https://www.karoo.co.za'], $data['site']);
        $this->assertSame(['start' => '2026-10-01', 'end' => '2026-10-31', 'label' => 'October 2026'], $data['period']);
        $this->assertSame(['start' => '2026-09-01', 'end' => '2026-09-30', 'label' => 'September 2026'], $data['compare_period']);
        $this->assertSame(Report::SECTIONS, $data['sections']);
    }

    public function test_search_console_totals_changes_and_tops(): void
    {
        $gsc = $this->snapshot($this->fullSite())['search_console'];

        $this->assertTrue($gsc['available']);
        $this->assertEquals(['clicks' => 3720, 'impressions' => 46500, 'ctr' => 0.08, 'position' => 8.0], $gsc['totals']);
        $this->assertSame(3000, $gsc['compare_totals']['clicks']);
        $this->assertEquals(['clicks_pct' => 24.0, 'impressions_pct' => 55.0, 'ctr' => -2.0, 'position' => 2.0], $gsc['changes']);
        $this->assertCount(31, $gsc['series']);
        $this->assertSame(['date' => '2026-10-01', 'clicks' => 120, 'impressions' => 1500], $gsc['series'][0]);
        $this->assertSame(['coffee beans', 'espresso machine', 'buy coffee online'], array_column($gsc['top_queries'], 'query'));
        $this->assertSame(50, $gsc['top_queries'][0]['clicks'], 'September rows are not counted');
        $this->assertSame('https://www.karoo.co.za/beans', $gsc['top_pages'][0]['page']);
        $this->assertContains('buy coffee online', array_column($gsc['quick_wins'], 'query'));
        $this->assertNotContains('espresso machine', array_column($gsc['quick_wins'], 'query'), 'already top 3');
    }

    public function test_compare_none_and_year(): void
    {
        $site = $this->fullSite();

        $none = $this->snapshot($site, ['compare' => 'none']);
        $this->assertNull($none['compare_period']);
        $this->assertNull($none['search_console']['compare_totals']);
        $this->assertNull($none['search_console']['changes']['clicks_pct']);

        $year = $this->snapshot($site, ['compare' => 'year']);
        $this->assertSame('October 2025', $year['compare_period']['label']);
        $this->assertNull($year['search_console']['changes']['clicks_pct'], 'no data a year ago: no %');
    }

    public function test_rank_tracking_bands_movers_and_features(): void
    {
        $rank = $this->snapshot($this->fullSite())['rank_tracking'];

        $this->assertTrue($rank['available']);
        $this->assertSame(5, $rank['keywords_tracked'], 'active keywords only');
        $this->assertSame(4, $rank['keywords_checked']);
        $this->assertEquals(11.0, $rank['avg_position']); // (5 + 2 + 12 + 25) / 4; k1's November check ignored
        $this->assertEquals(14.3, $rank['avg_position_start']); // (15 + 8 + 4 + 30) / 4; k4 from its first October check
        $this->assertEquals(3.3, $rank['avg_position_change']);
        $this->assertSame(['top3' => 1, 'top10' => 1, 'top20' => 1, 'top100' => 1, 'not_ranking' => 0], $rank['distribution']);
        $this->assertSame(['top3' => 0, 'top10' => 2, 'top20' => 1, 'top100' => 1, 'not_ranking' => 0], $rank['distribution_start']);
        $this->assertSame(['count' => 1, 'keywords' => ['k1']], $rank['entered_top10']);
        $this->assertSame(['count' => 1, 'keywords' => ['k3']], $rank['left_top10']);
        $this->assertSame(['count' => 1, 'keywords' => ['k2']], $rank['entered_top3']);
        $this->assertSame(['count' => 0, 'keywords' => []], $rank['left_top3']);
        $this->assertSame([['k1', 15, 5, 10], ['k2', 8, 2, 6], ['k4', 30, 25, 5]], array_map(fn ($g) => [$g['keyword'], $g['from'], $g['to'], $g['change']], $rank['gains']));
        $this->assertSame([['k3', 4, 12, -8]], array_map(fn ($g) => [$g['keyword'], $g['from'], $g['to'], $g['change']], $rank['drops']));
        $this->assertSame(['present' => 1, 'ours' => 1], $rank['serp_features']['featured_snippet']);
        $this->assertSame(['present' => 0, 'ours' => 0], $rank['serp_features']['local_pack']);
    }

    public function test_site_audit_is_the_latest_finished_by_the_period_end(): void
    {
        $audit = $this->snapshot($this->fullSite())['site_audit'];

        $this->assertTrue($audit['available']);
        $this->assertSame('2026-10-20', $audit['audited_on'], 'the 5 November audit is after the period');
        $this->assertSame(78, $audit['health_score']);
        $this->assertSame(70, $audit['previous_health_score']);
        $this->assertSame(8, $audit['health_change']);
        $this->assertSame(['critical' => 2, 'warning' => 1, 'notice' => 0, 'total' => 3], $audit['counts']);
        $this->assertSame([4, 1], [$audit['fixed'], $audit['new']]);
        $this->assertSame(['Broken pages (4xx)', 'critical', 2], [$audit['top_issues'][0]['title'], $audit['top_issues'][0]['severity'], $audit['top_issues'][0]['count']]);
        $this->assertStringEndsWith('.', $audit['top_issues'][0]['how_to_fix']);
        $this->assertStringNotContainsString('If it is gone for good', $audit['top_issues'][0]['how_to_fix'], 'first sentence only');
    }

    public function test_the_full_summary(): void
    {
        $report = $this->makeReport($this->fullSite());

        $this->assertSame([
            'Search traffic up 24% (3,720 clicks)',
            'Site health 78/100, up 8 points',
            '2 critical issues need attention',
            'Shown in Google search 46,500 times, up 55%',
            '1 keyword entered the top 10',
        ], $report->summary);
    }

    public function test_sections_without_data_say_why(): void
    {
        $site = $this->rankSite($this->makeClient()); // no Search Console, keywords or audits

        $data = $this->snapshot($site);

        $this->assertSame(['available' => false, 'reason' => 'Google Search Console is not connected for this website.'], $data['search_console']);
        $this->assertSame(['available' => false, 'reason' => 'No keywords are tracked for this website yet.'], $data['rank_tracking']);
        $this->assertSame(['available' => false, 'reason' => 'No website health check had finished by the end of this period.'], $data['site_audit']);
        $this->assertSame(['No search, ranking or website health data was available for this period.'], $this->makeReport($site)->summary);
    }

    public function test_data_not_yet_published_and_keywords_never_checked(): void
    {
        $site = $this->reportSite();
        $site->forceFill(['gsc_data_through' => '2026-09-20'])->save();
        $this->makeKeyword($site, 'unchecked');

        $data = $this->snapshot($site);

        $this->assertSame('Google has not published search data for this period yet.', $data['search_console']['reason']);
        $this->assertSame('No keyword rankings had been checked by the end of this period.', $data['rank_tracking']['reason']);
    }

    public function test_only_the_chosen_sections_are_built(): void
    {
        $data = $this->snapshot($this->fullSite(), ['sections' => ['site_audit']]);

        $this->assertSame(['site_audit'], $data['sections']);
        $this->assertArrayNotHasKey('search_console', $data);
        $this->assertArrayNotHasKey('rank_tracking', $data);
    }

    public function test_the_snapshot_holds_no_ids_or_emails(): void
    {
        $data = $this->snapshot($this->fullSite());
        $json = json_encode($data);

        $this->assertDoesNotMatchRegularExpression('/"[a-z_]*id"\s*:/', $json);
        $this->assertStringNotContainsString('@', $json);
    }
}
