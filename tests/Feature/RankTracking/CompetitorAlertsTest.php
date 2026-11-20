<?php

namespace Tests\Feature\RankTracking;

use App\Mail\CompetitorAlertMail;
use App\Models\CompetitorAlert;
use App\Models\RankCheck;
use App\Models\RankCompetitorPosition;
use App\Models\RankKeyword;
use App\Models\Site;
use App\Models\SiteCompetitor;
use App\Models\SiteCompetitorSetting;
use App\Services\RankTracking\CompetitorTracker;
use App\Services\RankTracking\RankChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AuthTestHelpers;
use Tests\Support\RankTrackingHelpers;
use Tests\TestCase;

/** Positions and alerts written as checks finish, and the daily alert email. */
class CompetitorAlertsTest extends TestCase
{
    use AuthTestHelpers, RankTrackingHelpers, RefreshDatabase;

    private Site $site;

    private SiteCompetitor $rival;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->travelTo('2026-11-20 08:00:00');
        $this->configureDataForSeo();
        $this->site = $this->rankSite();
        $this->rival = $this->makeCompetitor($this->site, 'rival.co.za');
    }

    /** Yesterday's done check with hosts, and today's pending one posted as "task-{keyword}". */
    private function keyword(string $keyword, bool $key, ?array $yesterday): RankKeyword
    {
        $k = $this->makeKeyword($this->site, $keyword, ['is_key' => $key]);
        if ($yesterday !== null) {
            $this->addCheck($k, '2026-11-19', $yesterday['ours'], ['organic_domains' => $yesterday['hosts']]);
        }
        $this->addCheck($k, '2026-11-20', null, ['status' => RankCheck::PENDING, 'task_id' => "task-{$k->id}", 'matching_urls' => null, 'url' => null, 'completed_at' => null]);

        return $k;
    }

    private function collect(array $serps): void
    {
        $this->dfsRoutes['/v3/serp/google/organic/tasks_ready'] = fn () => $this->envelope([['id' => 'r', 'status_code' => 20000,
            'result' => array_map(fn ($id) => ['id' => "task-{$id}"], array_keys($serps))]]);
        $this->dfsRoutes['/v3/serp/google/organic/task_get/advanced/'] = function ($r) use ($serps) {
            $id = (int) str_replace('task-', '', basename(parse_url($r->url(), PHP_URL_PATH)));

            return $this->envelope([$this->serpTask($serps[$id], "task-{$id}")]);
        };
        $this->artisan('rank-tracking:collect')->assertSuccessful();
    }

    public function test_a_finished_check_stores_hosts_positions_and_alerts_on_key_keywords_only(): void
    {
        $paused = $this->makeCompetitor($this->site, 'paused.co.za', ['is_active' => false]);
        $before = ['ours' => 3, 'hosts' => $this->hosts([3 => 'karoo.co.za', 5 => 'rival.co.za'])];
        $overtaken = $this->keyword('coffee beans', true, $before);
        $notKey = $this->keyword('tea', false, $before);
        $alreadyAhead = $this->keyword('espresso', true, ['ours' => 6, 'hosts' => $this->hosts([2 => 'rival.co.za', 6 => 'karoo.co.za'])]);
        $noPrevious = $this->keyword('mugs', true, null);
        app(CompetitorTracker::class)->backfill($this->rival);

        $today = [$this->organic(1, 'https://www.rival.co.za/beans'), $this->organic(2, 'https://paused.co.za/'), $this->organic(4, 'https://www.karoo.co.za/beans')];
        $this->collect([$overtaken->id => $today, $notKey->id => $today, $alreadyAhead->id => $today, $noPrevious->id => $today]);

        $check = RankCheck::query()->where('keyword_id', $overtaken->id)->where('checked_on', '2026-11-20')->sole();
        $this->assertSame(RankCheck::DONE, $check->status);
        $this->assertSame(4, $check->position);
        $this->assertSame(['rival.co.za', 'paused.co.za', null, 'karoo.co.za'], $check->organic_domains);
        $this->assertSame(1, RankCompetitorPosition::query()->where('check_id', $check->id)->sole()->position);
        $this->assertFalse(RankCompetitorPosition::query()->where('competitor_id', $paused->id)->exists());

        $alert = CompetitorAlert::query()->sole();
        $this->assertSame([$overtaken->id, $this->rival->id, $check->id, '2026-11-20', 4, 1, 3, 5, $this->site->workspace_id], [
            $alert->keyword_id, $alert->competitor_id, $alert->check_id, $alert->checked_on->toDateString(),
            $alert->our_position, $alert->their_position, $alert->previous_our_position, $alert->previous_their_position, $alert->workspace_id,
        ]);
        $this->assertNull($alert->emailed_at);

        // A live re-check of the day where the competitor is behind again drops the alert.
        app(RankChecker::class)->complete($check->fresh(), ['items' => [$this->organic(1, 'https://karoo.co.za/'), $this->organic(2, 'https://rival.co.za/')]], 0.0);
        $this->assertSame(0, CompetitorAlert::query()->count());
        $this->assertSame(2, RankCompetitorPosition::query()->where('check_id', $check->id)->sole()->position);
    }

    public function test_dropping_out_counts_as_101_and_no_result_stores_no_hosts(): void
    {
        $k = $this->keyword('coffee beans', true, ['ours' => 7, 'hosts' => $this->hosts([7 => 'karoo.co.za'])]);
        app(CompetitorTracker::class)->backfill($this->rival);

        // Neither ranks yesterday for the rival (101 vs 7); today the site drops out and the rival is 50th.
        $this->collect([$k->id => [$this->organic(50, 'https://rival.co.za/')]]);

        $alert = CompetitorAlert::query()->sole();
        $this->assertSame([null, 50, 7, null], [$alert->our_position, $alert->their_position, $alert->previous_our_position, $alert->previous_their_position]);
    }

    public function test_the_daily_email_sends_each_sites_new_alerts_once(): void
    {
        Mail::fake();
        $keyword = $this->makeKeyword($this->site, 'coffee beans', ['is_key' => true]);
        $other = $this->rankSite(url: 'https://quiet.co.za');
        $otherKeyword = $this->makeKeyword($other, 'tea', ['is_key' => true]);
        $otherRival = $this->makeCompetitor($other, 'rival.co.za');
        SiteCompetitorSetting::query()->create(['site_id' => $this->site->id, 'alert_recipients' => ['seo@agency.co.za', 'boss@agency.co.za']]);

        $alert = fn (RankKeyword $k, SiteCompetitor $c, string $day, array $state = []) => CompetitorAlert::query()->create([
            'workspace_id' => $k->workspace_id, 'site_id' => $k->site_id, 'keyword_id' => $k->id, 'competitor_id' => $c->id,
            'check_id' => $this->addCheck($k, $day, 4)->id, 'checked_on' => $day, 'our_position' => 4, 'their_position' => 2,
            'previous_our_position' => 3, 'previous_their_position' => null, ...$state,
        ]);
        $new = $alert($keyword, $this->rival, '2026-11-20');
        $this->travelBack();
        $this->travelTo('2026-11-11 08:00:00');
        $stale = $alert($keyword, $this->rival, '2026-11-11');
        $this->travelBack();
        $this->travelTo('2026-11-20 07:30:00');
        $sent = $alert($keyword, $this->rival, '2026-11-18', ['emailed_at' => now()->subDay()]);
        $noRecipients = $alert($otherKeyword, $otherRival, '2026-11-20');

        $this->artisan('rank-tracking:competitor-alerts')->expectsOutputToContain('Emailed 1 competitor alert(s) for 1 site(s).')->assertSuccessful();

        Mail::assertSent(CompetitorAlertMail::class, 2);
        Mail::assertSent(CompetitorAlertMail::class, function (CompetitorAlertMail $mail) {
            $rendered = $mail->rendered();
            $this->assertSame('Competitor alert for karoo.co.za: 1 alert', $rendered['subject']);
            $this->assertStringContainsString('<table', $rendered['body']);
            $this->assertStringContainsString('coffee beans', $rendered['body']);
            $this->assertStringContainsString('Not in top 100 → 2', $rendered['body']);
            $this->assertSame(rtrim(config('app.frontend_url'), '/')."/dashboard/rank-tracking/{$this->site->id}?tab=competitors", $mail->url);

            return $mail->hasTo('seo@agency.co.za');
        });
        $this->assertNotNull($new->fresh()->emailed_at);
        $this->assertNull($stale->fresh()->emailed_at);
        $this->assertNull($noRecipients->fresh()->emailed_at);
        $this->assertTrue($sent->fresh()->emailed_at->lt(now()));

        $this->artisan('rank-tracking:competitor-alerts')->expectsOutputToContain('Emailed 0 competitor alert(s)')->assertSuccessful();
        Mail::assertSent(CompetitorAlertMail::class, 2);
    }

    public function test_alerts_of_a_removed_competitor_are_not_emailed(): void
    {
        Mail::fake();
        $keyword = $this->makeKeyword($this->site, 'coffee beans', ['is_key' => true]);
        SiteCompetitorSetting::query()->create(['site_id' => $this->site->id, 'alert_recipients' => ['seo@agency.co.za']]);
        CompetitorAlert::query()->create([
            'workspace_id' => $keyword->workspace_id, 'site_id' => $this->site->id, 'keyword_id' => $keyword->id, 'competitor_id' => $this->rival->id,
            'check_id' => $this->addCheck($keyword, '2026-11-20', 4)->id, 'checked_on' => '2026-11-20', 'our_position' => 4, 'their_position' => 2,
        ]);
        $this->rival->delete();

        $this->artisan('rank-tracking:competitor-alerts')->assertSuccessful();
        Mail::assertNothingSent();
    }
}
