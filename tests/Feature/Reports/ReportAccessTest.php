<?php

namespace Tests\Feature\Reports;

use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\AuthTestHelpers;
use Tests\Support\ReportHelpers;
use Tests\TestCase;

/** Permissions, client access and tenancy on every report route. */
class ReportAccessTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase, ReportHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        Storage::fake('s3');
        $this->travelTo('2026-11-20 10:00:00');
    }

    private const SCHEDULE = ['is_active' => true, 'day_of_month' => 3, 'recipients' => ['owner@karoo.co.za'], 'sections' => ['search_console'], 'compare' => 'previous', 'note' => null];

    public function test_a_client_login_views_its_own_reports_only(): void
    {
        $client = $this->makeClient();
        $site = $this->reportSite($client);
        $report = $this->makeReport($site, ['sections' => ['site_audit']]);
        $other = $this->makeReport($this->reportSite(), ['sections' => ['site_audit']]);
        $user = $this->makeUser(Role::CLIENT, clients: [$client]);
        $as = $this->actingAs($user, 'sanctum');

        $as->getJson('/api/reports/sites')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $site->id);
        $as->getJson("/api/sites/{$site->id}/reports")->assertOk()->assertJsonCount(1, 'data');
        $as->getJson("/api/reports/{$report->id}")->assertOk();
        $as->get("/api/reports/{$report->id}/pdf")->assertOk();
        $as->getJson("/api/sites/{$site->id}/report-schedule")->assertOk()->assertJsonPath('data', null);

        // Read-only.
        $as->postJson("/api/sites/{$site->id}/reports", ['period' => 'last_month', 'sections' => ['site_audit']])->assertForbidden();
        $as->postJson("/api/reports/{$report->id}/send", ['recipients' => ['a@b.co.za']])->assertForbidden();
        $as->postJson("/api/reports/{$report->id}/share")->assertForbidden();
        $as->deleteJson("/api/reports/{$report->id}/share")->assertForbidden();
        $as->deleteJson("/api/reports/{$report->id}")->assertForbidden();
        $as->putJson("/api/sites/{$site->id}/report-schedule", self::SCHEDULE)->assertForbidden();

        // Another client's site in the same workspace.
        $as->getJson("/api/reports/{$other->id}")->assertForbidden();
        $as->getJson("/api/sites/{$other->site_id}/reports")->assertForbidden();
    }

    public function test_create_and_delete_are_separate_permissions(): void
    {
        $site = $this->reportSite();
        $report = $this->makeReport($site, ['sections' => ['site_audit']]);
        $role = Role::inWorkspace($this->workspace()->id)->where('name', Role::ANALYST)->firstOrFail();
        $user = $this->makeUser(Role::ANALYST, clients: [$site->client]);
        $this->inWorkspace($this->workspace(), fn () => $role->syncPermissions(['view_reports', 'create_reports']));
        $as = $this->actingAs($user, 'sanctum');

        $as->postJson("/api/reports/{$report->id}/share")->assertOk();
        $as->putJson("/api/sites/{$site->id}/report-schedule", self::SCHEDULE)->assertOk();
        $as->deleteJson("/api/reports/{$report->id}")->assertForbidden();

        $this->inWorkspace($this->workspace(), fn () => $role->syncPermissions(['view_reports', 'delete_reports']));
        $user->unsetRelation('roles')->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $as->postJson("/api/sites/{$site->id}/reports", ['period' => 'last_month', 'sections' => ['site_audit']])->assertForbidden();
        $as->deleteJson("/api/reports/{$report->id}")->assertNoContent();
    }

    public function test_default_roles(): void
    {
        $this->assertTrue($this->userCan($this->makeUser(Role::MANAGER), 'delete_reports'));
        $analyst = $this->makeUser(Role::ANALYST);
        $this->assertTrue($this->userCan($analyst, 'create_reports'));
        $this->assertTrue($this->userCan($analyst, 'delete_reports'));
        $client = $this->makeUser(Role::CLIENT);
        $this->assertTrue($this->userCan($client, 'view_reports'));
        $this->assertFalse($this->userCan($client, 'create_reports'));
    }

    public function test_another_workspaces_reports_answer_404_everywhere(): void
    {
        $b = $this->makeWorkspace('Rival Agency');
        $siteB = $this->reportSite($this->makeClient($b));
        $reportB = $this->makeReport($siteB, ['sections' => ['site_audit']]);
        ReportSchedule::query()->withoutGlobalScopes()->create(['workspace_id' => $b->id, 'site_id' => $siteB->id, ...self::SCHEDULE]);
        $siteA = $this->reportSite();
        $as = $this->actingAs($this->ownerOf($this->workspace()), 'sanctum');

        $as->getJson('/api/reports/sites')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $siteA->id);
        $as->getJson("/api/sites/{$siteB->id}/reports")->assertNotFound();
        $as->postJson("/api/sites/{$siteB->id}/reports", ['period' => 'last_month', 'sections' => ['site_audit']])->assertNotFound();
        $as->getJson("/api/sites/{$siteB->id}/report-schedule")->assertNotFound();
        $as->putJson("/api/sites/{$siteB->id}/report-schedule", self::SCHEDULE)->assertNotFound();
        $as->getJson("/api/reports/{$reportB->id}")->assertNotFound();
        $as->get("/api/reports/{$reportB->id}/pdf")->assertNotFound();
        $as->postJson("/api/reports/{$reportB->id}/send", ['recipients' => ['a@b.co.za']])->assertNotFound();
        $as->postJson("/api/reports/{$reportB->id}/share")->assertNotFound();
        $as->deleteJson("/api/reports/{$reportB->id}/share")->assertNotFound();
        $as->deleteJson("/api/reports/{$reportB->id}")->assertNotFound();

        $this->assertSame(Report::READY, $reportB->fresh()->status);
        $this->assertNull($reportB->fresh()->share_token);
    }

    public function test_the_schedule_is_saved_and_validated(): void
    {
        $site = $this->reportSite();
        $as = $this->actingAs($this->makeUser(Role::MANAGER), 'sanctum');

        $data = $as->putJson("/api/sites/{$site->id}/report-schedule", [...self::SCHEDULE, 'recipients' => ['Owner@Karoo.co.za', 'owner@karoo.co.za'], 'note' => '  '])->assertOk()->json('data');
        $this->assertSame([
            'site_id' => $site->id, 'is_active' => true, 'day_of_month' => 3, 'send_hour' => 8, 'recipients' => ['owner@karoo.co.za'], 'sections' => ['search_console'],
            'compare' => 'previous', 'note' => null, 'last_run_at' => null, 'next_run_on' => '2026-12-03', 'next_run_at' => '2026-12-03T08:00:00+02:00',
        ], $data);
        // The hour is kept when omitted, and can be changed.
        $as->putJson("/api/sites/{$site->id}/report-schedule", [...self::SCHEDULE, 'send_hour' => 17])
            ->assertJsonPath('data.send_hour', 17)->assertJsonPath('data.next_run_at', '2026-12-03T17:00:00+02:00');
        $as->putJson("/api/sites/{$site->id}/report-schedule", self::SCHEDULE)->assertJsonPath('data.send_hour', 17);
        $as->getJson("/api/sites/{$site->id}/report-schedule")->assertJsonPath('data.day_of_month', 3);
        $this->assertSame(3, $as->getJson('/api/reports/sites')->json('data.0.schedule.day_of_month'));

        $as->putJson("/api/sites/{$site->id}/report-schedule", [...self::SCHEDULE, 'day_of_month' => 25])->assertJsonPath('data.next_run_on', '2026-11-25');
        $as->putJson("/api/sites/{$site->id}/report-schedule", [...self::SCHEDULE, 'is_active' => false])->assertJsonPath('data.next_run_on', null);
        $this->assertSame(1, ReportSchedule::query()->withoutGlobalScopes()->count(), 'one per site');

        $as->putJson("/api/sites/{$site->id}/report-schedule", [...self::SCHEDULE, 'day_of_month' => 29, 'send_hour' => 24, 'recipients' => [], 'sections' => [], 'compare' => 'x'])
            ->assertJsonValidationErrors(['day_of_month', 'send_hour', 'recipients', 'sections', 'compare']);
    }
}
