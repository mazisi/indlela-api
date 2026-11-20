<?php

namespace Tests\Feature\SearchConsole;

use App\Models\GscQueryPageDaily;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\SearchConsole\ExpectedCtr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\AuthTestHelpers;
use Tests\Support\SearchConsoleHelpers;
use Tests\TestCase;

/**
 * The reports' maths on small hand-made datasets. "Today" is 2026-06-15 and
 * the site's data runs through 2026-06-14, so the default period is
 * 2026-05-18 .. 2026-06-14 (28 days) and the previous one 2026-04-20 .. 2026-05-17.
 */
class SearchConsoleReportsTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase, SearchConsoleHelpers;

    private User $manager;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->travelTo('2026-06-15 08:00:00');
        $this->manager = $this->makeUser(Role::MANAGER);
        $this->site = $this->linkedSite(state: ['gsc_data_through' => '2026-06-14', 'gsc_synced_at' => now()]);
    }

    private function report(string $report, array $query = []): TestResponse
    {
        return $this->actingAs($this->manager, 'sanctum')->getJson("/api/sites/{$this->site->id}/search-console/{$report}?".http_build_query($query));
    }

    public function test_overview_totals_series_and_compare_periods(): void
    {
        $this->addTotals($this->site, [
            ['2026-06-14', 10, 100, 2.0],
            ['2026-06-13', 5, 300, 6.0],
            ['2026-05-17', 4, 100, 10.0],   // previous period
            ['2025-06-01', 7, 70, 3.0],     // a year earlier
        ]);

        $response = $this->report('overview')->assertOk()
            ->assertJsonPath('period', ['start' => '2026-05-18', 'end' => '2026-06-14'])
            ->assertJsonPath('compare_period', ['start' => '2026-04-20', 'end' => '2026-05-17'])
            ->assertJsonPath('data_through', '2026-06-14')
            // Position weighted by impressions: (2 x 100 + 6 x 300) / 400 = 5.
            ->assertJsonPath('totals', ['clicks' => 15, 'impressions' => 400, 'ctr' => 0.0375, 'position' => 5])
            ->assertJsonPath('compare_totals', ['clicks' => 4, 'impressions' => 100, 'ctr' => 0.04, 'position' => 10])
            ->assertJsonPath('sync.status', 'done')
            ->assertJsonCount(28, 'series')
            ->assertJsonCount(28, 'compare_series')
            ->assertJsonPath('series.0', ['date' => '2026-05-18', 'clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => null])
            ->assertJsonPath('series.27', ['date' => '2026-06-14', 'clicks' => 10, 'impressions' => 100, 'ctr' => 0.1, 'position' => 2]);
        $this->assertSame('2026-05-17', $response->json('compare_series.27.date'));

        $this->report('overview', ['compare' => 'year', 'start' => '2026-06-01', 'end' => '2026-06-14'])
            ->assertJsonPath('compare_period', ['start' => '2025-06-01', 'end' => '2025-06-14'])
            ->assertJsonPath('totals.clicks', 15)
            ->assertJsonPath('compare_totals', ['clicks' => 7, 'impressions' => 70, 'ctr' => 0.1, 'position' => 3])
            ->assertJsonCount(14, 'series');

        $this->report('overview', ['compare' => 'none', 'start' => '2026-06-13', 'end' => '2026-06-14'])
            ->assertJsonPath('compare_period', null)->assertJsonPath('compare_totals', null)->assertJsonPath('compare_series', null)
            ->assertJsonCount(2, 'series');

        $this->report('overview', ['start' => '2026-06-14', 'end' => '2026-06-01'])->assertJsonValidationErrors('end');
        $this->report('overview', ['compare' => 'decade'])->assertJsonValidationErrors('compare');
    }

    public function test_unlinked_is_409_and_linked_without_data_is_empty_with_the_sync_state(): void
    {
        $this->site->forceFill(['gsc_property' => null])->save();
        $this->report('overview')->assertStatus(409)->assertJsonPath('error_type', 'gsc_not_linked');
        $this->report('queries')->assertStatus(409)->assertJsonPath('error_type', 'gsc_not_linked');

        $this->site->forceFill(['gsc_property' => 'sc-domain:example.co.za', 'gsc_sync_status' => 'syncing', 'gsc_data_through' => null])->save();
        $this->report('overview')->assertOk()
            ->assertJsonPath('period', ['start' => '2026-05-18', 'end' => '2026-06-14']) // ending yesterday
            ->assertJsonPath('data_through', null)
            ->assertJsonPath('totals', ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => null])
            ->assertJsonPath('series', [])
            ->assertJsonPath('sync.status', 'syncing');
        foreach (['queries', 'pages', 'quick-wins', 'low-ctr', 'content-decay'] as $report) {
            $this->report($report)->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0)->assertJsonPath('sync.status', 'syncing');
        }
    }

    public function test_queries_and_pages_with_changes_against_the_previous_period(): void
    {
        $a = 'https://x.co.za/a';
        $b = 'https://x.co.za/b';
        $this->addRows($this->site, [
            ['2026-06-01', 'coffee', $a, 10, 100, 2.0],
            ['2026-06-02', 'coffee', $b, 0, 100, 4.0],
            ['2026-06-03', 'tea', $a, 20, 40, 1.5],
            ['2026-05-30', 'coffee', $a, 4, 50, 5.0],   // compare period
            ['2026-05-26', 'old', $a, 3, 10, 9.0],      // compare only: not listed
        ]);
        $period = ['start' => '2026-06-01', 'end' => '2026-06-07'];

        $this->report('queries', $period)->assertOk()
            ->assertJsonPath('period', ['start' => '2026-06-01', 'end' => '2026-06-07'])
            ->assertJsonPath('compare_period', ['start' => '2026-05-25', 'end' => '2026-05-31'])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('data', [
                ['query' => 'tea', 'clicks' => 20, 'impressions' => 40, 'ctr' => 0.5, 'position' => 1.5, 'clicks_change' => 20, 'position_change' => null],
                ['query' => 'coffee', 'clicks' => 10, 'impressions' => 200, 'ctr' => 0.05, 'position' => 3, 'clicks_change' => 6, 'position_change' => -2],
            ]);

        $this->report('queries', [...$period, 'sort' => '-impressions'])->assertJsonPath('data.0.query', 'coffee');
        $this->report('queries', [...$period, 'sort' => 'position'])->assertJsonPath('data.0.query', 'tea');
        $this->report('queries', [...$period, 'search' => 'COF'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.query', 'coffee');
        $this->report('queries', [...$period, 'compare' => 'none'])->assertJsonPath('data.1.clicks_change', null);
        $this->report('queries', [...$period, 'sort' => 'potential_clicks'])->assertJsonValidationErrors('sort');
        $this->report('queries', [...$period, 'per_page' => 10, 'page' => 2])->assertJsonPath('data', [])->assertJsonPath('meta.current_page', 2);

        $this->report('pages', $period)->assertOk()
            ->assertJsonPath('data', [
                // (2 x 100 + 1.5 x 40) / 140 = 1.857…; before (coffee + old): 7 clicks, (5 x 50 + 9 x 10) / 60 = 5.667.
                ['page' => $a, 'clicks' => 30, 'impressions' => 140, 'ctr' => 0.2143, 'position' => 1.9, 'clicks_change' => 23, 'position_change' => -3.8],
                ['page' => $b, 'clicks' => 0, 'impressions' => 100, 'ctr' => 0, 'position' => 4, 'clicks_change' => 0, 'position_change' => null],
            ]);
    }

    public function test_quick_wins_are_queries_at_positions_5_to_15_with_enough_impressions(): void
    {
        $x = 'https://x.co.za/x';
        $y = 'https://x.co.za/y';
        $this->addRows($this->site, [
            ['2026-06-01', 'qw1', $x, 15, 700, 8.0],
            ['2026-06-02', 'qw1', $y, 5, 300, 8.0],
            ['2026-06-01', 'qw2', $x, 0, 100, 15.0],      // edge of the band and the minimum
            ['2026-06-01', 'mixed', $x, 0, 100, 1.0],     // weighted (1x100 + 6x900)/1000 = 5.5
            ['2026-06-02', 'mixed', $y, 0, 900, 6.0],
            ['2026-06-01', 'too-high', $x, 50, 1000, 4.9],
            ['2026-06-01', 'too-low', $x, 0, 1000, 15.1],
            ['2026-06-01', 'few', $x, 0, 99, 8.0],
            ['2026-05-01', 'outside', $x, 0, 5000, 8.0],  // before the period
        ]);
        $target = ExpectedCtr::at(3); // 0.11

        $this->report('quick-wins')->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data', [
                // potential = (impressions x 0.11 - clicks) x 30/28
                ['query' => 'mixed', 'page' => $y, 'impressions' => 1000, 'clicks' => 0, 'ctr' => 0, 'position' => 5.5, 'potential_clicks' => (int) round(1000 * $target * 30 / 28)],
                ['query' => 'qw1', 'page' => $x, 'impressions' => 1000, 'clicks' => 20, 'ctr' => 0.02, 'position' => 8, 'potential_clicks' => (int) round((1000 * $target - 20) * 30 / 28)],
                ['query' => 'qw2', 'page' => $x, 'impressions' => 100, 'clicks' => 0, 'ctr' => 0, 'position' => 15, 'potential_clicks' => (int) round(100 * $target * 30 / 28)],
            ]);
        $this->assertSame(118, $this->report('quick-wins')->json('data.0.potential_clicks'));

        // A 14-day period halves the impressions needed (100 per 28 days → 50): "few" (99) gets in.
        $this->report('quick-wins', ['start' => '2026-06-01', 'end' => '2026-06-14', 'sort' => 'position'])
            ->assertJsonPath('meta.total', 4)->assertJsonPath('data.0.query', 'mixed')->assertJsonPath('data.1.query', 'few');
        $this->report('quick-wins', ['search' => 'qw'])->assertJsonPath('meta.total', 2);
    }

    public function test_low_ctr_flags_page_one_pages_below_the_expected_curve(): void
    {
        $p = fn (int $n) => "https://x.co.za/p{$n}";
        $this->addRows($this->site, [
            ['2026-06-01', 'q-a', $p(1), 25, 400, 1.0],
            ['2026-06-01', 'q-b', $p(1), 25, 300, 1.0],
            ['2026-06-01', 'q-c', $p(1), 25, 200, 1.0],
            ['2026-06-01', 'q-d', $p(1), 25, 100, 1.0],   // ctr 0.10 < 0.28 x 0.7
            ['2026-06-01', 'q-e', $p(2), 60, 500, 3.0],   // ctr 0.12 ≥ 0.11 x 0.7: fine
            ['2026-06-01', 'q-f', $p(3), 5, 300, 2.4],    // rounds to 2: 0.0167 < 0.155 x 0.7
            ['2026-06-01', 'q-g', $p(4), 0, 1000, 11.0],  // not page one
            ['2026-06-01', 'q-h', $p(5), 0, 199, 1.0],    // too few impressions
        ]);

        $this->report('low-ctr')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data', [
                ['page' => $p(1), 'impressions' => 1000, 'clicks' => 100, 'ctr' => 0.1, 'expected_ctr' => 0.28, 'position' => 1, 'missed_clicks' => 180, 'top_queries' => ['q-a', 'q-b', 'q-c']],
                ['page' => $p(3), 'impressions' => 300, 'clicks' => 5, 'ctr' => 0.0167, 'expected_ctr' => 0.155, 'position' => 2.4, 'missed_clicks' => 42, 'top_queries' => ['q-f']],
            ]);

        $this->report('low-ctr', ['sort' => 'position'])->assertJsonPath('data.0.page', $p(1));
        $this->report('low-ctr', ['search' => 'p3'])->assertJsonPath('meta.total', 1);
    }

    public function test_content_decay_by_28_day_drop_or_months_of_decline(): void
    {
        $page = fn (string $name) => "https://x.co.za/{$name}";
        $this->addRows($this->site, [
            // Dropped 50 %: 100 in the previous 28 days, 50 in the last.
            ['2026-05-01', 'q', $page('dropped'), 100, 1000, 3.0],
            ['2026-06-01', 'q', $page('dropped'), 50, 1000, 3.0],
            // Falling every month Feb → May (90, 70, 50, 30), flat over the 28-day windows.
            ['2026-02-05', 'q', $page('sliding'), 90, 1000, 3.0],
            ['2026-03-05', 'q', $page('sliding'), 70, 1000, 3.0],
            ['2026-04-05', 'q', $page('sliding'), 50, 1000, 3.0],
            ['2026-05-05', 'q', $page('sliding'), 30, 1000, 3.0],
            ['2026-06-01', 'q', $page('sliding'), 30, 1000, 3.0],
            // Too small to matter (10 → 0).
            ['2026-05-01', 'q', $page('small'), 10, 100, 3.0],
            // Only 10 % down.
            ['2026-05-01', 'q', $page('steady'), 100, 1000, 3.0],
            ['2026-06-01', 'q', $page('steady'), 90, 1000, 3.0],
        ]);

        $this->report('content-decay', ['start' => '2020-01-01', 'end' => '2020-01-31'])->assertOk()
            ->assertJsonMissingPath('period')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0', [
                'page' => $page('dropped'), 'clicks_last_28' => 50, 'clicks_prev_28' => 100, 'clicks_change_pct' => -50,
                'monthly' => [
                    ['month' => '2025-12', 'clicks' => 0], ['month' => '2026-01', 'clicks' => 0], ['month' => '2026-02', 'clicks' => 0],
                    ['month' => '2026-03', 'clicks' => 0], ['month' => '2026-04', 'clicks' => 0], ['month' => '2026-05', 'clicks' => 100],
                ],
                'decline_months' => 0,
            ])
            ->assertJsonPath('data.1.page', $page('sliding'))
            ->assertJsonPath('data.1.clicks_change_pct', 0)
            ->assertJsonPath('data.1.monthly.2', ['month' => '2026-02', 'clicks' => 90])
            ->assertJsonPath('data.1.monthly.5', ['month' => '2026-05', 'clicks' => 30])
            ->assertJsonPath('data.1.decline_months', 3);

        $this->report('content-decay', ['sort' => '-decline_months'])->assertJsonPath('data.0.page', $page('sliding'));

        // Data through the last day of a month: that month counts as full.
        $this->site->forceFill(['gsc_data_through' => '2026-05-31'])->save();
        $this->report('content-decay')->assertJsonPath('data.0.monthly.5.month', '2026-05');
    }

    public function test_rows_stamped_with_another_workspace_never_reach_a_report(): void
    {
        $rival = $this->makeWorkspace('Rival');
        $this->addRows($this->site, [['2026-06-01', 'mine', 'https://x.co.za/', 1, 10, 2.0]]);
        GscQueryPageDaily::withoutGlobalScopes()->insert([
            'workspace_id' => $rival->id, 'site_id' => $this->site->id, 'date' => '2026-06-01', 'query' => 'leaked', 'page' => 'https://x.co.za/',
            'query_hash' => md5('leaked'), 'page_hash' => md5('https://x.co.za/'), 'clicks' => 5, 'impressions' => 50, 'ctr' => 0.1, 'position' => 2.0,
        ]);

        $this->report('queries')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.query', 'mine');
    }
}
