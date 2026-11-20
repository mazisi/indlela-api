<?php

namespace Tests\Feature\SiteAudit;

use App\Jobs\RunSiteAudit;
use App\Models\Role;
use App\Models\SiteAudit;
use App\Models\User;
use App\Services\SiteAudit\AuditAnalyser;
use App\Services\SiteAudit\SiteCrawler;
use App\Support\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AuthTestHelpers;
use Tests\Support\SiteAuditHelpers;
use Tests\TestCase;

class SiteAuditApiTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase, SiteAuditHelpers;

    private const S = 'https://www.karoo.co.za';

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->manager = $this->makeUser(Role::MANAGER, ['full_name' => 'Thandi', 'surname' => 'Mokoena']);
    }

    private function crawlableWeb(): void
    {
        $s = self::S;
        $this->fakeWeb();
        $this->web = [
            "{$s}/sitemap.xml" => $this->sitemap(["{$s}/", "{$s}/a"]),
            "{$s}/" => $this->html('Karoo Coffee Roasters | Fresh coffee beans', 'Fresh', ['/a', '/gone'], ['canonical' => "{$s}/"]),
            "{$s}/a" => $this->html('Karoo Coffee page A | Karoo Coffee Roasters', 'A', ['/'], ['canonical' => "{$s}/a", 'h1' => []]),
        ];
    }

    public function test_running_an_audit_end_to_end_and_reading_its_results(): void
    {
        $this->crawlableWeb();
        $client = $this->makeClient(state: ['name' => 'Karoo Coffee']);
        $site = $this->auditSite($client);
        $as = $this->actingAs($this->manager, 'sanctum');

        $response = $as->postJson("/api/sites/{$site->id}/audits")->assertStatus(202);
        $id = $response->json('data.id');
        $response->assertJsonPath('data.status', 'done') // sync queue in tests
            ->assertJsonPath('data.trigger', 'manual')
            ->assertJsonPath('data.triggered_by', ['id' => $this->manager->id, 'name' => 'Thandi Mokoena'])
            ->assertJsonPath('data.pages_crawled', 3)
            ->assertJsonPath('data.counts', ['critical' => 2, 'warning' => 1, 'notice' => 0, 'total' => 3])
            ->assertJsonPath('data.comparison', null)
            ->assertJsonPath('data.vitals_status', 'done');
        $this->assertSame(['id', 'site_id', 'status', 'trigger', 'triggered_by', 'page_limit', 'pages_crawled', 'pages_found', 'started_at', 'finished_at',
            'error', 'health_score', 'counts', 'comparison', 'vitals_status', 'vitals_message', 'details_pruned', 'created_at'], array_keys($response->json('data')));

        $as->getJson("/api/audits/{$id}")->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.health_score', $response->json('data.health_score'));

        $as->getJson('/api/site-audit/sites')->assertOk()
            ->assertJsonPath('data.0.id', $site->id)
            ->assertJsonPath('data.0.client', ['id' => $client->id, 'name' => 'Karoo Coffee'])
            ->assertJsonPath('data.0.audit_schedule', 'off')
            ->assertJsonPath('data.0.audit_page_limit', 500)
            ->assertJsonPath('data.0.latest_audit.id', $id)
            ->assertJsonPath('data.0.running_audit', null);

        $as->getJson("/api/sites/{$site->id}/audits")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 15)->assertJsonPath('data.0.id', $id);

        // Issues: critical first, with the catalogue text.
        $issues = $as->getJson("/api/audits/{$id}/issues")->assertOk()->json('data');
        $this->assertSame(['broken_internal_link', 'page_4xx', 'missing_h1'], array_column($issues, 'type')); // ties by type
        $this->assertSame(['type', 'severity', 'title', 'why', 'how_to_fix', 'count', 'new_count'], array_keys($issues[0]));
        $this->assertStringContainsString('Elementor', $issues[2]['how_to_fix']);

        $as->getJson("/api/audits/{$id}/issues/broken_internal_link")->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.url', self::S.'/')
            ->assertJsonPath('data.0.details', ['to_url' => self::S.'/gone', 'status_code' => 404, 'kind' => 'link'])
            ->assertJsonPath('data.0.is_new', false)
            ->assertJsonPath('issue.title', 'Broken internal links');
        $as->getJson("/api/audits/{$id}/issues/missing_h1?search=GONE")->assertOk()->assertJsonPath('meta.total', 0);
        $as->getJson("/api/audits/{$id}/issues/not_a_type")->assertNotFound();
        $as->getJson("/api/audits/{$id}/issues/thin_content")->assertOk()->assertJsonPath('meta.total', 0);

        // Pages: filters and sort.
        $as->getJson("/api/audits/{$id}/pages")->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.url', self::S.'/')->assertJsonPath('data.0.issues_count', 1);
        $as->getJson("/api/audits/{$id}/pages?status=4xx")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status_code', 404);
        $as->getJson("/api/audits/{$id}/pages?indexable=1")->assertOk()->assertJsonPath('meta.total', 2);
        $as->getJson("/api/audits/{$id}/pages?sort=-inlinks_count")->assertOk()->assertJsonPath('data.0.inlinks_count', 1);
        $as->getJson("/api/audits/{$id}/pages?search=/a")->assertOk()->assertJsonPath('meta.total', 1);
        $as->getJson("/api/audits/{$id}/pages?sort=title")->assertJsonValidationErrors('sort');
        $as->getJson("/api/audits/{$id}/pages?status=6xx")->assertJsonValidationErrors('status');

        $pageA = SiteAudit::find($id)->pages()->where('url', self::S.'/a')->first();
        $as->getJson("/api/audits/{$id}/pages/{$pageA->id}")->assertOk()
            ->assertJsonPath('data.url', self::S.'/a')
            ->assertJsonPath('data.issues.0.type', 'missing_h1')
            ->assertJsonPath('data.inlinks', [['page_id' => SiteAudit::find($id)->pages()->where('url', self::S.'/')->value('id'), 'url' => self::S.'/', 'nofollow' => false]])
            ->assertJsonPath('data.inlinks_total', 1)
            ->assertJsonPath('data.redirect_chain', null);
    }

    public function test_one_audit_at_a_time_per_site(): void
    {
        Queue::fake();
        $site = $this->auditSite();
        $as = $this->actingAs($this->manager, 'sanctum');

        $id = $as->postJson("/api/sites/{$site->id}/audits")->assertStatus(202)->assertJsonPath('data.status', 'queued')->json('data.id');
        Queue::assertPushed(RunSiteAudit::class, fn (RunSiteAudit $job) => $job->auditId === $id && $job->workspaceId === $this->workspace()->id);

        $as->postJson("/api/sites/{$site->id}/audits")->assertStatus(409)->assertJsonPath('error_type', 'audit_running');
        $as->getJson('/api/site-audit/sites')->assertJsonPath('data.0.running_audit.id', $id)->assertJsonPath('data.0.latest_audit', null);

        // Cancel, then a new one may start.
        $as->postJson("/api/audits/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $as->postJson("/api/audits/{$id}/cancel")->assertStatus(409)->assertJsonPath('error_type', 'audit_not_running');
        // ...and its job is queued even though the cancelled audit's job hasn't
        // run yet (a per-site unique lock used to drop it, stuck at "queued").
        $second = $as->postJson("/api/sites/{$site->id}/audits")->assertStatus(202)->json('data.id');
        Queue::assertPushed(RunSiteAudit::class, fn (RunSiteAudit $job) => $job->auditId === $second);

        // The cancelled audit's job does nothing when it runs.
        (new RunSiteAudit($id, $this->workspace()->id))->handle(app(SiteCrawler::class), app(AuditAnalyser::class), app(CurrentWorkspace::class));
        $this->assertSame(0, SiteAudit::query()->withoutGlobalScopes()->find($id)->pages()->count());
    }

    public function test_audit_settings(): void
    {
        $site = $this->auditSite();
        $as = $this->actingAs($this->manager, 'sanctum');

        $as->putJson("/api/sites/{$site->id}/audit-settings", ['audit_schedule' => 'weekly', 'audit_page_limit' => 1200])->assertOk()
            ->assertJsonPath('data.id', $site->id)->assertJsonPath('data.audit_schedule', 'weekly')->assertJsonPath('data.audit_page_limit', 1200)
            ->assertJsonPath('data.latest_audit', null);
        $as->putJson("/api/sites/{$site->id}/audit-settings", ['audit_schedule' => 'daily', 'audit_page_limit' => 6000])
            ->assertJsonValidationErrors(['audit_schedule', 'audit_page_limit']);
        $as->putJson("/api/sites/{$site->id}/audit-settings", ['audit_schedule' => 'off', 'audit_page_limit' => 5])
            ->assertJsonValidationErrors(['audit_page_limit']);

        $this->assertSame('weekly', $site->fresh()->audit_schedule);
    }

    public function test_failed_job_marks_the_audit_failed(): void
    {
        Queue::fake();
        $site = $this->auditSite();
        $audit = $this->makeAudit($site, ['status' => SiteAudit::CRAWLING, 'finished_at' => null]);

        (new RunSiteAudit($audit->id, $site->workspace_id))->failed(new \RuntimeException('Out of memory'));

        $audit->refresh();
        $this->assertSame('failed', $audit->status);
        $this->assertSame('Out of memory', $audit->error);
        $this->assertNotNull($audit->finished_at);
    }

    public function test_client_role_views_but_cannot_start_cancel_or_change_settings(): void
    {
        $client = $this->makeClient();
        $site = $this->auditSite($client);
        $audit = $this->makeAudit($site, ['status' => SiteAudit::QUEUED, 'finished_at' => null]);
        $viewer = $this->makeUser(Role::CLIENT, clients: [$client]);
        $as = $this->actingAs($viewer, 'sanctum');

        $as->getJson('/api/site-audit/sites')->assertOk()->assertJsonCount(1, 'data');
        $as->getJson("/api/sites/{$site->id}/audits")->assertOk();
        $as->getJson("/api/audits/{$audit->id}")->assertOk();
        $as->getJson("/api/audits/{$audit->id}/issues")->assertOk();
        $as->getJson("/api/audits/{$audit->id}/pages")->assertOk();
        $as->postJson("/api/sites/{$site->id}/audits")->assertForbidden();
        $as->postJson("/api/audits/{$audit->id}/cancel")->assertForbidden();
        $as->putJson("/api/sites/{$site->id}/audit-settings", ['audit_schedule' => 'weekly', 'audit_page_limit' => 100])->assertForbidden();
    }

    public function test_users_only_reach_their_clients_sites(): void
    {
        $theirs = $this->makeClient();
        $other = $this->auditSite($this->makeClient(), 'https://other.co.za');
        $audit = $this->makeAudit($other);
        $analyst = $this->makeUser(Role::ANALYST, clients: [$theirs]);
        $this->auditSite($theirs);
        $as = $this->actingAs($analyst, 'sanctum');

        $as->getJson('/api/site-audit/sites')->assertOk()->assertJsonCount(1, 'data');
        $as->getJson("/api/sites/{$other->id}/audits")->assertForbidden();
        $as->postJson("/api/sites/{$other->id}/audits")->assertForbidden();
        $as->getJson("/api/audits/{$audit->id}")->assertForbidden();
        $as->getJson("/api/audits/{$audit->id}/pages")->assertForbidden();
        $as->getJson("/api/audits/{$audit->id}/issues/missing_h1")->assertForbidden();
    }

    public function test_without_the_permission(): void
    {
        $site = $this->auditSite();
        $role = Role::inWorkspace($this->workspace()->id)->where('name', Role::MANAGER)->first();
        $this->inWorkspace($this->workspace(), fn () => $role->revokePermissionTo(['view_site_audit', 'create_site_audit', 'update_site_audit', 'delete_site_audit']));
        $as = $this->actingAs($this->manager->fresh(), 'sanctum');

        $as->getJson('/api/site-audit/sites')->assertForbidden();
        $as->getJson("/api/sites/{$site->id}/audits")->assertForbidden();
    }
}
