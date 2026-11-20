<?php

namespace Tests\Feature\LocalGrid;

use App\Models\Client;
use App\Models\MapGrid;
use App\Models\MapGridScan;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Workspace;
use App\Support\Permissions;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthTestHelpers;
use Tests\Support\LocalGridHelpers;
use Tests\TestCase;

/** Who may see and change local grids: permissions, client access, workspaces, default roles. */
class LocalGridAccessTest extends TestCase
{
    use AuthTestHelpers, LocalGridHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->travelTo('2026-11-20 10:00:00');
        $this->configureDataForSeo();
        $this->answerMaps(fn () => 1);
    }

    /** @return array<string, array{0: string, 1: string, 2: array}> name => [method, url, body] */
    private function routes(Site $site, MapGrid $grid, MapGridScan $scan): array
    {
        return [
            'list' => ['GET', "/api/sites/{$site->id}/local-grids", []],
            'show' => ['GET', "/api/local-grids/{$grid->id}", []],
            'scan_show' => ['GET', "/api/local-grid-scans/{$scan->id}", []],
            'search' => ['POST', "/api/sites/{$site->id}/local-grids/business-search", ['query' => 'karoo']],
            'store' => ['POST', "/api/sites/{$site->id}/local-grids", $this->gridBody(['keyword' => 'new keyword'])],
            'update' => ['PUT', "/api/local-grids/{$grid->id}", ['schedule' => 'weekly']],
            'scan' => ['POST', "/api/local-grids/{$grid->id}/scan", []],
            'delete' => ['DELETE', "/api/local-grids/{$grid->id}", []],
        ];
    }

    private const READS = ['list', 'show', 'scan_show'];

    /** @return array{Site, MapGrid, MapGridScan} */
    private function fixture(?Client $client = null, string $url = 'https://www.karoo.co.za'): array
    {
        $site = $this->rankSite($client, $url);
        $grid = $this->makeGrid($site);

        return [$site, $grid, $this->makeScan($grid, array_fill(0, 9, 2))];
    }

    public function test_the_default_roles_get_the_page_at_their_levels(): void
    {
        $this->assertContains('delete_local_grid', Permissions::all());
        $manager = $this->makeUser(Role::MANAGER);
        $analyst = $this->makeUser(Role::ANALYST);
        $client = $this->makeUser(Role::CLIENT);

        $this->assertTrue($this->userCan($manager, 'delete_local_grid'));
        $this->assertTrue($this->userCan($analyst, 'create_local_grid'));
        $this->assertTrue($this->userCan($analyst, 'update_local_grid'));
        $this->assertTrue($this->userCan($client, 'view_local_grid'));
        $this->assertFalse($this->userCan($client, 'update_local_grid'));
    }

    public function test_existing_workspaces_get_a_new_page_from_the_seeder_without_losing_their_edits(): void
    {
        $workspace = $this->workspace();
        $analyst = Role::inWorkspace($workspace->id)->where('name', Role::ANALYST)->firstOrFail();
        // A workspace made before the page existed, whose Analyst role was edited since.
        Permission::query()->where('name', 'like', '%_local_grid')->delete();
        $this->inWorkspace($workspace, fn () => $analyst->revokePermissionTo('update_rank_tracking'));
        $this->assertFalse($analyst->fresh()->permissions->contains('name', 'view_local_grid'));

        $this->seed(RolePermissionSeeder::class);

        $names = fn (string $role) => Role::inWorkspace($workspace->id)->where('name', $role)->firstOrFail()->permissions()->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['view_local_grid', 'create_local_grid', 'update_local_grid', 'delete_local_grid'], array_values(array_filter($names(Role::ANALYST), fn ($n) => str_ends_with($n, '_local_grid'))));
        $this->assertNotContains('update_rank_tracking', $names(Role::ANALYST), 'the edit is kept');
        $this->assertSame(['view_local_grid'], array_values(array_filter($names(Role::CLIENT), fn ($n) => str_ends_with($n, '_local_grid'))));
        $this->assertContains('delete_local_grid', $names(Role::OWNER));

        // A second run adds nothing back that a workspace took away.
        $this->inWorkspace($workspace, fn () => Role::inWorkspace($workspace->id)->where('name', Role::CLIENT)->firstOrFail()->revokePermissionTo('view_local_grid'));
        $this->seed(RolePermissionSeeder::class);
        $this->assertNotContains('view_local_grid', $names(Role::CLIENT));
        $this->assertSame(1, Workspace::query()->count());
    }

    public function test_a_client_login_can_view_but_not_change(): void
    {
        $client = $this->makeClient();
        [$site, $grid, $scan] = $this->fixture($client);
        $as = $this->actingAs($this->makeUser(Role::CLIENT, clients: [$client]), 'sanctum');

        foreach ($this->routes($site, $grid, $scan) as $name => [$method, $url, $body]) {
            $as->json($method, $url, $body)->assertStatus(in_array($name, self::READS, true) ? 200 : 403);
        }
        $as->getJson('/api/local-grid/sites')->assertOk()->assertJsonCount(1, 'data');
        $as->getJson('/api/local-grid/usage')->assertOk();
        $this->assertSame(1, MapGrid::query()->count());
        $this->assertSame(1, MapGridScan::query()->count());
        $this->assertArrayNotHasKey('POST /v3/serp/google/maps/live/advanced', $this->dfs);
    }

    public function test_an_analyst_works_only_on_the_clients_assigned_to_them(): void
    {
        $mine = $this->makeClient();
        $theirs = $this->makeClient();
        [$mySite, $myGrid] = $this->fixture($mine, 'https://mine.co.za');
        [$theirSite, $theirGrid, $theirScan] = $this->fixture($theirs, 'https://theirs.co.za');
        $as = $this->actingAs($this->makeUser(Role::ANALYST, clients: [$mine]), 'sanctum');

        foreach ($this->routes($theirSite, $theirGrid, $theirScan) as [$method, $url, $body]) {
            $as->json($method, $url, $body)->assertForbidden();
        }
        $as->getJson('/api/local-grid/sites')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mySite->id);
        // Usage is the workspace's: both grids count.
        $as->getJson('/api/local-grid/usage')->assertJsonPath('data.grids.used', 2);

        $as->postJson("/api/local-grids/{$myGrid->id}/scan")->assertStatus(202);
        $as->deleteJson("/api/local-grids/{$myGrid->id}")->assertNoContent();
        $this->assertNotSoftDeleted($theirGrid);
    }

    public function test_without_the_permission_nothing_is_visible(): void
    {
        $role = Role::inWorkspace($this->workspace()->id)->where('name', Role::CLIENT)->firstOrFail();
        $this->inWorkspace($this->workspace(), fn () => $role->revokePermissionTo('view_local_grid'));
        $client = $this->makeClient();
        [$site, $grid, $scan] = $this->fixture($client);
        $as = $this->actingAs($this->makeUser(Role::CLIENT, clients: [$client]), 'sanctum');

        foreach (['/api/local-grid/sites', '/api/local-grid/usage', "/api/sites/{$site->id}/local-grids", "/api/local-grids/{$grid->id}", "/api/local-grid-scans/{$scan->id}"] as $url) {
            $as->getJson($url)->assertForbidden();
        }
    }

    public function test_another_workspaces_grids_and_scans_answer_404_on_every_route(): void
    {
        $a = $this->workspace();
        $b = $this->makeWorkspace('Rival Agency');
        [$siteB, $gridB, $scanB] = $this->fixture($this->makeClient($b), 'https://rival.co.za');
        [$siteA] = $this->fixture($this->makeClient($a), 'https://mine.co.za');
        $as = $this->actingAs($this->ownerOf($a), 'sanctum');

        foreach ($this->routes($siteB, $gridB, $scanB) as [$method, $url, $body]) {
            $as->json($method, $url, $body)->assertNotFound();
        }
        $as->getJson('/api/local-grid/sites')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $siteA->id);
        $as->getJson('/api/local-grid/usage')->assertJsonPath('data.grids.used', 1);

        $this->assertNotSoftDeleted($gridB);
        $this->assertSame('none', $gridB->fresh()->schedule);
        $this->assertSame(1, MapGridScan::query()->withoutGlobalScopes()->where('grid_id', $gridB->id)->count());
    }
}
