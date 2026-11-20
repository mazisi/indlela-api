<?php

namespace Tests\Feature\RankTracking;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCompetitor;
use App\Models\SiteCompetitorSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthTestHelpers;
use Tests\Support\RankTrackingHelpers;
use Tests\TestCase;

/** Who may see and change competitors (rank tracking's permissions, client access, workspaces), and their history. */
class CompetitorAccessTest extends TestCase
{
    use AuthTestHelpers, RankTrackingHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->travelTo('2026-11-20 10:00:00');
    }

    /** @return array<string, array{0: string, 1: string, 2: array}> name => [method, url, body] */
    private function routes(Site $site, SiteCompetitor $competitor): array
    {
        return [
            'list' => ['GET', "/api/sites/{$site->id}/competitors", []],
            'suggestions' => ['GET', "/api/sites/{$site->id}/competitors/suggestions", []],
            'overview' => ['GET', "/api/sites/{$site->id}/competitors/overview", []],
            'keywords' => ['GET', "/api/sites/{$site->id}/competitors/keywords", []],
            'alerts' => ['GET', "/api/sites/{$site->id}/competitor-alerts", []],
            'settings' => ['GET', "/api/sites/{$site->id}/competitor-settings", []],
            'store' => ['POST', "/api/sites/{$site->id}/competitors", ['domain' => 'new-rival.co.za']],
            'save_settings' => ['PUT', "/api/sites/{$site->id}/competitor-settings", ['alert_recipients' => ['a@b.co.za']]],
            'update' => ['PUT', "/api/competitors/{$competitor->id}", ['name' => 'Renamed']],
            'delete' => ['DELETE', "/api/competitors/{$competitor->id}", []],
        ];
    }

    private const READS = ['list', 'suggestions', 'overview', 'keywords', 'alerts', 'settings'];

    public function test_a_client_login_can_view_but_not_change(): void
    {
        $client = $this->makeClient();
        $site = $this->rankSite($client);
        $competitor = $this->makeCompetitor($site, 'rival.co.za');
        $as = $this->actingAs($this->makeUser(Role::CLIENT, clients: [$client]), 'sanctum');

        foreach ($this->routes($site, $competitor) as $name => [$method, $url, $body]) {
            $as->json($method, $url, $body)->assertStatus(in_array($name, self::READS, true) ? 200 : 403);
        }
        $this->assertSame('rival.co.za', $competitor->fresh()->name);
        $this->assertSame(1, SiteCompetitor::query()->count());
    }

    public function test_an_analyst_only_reaches_the_clients_assigned_to_them(): void
    {
        $mine = $this->makeClient();
        $theirs = $this->makeClient();
        $mySite = $this->rankSite($mine, 'https://mine.co.za');
        $theirSite = $this->rankSite($theirs, 'https://theirs.co.za');
        $myCompetitor = $this->makeCompetitor($mySite, 'rival.co.za');
        $theirCompetitor = $this->makeCompetitor($theirSite, 'rival.co.za');
        $as = $this->actingAs($this->makeUser(Role::ANALYST, clients: [$mine]), 'sanctum');

        foreach ($this->routes($theirSite, $theirCompetitor) as [$method, $url, $body]) {
            $as->json($method, $url, $body)->assertForbidden();
        }
        foreach ($this->routes($mySite, $myCompetitor) as $name => [$method, $url, $body]) {
            $as->json($method, $url, $body)->assertSuccessful();
        }
        $this->assertNotSoftDeleted($theirCompetitor);
    }

    public function test_another_workspaces_sites_and_competitors_answer_404(): void
    {
        $b = $this->makeWorkspace('Rival Agency');
        $siteB = $this->rankSite($this->makeClient($b), 'https://rival-agency.co.za');
        $competitorB = $this->makeCompetitor($siteB, 'rival.co.za');
        $as = $this->actingAs($this->ownerOf($this->workspace()), 'sanctum');

        foreach ($this->routes($siteB, $competitorB) as [$method, $url, $body]) {
            $as->json($method, $url, $body)->assertNotFound();
        }
        $this->assertNotSoftDeleted($competitorB);
        $this->assertSame('rival.co.za', $competitorB->fresh()->name);
        $this->assertSame(0, SiteCompetitorSetting::query()->withoutGlobalScopes()->count());
    }

    public function test_changes_are_in_the_history_and_a_removal_can_be_undone_while_there_is_room(): void
    {
        $client = $this->makeClient();
        $site = $this->rankSite($client);
        $manager = $this->makeUser(Role::MANAGER);
        $as = $this->actingAs($manager, 'sanctum');
        $id = $as->postJson("/api/sites/{$site->id}/competitors", ['domain' => 'rival.co.za'])->assertCreated()->json('data.id');
        $as->putJson("/api/sites/{$site->id}/competitor-settings", ['alert_recipients' => ['a@karoo.co.za']])->assertOk();
        $as->deleteJson("/api/competitors/{$id}")->assertNoContent();
        $deleted = AuditLog::query()->where('table_name', 'site_competitors')->where('action_type', 'DELETE')->sole();

        $entries = $as->getJson('/api/settings/change-history?page_key=rank_tracking')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['Competitor', 'Competitor alerts'], array_unique(array_column($entries, 'record_type')));

        // Five others took its place meanwhile.
        foreach (range(1, 5) as $i) {
            $as->postJson("/api/sites/{$site->id}/competitors", ['domain' => "other{$i}.co.za"])->assertCreated();
        }
        $as->postJson("/api/settings/change-history/{$deleted->id}/undo")->assertUnprocessable()->assertJsonValidationErrors(['undo' => 'up to 5 competitors']);

        SiteCompetitor::query()->where('domain', 'other1.co.za')->sole()->delete();
        $as->postJson("/api/settings/change-history/{$deleted->id}/undo")->assertOk();
        $this->assertNotSoftDeleted('site_competitors', ['id' => $id]);

        // A user limited to another client doesn't see these entries.
        $other = $this->makeUser(Role::ANALYST, clients: [$this->makeClient()]);
        $this->inWorkspace($this->workspace(), fn () => Role::inWorkspace($this->workspace()->id)->where('name', Role::ANALYST)->firstOrFail()->givePermissionTo('view_settings_change_history'));
        $this->assertSame([], $this->actingAs($other->fresh(), 'sanctum')->getJson('/api/settings/change-history?page_key=rank_tracking')->assertOk()->json('data'));
    }
}
