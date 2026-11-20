<?php

namespace Tests\Feature\SiteAudit;

use App\Models\SiteAudit;
use App\Models\SiteAuditPage;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AuthTestHelpers;
use Tests\Support\SiteAuditHelpers;
use Tests\TestCase;

/** Workspace A can't see or touch workspace B's audits, whatever its role. */
class SiteAuditTenancyTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase, SiteAuditHelpers;

    public function test_another_workspaces_sites_and_audits_answer_404_on_every_route(): void
    {
        $this->setUpAuth();
        Queue::fake();
        $a = $this->workspace();
        $b = $this->makeWorkspace('Rival Agency');
        $siteB = $this->auditSite($this->makeClient($b), 'https://rival.co.za');
        $auditB = $this->makeAudit($siteB, ['status' => SiteAudit::CRAWLING, 'finished_at' => null]);
        $pageB = SiteAuditPage::query()->create(['audit_id' => $auditB->id, 'url' => 'https://rival.co.za/', 'url_hash' => md5('x'), 'status_code' => 200]);

        // A's own audit, to prove a B page can't be reached through it either.
        $siteA = $this->auditSite($this->makeClient($a), 'https://mine.co.za');
        $auditA = $this->makeAudit($siteA);

        $as = $this->actingAs($this->ownerOf($a), 'sanctum');

        $as->getJson('/api/site-audit/sites')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $siteA->id);
        $as->getJson("/api/sites/{$siteB->id}/audits")->assertNotFound();
        $as->postJson("/api/sites/{$siteB->id}/audits")->assertNotFound();
        $as->putJson("/api/sites/{$siteB->id}/audit-settings", ['audit_schedule' => 'weekly', 'audit_page_limit' => 100])->assertNotFound();

        foreach (['', '/issues', '/issues/missing_h1', '/pages', "/pages/{$pageB->id}", '/vitals', '/comparison'] as $path) {
            $as->getJson("/api/audits/{$auditB->id}{$path}")->assertNotFound();
        }
        $as->postJson("/api/audits/{$auditB->id}/cancel")->assertNotFound();
        $as->getJson("/api/audits/{$auditA->id}/pages/{$pageB->id}")->assertNotFound();

        $this->assertSame(SiteAudit::CRAWLING, $auditB->fresh()->status);
        $this->assertSame('off', $siteB->fresh()->audit_schedule);
        $this->assertSame(1, SiteAudit::query()->withoutGlobalScopes()->where('site_id', $siteB->id)->count());
        $this->assertInstanceOf(Workspace::class, $b);
    }
}
