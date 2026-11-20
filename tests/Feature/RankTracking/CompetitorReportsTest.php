<?php

namespace Tests\Feature\RankTracking;

use App\Models\CompetitorAlert;
use App\Models\RankCheck;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCompetitor;
use App\Models\User;
use App\Services\RankTracking\CompetitorTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthTestHelpers;
use Tests\Support\RankTrackingHelpers;
use Tests\TestCase;

/** The Competitors tab: figures, share of voice, trend, head-to-head, suggestions and alerts. */
class CompetitorReportsTest extends TestCase
{
    use AuthTestHelpers, RankTrackingHelpers, RefreshDatabase;

    private Site $site;

    private User $analyst;

    private SiteCompetitor $rival;

    private SiteCompetitor $beta;

    private SiteCompetitor $gamma;

    /** @var array<string, RankCheck> */
    private array $checks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        // Friday; the ISO week started on Monday 16 November.
        $this->travelTo('2026-11-20 10:00:00');
        $client = $this->makeClient();
        $this->site = $this->rankSite($client);
        $this->analyst = $this->makeUser(Role::ANALYST, clients: [$client]);

        $this->rival = $this->makeCompetitor($this->site, 'rival.co.za', ['name' => 'Rival Roasters']);
        $this->beta = $this->makeCompetitor($this->site, 'beta.co.za');
        $this->gamma = $this->makeCompetitor($this->site, 'gamma.co.za', ['is_active' => false]);

        $beans = $this->makeKeyword($this->site, 'coffee beans', ['is_key' => true]);
        $espresso = $this->makeKeyword($this->site, 'espresso');
        $tea = $this->makeKeyword($this->site, 'tea');
        $old = $this->makeKeyword($this->site, 'old', ['is_active' => false]);

        $this->checks['beans_prev'] = $this->addCheck($beans, '2026-11-13', 5, ['organic_domains' => $this->hosts([1 => 'rival.co.za', 5 => 'karoo.co.za', 8 => 'beta.co.za'])]);
        $this->checks['beans'] = $this->addCheck($beans, '2026-11-20', 4, ['organic_domains' => $this->hosts([2 => 'rival.co.za', 4 => 'karoo.co.za', 6 => 'directory.co.za', 12 => 'beta.co.za', 30 => 'news.co.za'])]);
        $this->addCheck($espresso, '2026-11-20', 2, ['organic_domains' => $this->hosts([2 => 'karoo.co.za', 3 => 'shop.rival.co.za', 4 => 'shop.gamma.co.za', 7 => 'directory.co.za'])]);
        $this->addCheck($tea, '2026-11-20', null, ['organic_domains' => $this->hosts([1 => 'beta.co.za', 9 => 'other.co.za'])]);
        $this->addCheck($old, '2026-11-20', 1, ['organic_domains' => $this->hosts([1 => 'karoo.co.za', 2 => 'oldonly.co.za'])]);

