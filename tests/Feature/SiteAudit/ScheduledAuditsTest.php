<?php

namespace Tests\Feature\SiteAudit;

use App\Jobs\RunSiteAudit;
use App\Models\Site;
use App\Models\SiteAudit;
use App\Models\SiteAuditIssue;
use App\Models\SiteAuditPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AuthTestHelpers;
use Tests\Support\SiteAuditHelpers;
use Tests\TestCase;

class ScheduledAuditsTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase, SiteAuditHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        Queue::fake();
        $this->travelTo('2026-11-20 02:00:00');
    }

    private function site(string $schedule, ?string $lastFinished = null, array $state = [], string $status = SiteAudit::DONE): Site
    {
        $site = $this->auditSite(url: 'https://'.fake()->unique()->domainWord().'.co.za', state: ['audit_schedule' => $schedule, ...$state]);
        if ($lastFinished) {
            $this->makeAudit($site, ['status' => $status, 'finished_at' => $lastFinished]);
        }

        return $site;
    }

    public function test_due_sites_get_a_scheduled_audit(): void
    {
        $other = $this->makeWorkspace('Other agency');
        $due = [
            $this->site('weekly', '2026-11-13 09:00:00'),        // 7 days ago (later in the day): due
            $this->site('weekly'),                              // never audited
            $this->site('monthly', '2026-10-21 23:00:00'),      // 30 days ago
            $this->site('weekly', '2026-11-10 10:00:00', status: SiteAudit::FAILED),
            $this->auditSite($this->makeClient($other), 'https://other-agency.co.za', ['audit_schedule' => 'weekly']),
        ];
        $notDue = [
            $this->site('weekly', '2026-11-14 01:00:00'),       // 6 days ago
            $this->site('monthly', '2026-10-23 08:00:00'),      // 28 days ago
            $this->site('off', '2026-01-01 08:00:00'),
            $this->site('weekly', '2026-11-01 08:00:00', ['is_active' => false]),
        ];
        $running = $this->site('weekly', '2026-11-01 08:00:00');
        $this->makeAudit($running, ['status' => SiteAudit::CRAWLING, 'finished_at' => null]);

        $this->artisan('site-audit:run-scheduled')->expectsOutputToContain('Queued 5 scheduled audit(s)')->assertSuccessful();

        $queued = SiteAudit::query()->withoutGlobalScopes()->where('status', SiteAudit::QUEUED)->get();
        $this->assertEqualsCanonicalizing(collect($due)->pluck('id')->all(), $queued->pluck('site_id')->all());
        $this->assertSame([SiteAudit::SCHEDULED], $queued->pluck('trigger')->unique()->values()->all());
        $this->assertNull($queued->first()->triggered_by);
        $otherAudit = $queued->firstWhere('site_id', $due[4]->id);
        $this->assertSame($other->id, $otherAudit->workspace_id);
        Queue::assertPushed(RunSiteAudit::class, 5);
        Queue::assertPushed(RunSiteAudit::class, fn (RunSiteAudit $job) => $job->auditId === $otherAudit->id && $job->workspaceId === $other->id);
        $this->assertNotNull($notDue);
    }

    public function test_old_audits_lose_their_details_and_keep_their_summary(): void
    {
        $site = $this->site('off');
        $other = $this->site('off');
        $audits = collect(range(1, 12))->map(function (int $i) use ($site) {
            $audit = $this->makeAudit($site, ['finished_at' => now()->subDays(13 - $i), 'health_score' => 60 + $i]);
            $page = SiteAuditPage::query()->create(['audit_id' => $audit->id, 'url' => "https://x.co.za/{$i}", 'url_hash' => md5("{$i}"), 'status_code' => 200]);
            SiteAuditIssue::query()->create(['audit_id' => $audit->id, 'workspace_id' => $site->workspace_id, 'site_id' => $site->id, 'type' => 'missing_h1',
                'severity' => 'warning', 'page_id' => $page->id, 'url' => $page->url, 'fingerprint' => sha1("{$i}")]);

            return $audit;
        });
        $kept = $this->makeAudit($other);

        $this->artisan('site-audit:run-scheduled')->expectsOutputToContain('pruned 2 old audit(s)')->assertSuccessful();

        foreach ($audits as $i => $audit) {
            $audit->refresh();
            $old = $i < 2;
            $this->assertSame($old, $audit->pruned_at !== null, "audit {$i}");
            $this->assertSame($old ? 0 : 1, $audit->pages()->count());
            $this->assertSame($old ? 0 : 1, $audit->issues()->count());
            $this->assertSame(60 + $i + 1, $audit->health_score, 'the summary stays');
        }
        $this->assertNull($kept->fresh()->pruned_at);

        $this->actingAs($this->ownerOf($this->workspace()), 'sanctum')->getJson("/api/sites/{$site->id}/audits?per_page=20")
            ->assertJsonPath('meta.total', 12)->assertJsonPath('data.11.details_pruned', true)->assertJsonPath('data.0.details_pruned', false);

        // Run again: nothing more to prune.
        $this->artisan('site-audit:run-scheduled')->expectsOutputToContain('pruned 0 old audit(s)');
    }
}
