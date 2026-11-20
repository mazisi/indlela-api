<?php

namespace Tests\Feature\RankTracking;

use App\Jobs\RefreshRankLocations;
use App\Models\RankCheck;
use App\Models\RankKeyword;
use App\Models\Role;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AuthTestHelpers;
use Tests\Support\RankTrackingHelpers;
use Tests\TestCase;

/** Who may see and change rank tracking: permissions, client access, workspaces. */
class RankTrackingAccessTest extends TestCase
{
    use AuthTestHelpers, RankTrackingHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->travelTo('2026-11-20 10:00:00');
        $this->configureDataForSeo();
        Queue::fake();
    }

    /** @return array<string, array{0: string, 1: string, 2: array}> name => [method, url, body] */
    private function routes(Site $site, RankKeyword $keyword): array
    {
        return [
            'list' => ['GET', "/api/sites/{$site->id}/rank-keywords", []],
            'summary' => ['GET', "/api/sites/{$site->id}/rank-tracking/summary", []],
            'cannibalisation' => ['GET', "/api/sites/{$site->id}/rank-tracking/cannibalisation", []],
            'history' => ['GET', "/api/rank-keywords/{$keyword->id}/history", []],
            'store' => ['POST', "/api/sites/{$site->id}/rank-keywords", ['keywords' => ['new kw'], 'locations' => [['type' => 'code', 'code' => 2710, 'name' => 'SA']], 'devices' => ['desktop'], 'frequency' => 'daily']],
            'bulk' => ['POST', "/api/sites/{$site->id}/rank-keywords/bulk", ['action' => 'deactivate', 'ids' => [$keyword->id]]],
            'update' => ['PUT', "/api/rank-keywords/{$keyword->id}", ['is_active' => false]],
            'check' => ['POST', "/api/rank-keywords/{$keyword->id}/check", []],
            'delete' => ['DELETE', "/api/rank-keywords/{$keyword->id}", []],
        ];
    }

    private const READS = ['list', 'summary', 'cannibalisation', 'history'];

    public function test_a_client_login_can_view_but_not_change(): void
    {
        $client = $this->makeClient();
        $site = $this->rankSite($client);
        $keyword = $this->makeKeyword($site);
        $this->history($keyword, [0 => 3]);
        $user = $this->makeUser(Role::CLIENT, clients: [$client]);
        $as = $this->actingAs($user, 'sanctum');

        foreach ($this->routes($site, $keyword) as $name => [$method, $url, $body]) {
            $as->json($method, $url, $body)->assertStatus(in_array($name, self::READS, true) ? 200 : 403);
        }
        $as->getJson('/api/rank-tracking/sites')->assertOk()->assertJsonCount(1, 'data');
        $as->getJson('/api/rank-tracking/usage')->assertOk();
        // Allowed (not 403): with no list yet the search queues its download.
        $as->getJson('/api/rank-tracking/locations?search=x')->assertStatus(409)->assertJsonPath('error_type', 'locations_loading');
        Queue::assertPushed(RefreshRankLocations::class);

        $this->assertTrue($keyword->fresh()->is_active);
        $this->assertSame(1, RankKeyword::query()->count());
        $this->assertSame(RankCheck::DONE, RankCheck::query()->value('status'));
    }

    public function test_an_analyst_changes_only_the_clients_assigned_to_them(): void
    {
        $mine = $this->makeClient();
        $theirs = $this->makeClient();
        $mySite = $this->rankSite($mine, 'https://mine.co.za');
        $theirSite = $this->rankSite($theirs, 'https://theirs.co.za');
        $myKeyword = $this->makeKeyword($mySite);
        $theirKeyword = $this->makeKeyword($theirSite);
        $this->history($theirKeyword, [0 => 2]);
        $this->addCheck($this->makeKeyword($mySite, 'cost'), '2026-11-20', 4, ['cost' => 0.25]);
        $analyst = $this->makeUser(Role::ANALYST, clients: [$mine]);
        $as = $this->actingAs($analyst, 'sanctum');

        foreach ($this->routes($theirSite, $theirKeyword) as [$method, $url, $body]) {
            $as->json($method, $url, $body)->assertForbidden();
        }
        $as->getJson('/api/rank-tracking/sites')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mySite->id);
        // Usage is the workspace's allowance: every site's keywords count.
        $as->getJson('/api/rank-tracking/usage')->assertJsonPath('data.keywords_tracked', 3)->assertJsonMissingPath('data.this_month');

        $as->putJson("/api/rank-keywords/{$myKeyword->id}", ['is_active' => false])->assertOk();
        $as->postJson("/api/rank-keywords/{$myKeyword->id}/check")->assertStatus(202);
        $as->deleteJson("/api/rank-keywords/{$myKeyword->id}")->assertNoContent();
        $this->assertNotSoftDeleted($theirKeyword);
    }

    public function test_without_the_permission_nothing_is_visible(): void
    {
        $role = Role::inWorkspace($this->workspace()->id)->where('name', Role::CLIENT)->firstOrFail();
        $this->inWorkspace($this->workspace(), fn () => $role->revokePermissionTo('view_rank_tracking'));
        $client = $this->makeClient();
        $site = $this->rankSite($client);
        $keyword = $this->makeKeyword($site);
        $as = $this->actingAs($this->makeUser(Role::CLIENT, clients: [$client]), 'sanctum');

        foreach (['/api/rank-tracking/sites', '/api/rank-tracking/usage', '/api/rank-tracking/locations', "/api/sites/{$site->id}/rank-keywords", "/api/rank-keywords/{$keyword->id}/history"] as $url) {
            $as->getJson($url)->assertForbidden();
        }
    }

    public function test_another_workspaces_sites_and_keywords_answer_404_on_every_route(): void
    {
        $a = $this->workspace();
        $b = $this->makeWorkspace('Rival Agency');
        $siteB = $this->rankSite($this->makeClient($b), 'https://rival.co.za');
        $keywordB = $this->makeKeyword($siteB);
        $this->history($keywordB, [0 => 1]);
        $siteA = $this->rankSite($this->makeClient($a), 'https://mine.co.za');
        $keywordA = $this->makeKeyword($siteA);
        $as = $this->actingAs($this->ownerOf($a), 'sanctum');

        foreach ($this->routes($siteB, $keywordB) as [$method, $url, $body]) {
            $as->json($method, $url, $body)->assertNotFound();
        }
        // B's keyword through A's own site.
        $as->postJson("/api/sites/{$siteA->id}/rank-keywords/bulk", ['action' => 'delete', 'ids' => [$keywordB->id]])->assertUnprocessable();

        $as->getJson('/api/rank-tracking/sites')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $siteA->id);
        $as->getJson('/api/rank-tracking/usage')->assertJsonPath('data.keywords_tracked', 1);
        $as->getJson("/api/sites/{$siteA->id}/rank-keywords")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $keywordA->id);

        $this->assertNotSoftDeleted($keywordB);
        $this->assertTrue($keywordB->fresh()->is_active);
        $this->assertSame(1, RankKeyword::query()->withoutGlobalScopes()->where('site_id', $siteB->id)->count());
        $this->assertSame(1, RankCheck::query()->withoutGlobalScopes()->where('keyword_id', $keywordB->id)->count());
    }
}