        $tracker = app(CompetitorTracker::class);
        $tracker->backfill($this->rival);
        $tracker->backfill($this->beta);
    }

    private function fetch(string $path)
    {
        return $this->actingAs($this->analyst, 'sanctum')->getJson("/api/sites/{$this->site->id}/{$path}")->assertOk();
    }

    public function test_competitors_list_with_their_figures(): void
    {
        $data = $this->fetch('competitors')->json('data');

        $this->assertSame(['rival.co.za', 'beta.co.za', 'gamma.co.za'], array_column($data, 'domain'));
        $this->assertEquals(['top10' => 2, 'top3' => 2, 'ranked' => 2, 'avg_position' => 2.5, 'sov' => 40], $data[0]['latest']);
        $this->assertSame('Rival Roasters', $data[0]['name']);
        $this->assertEquals(['top10' => 1, 'top3' => 1, 'ranked' => 2, 'avg_position' => 6.5, 'sov' => 20], $data[1]['latest']);
        $this->assertTrue($data[1]['has_history']);
        // Paused: not in the comparison.
        $this->assertNull($data[2]['latest']);
        $this->assertFalse($data[2]['has_history']);
        $this->assertFalse($data[2]['is_active']);
        $this->assertArrayHasKey('created_at', $data[2]);
    }

    public function test_overview_share_of_voice_and_weekly_trend(): void
    {
        $data = $this->fetch('competitors/overview')->json('data');

        $this->assertEquals(['domain' => 'karoo.co.za', 'top10' => 2, 'top3' => 1, 'ranked' => 2, 'avg_position' => 3, 'sov' => 40], $data['site']);
        $this->assertEquals([
            ['id' => $this->rival->id, 'name' => 'Rival Roasters', 'domain' => 'rival.co.za', 'top10' => 2, 'top3' => 2, 'ranked' => 2, 'avg_position' => 2.5, 'sov' => 40],
            ['id' => $this->beta->id, 'name' => 'beta.co.za', 'domain' => 'beta.co.za', 'top10' => 1, 'top3' => 1, 'ranked' => 2, 'avg_position' => 6.5, 'sov' => 20],
        ], $data['competitors']);
        $this->assertSame(3, $data['keywords_total']);
        $this->assertSame(1, $data['key_keywords']);
        $this->assertSame('2026-11-13', $data['history_since']);

        $trend = $data['trend'];
        $this->assertCount(12, $trend);
        $this->assertSame('2026-08-31', $trend[0]['week_start']);
        $this->assertNull($trend[0]['site']);
        $this->assertEquals([], $trend[0]['competitors']);
        // Week of 9 Nov: only "coffee beans" (5, rival 1, beta 8): one top-10 each.
        $this->assertSame('2026-11-09', $trend[10]['week_start']);
        $this->assertEquals(33.3, $trend[10]['site']);
        $this->assertEquals([$this->rival->id => 33.3, $this->beta->id => 33.3], $trend[10]['competitors']);
        $this->assertSame('2026-11-16', $trend[11]['week_start']);
        $this->assertEquals(40, $trend[11]['site']);
        $this->assertEquals([$this->rival->id => 40, $this->beta->id => 20], $trend[11]['competitors']);
    }

    public function test_head_to_head_rows_filters_and_sorts(): void
    {
        $rows = $this->fetch('competitors/keywords')->assertJsonPath('meta.total', 3)->json('data');

        $this->assertSame(['coffee beans', 'espresso', 'tea'], array_column($rows, 'keyword'));
        $this->assertEquals([
            'keyword_id' => $this->checks['beans']->keyword_id, 'keyword' => 'coffee beans', 'location_name' => 'South Africa', 'device' => 'desktop', 'is_key' => true,
            'ours' => ['position' => 4, 'change' => 1],
            'competitors' => [['id' => $this->rival->id, 'position' => 2, 'change' => -1], ['id' => $this->beta->id, 'position' => 12, 'change' => -4]],
            'best_competitor_position' => 2, 'gap' => -2, 'ahead_of_us' => [$this->rival->id],
        ], $rows[0]);
        // Espresso: no earlier check, so no changes; beta isn't in the top 100.
        $this->assertSame(['position' => 2, 'change' => null], $rows[1]['ours']);
        $this->assertSame([['id' => $this->rival->id, 'position' => 3, 'change' => null], ['id' => $this->beta->id, 'position' => null, 'change' => null]], $rows[1]['competitors']);
        $this->assertSame(1, $rows[1]['gap']);
        $this->assertSame([], $rows[1]['ahead_of_us']);
        // Tea: we don't rank (101), beta is first.
        $this->assertSame(-100, $rows[2]['gap']);
        $this->assertSame([$this->beta->id], $rows[2]['ahead_of_us']);

        $keywords = fn (string $query) => array_column($this->fetch("competitors/keywords?{$query}")->json('data'), 'keyword');
        $this->assertSame(['coffee beans', 'tea'], $keywords('only=losing'));
        $this->assertSame(['espresso'], $keywords('only=winning'));
        $this->assertSame(['coffee beans'], $keywords('only=key'));
        $this->assertSame(['espresso'], $keywords('search=ESP'));
        $this->assertSame(['tea', 'coffee beans', 'espresso'], $keywords('sort=gap'));
        $this->assertSame(['coffee beans', 'espresso', 'tea'], $keywords('sort=-position'));
        $this->assertSame(['espresso', 'coffee beans', 'tea'], $keywords('sort=position'));
        $this->actingAs($this->analyst, 'sanctum')->getJson("/api/sites/{$this->site->id}/competitors/keywords?only=nope")->assertUnprocessable();
    }

    public function test_suggestions_leave_out_the_site_and_its_competitors(): void
    {
        $this->assertSame([
            ['domain' => 'directory.co.za', 'top10_keywords' => 2, 'keywords_seen' => 2, 'best_position' => 6],
            ['domain' => 'other.co.za', 'top10_keywords' => 1, 'keywords_seen' => 1, 'best_position' => 9],
        ], $this->fetch('competitors/suggestions')->json('data'));

        // Nothing until a check stored its hosts.
        $empty = $this->rankSite(url: 'https://empty.co.za');
        $this->addCheck($this->makeKeyword($empty), '2026-11-20', 3);
        $this->actingAs($this->makeUser(Role::MANAGER), 'sanctum')->getJson("/api/sites/{$empty->id}/competitors/suggestions")->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_suggestions_leave_out_blocklisted_generic_sites_and_their_subdomains(): void
    {
        $this->assertContains('wikipedia.org', config('indlela.rank_tracking.suggestion_blocklist'));

        config(['indlela.rank_tracking.suggestion_blocklist' => ['co.za', 'other.co.za']]);
        $this->assertSame([], $this->fetch('competitors/suggestions')->json('data'), 'a blocked domain hides its subdomains too');

        config(['indlela.rank_tracking.suggestion_blocklist' => ['other.co.za']]);
        $this->assertSame(['directory.co.za'], array_column($this->fetch('competitors/suggestions')->json('data'), 'domain'));
    }

    public function test_alerts_newest_first(): void
    {
        $older = CompetitorAlert::query()->create([
            'workspace_id' => $this->site->workspace_id, 'site_id' => $this->site->id, 'keyword_id' => $this->checks['beans_prev']->keyword_id, 'competitor_id' => $this->rival->id,
            'check_id' => $this->checks['beans_prev']->id, 'checked_on' => '2026-11-13', 'our_position' => 5, 'their_position' => 1,
            'previous_our_position' => 3, 'previous_their_position' => 4, 'emailed_at' => now(),
        ]);
        $newer = CompetitorAlert::query()->create([
            'workspace_id' => $this->site->workspace_id, 'site_id' => $this->site->id, 'keyword_id' => $this->checks['beans']->keyword_id, 'competitor_id' => $this->beta->id,
            'check_id' => $this->checks['beans']->id, 'checked_on' => '2026-11-20', 'our_position' => null, 'their_position' => 12,
            'previous_our_position' => 5, 'previous_their_position' => null,
        ]);

        $response = $this->fetch('competitor-alerts')->assertJsonPath('meta.total', 2);
        $this->assertSame([$newer->id, $older->id], array_column($response->json('data'), 'id'));
        $this->assertSame([
            'id' => $newer->id, 'checked_on' => '2026-11-20',
            'keyword' => ['id' => $this->checks['beans']->keyword_id, 'keyword' => 'coffee beans', 'location_name' => 'South Africa', 'device' => 'desktop'],
            'competitor' => ['id' => $this->beta->id, 'name' => 'beta.co.za', 'domain' => 'beta.co.za'],
            'our_position' => null, 'their_position' => 12, 'previous_our_position' => 5, 'previous_their_position' => null, 'emailed_at' => null,
        ], $response->json('data.0'));
        $this->assertNotNull($response->json('data.1.emailed_at'));
    }
}
