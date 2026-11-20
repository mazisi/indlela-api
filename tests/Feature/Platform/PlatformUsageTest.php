<?php

namespace Tests\Feature\Platform;

use App\Models\RankCheck;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Services\RankTracking\RankLimits;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Tests\Support\AuthTestHelpers;
use Tests\Support\RankTrackingHelpers;
use Tests\TestCase;

/**
 * Platform › Usage: only in the platform workspace (indlela.platform_workspace),
 * only with view_platform_usage there; the permission never exists anywhere
 * else; figures are aggregated across every workspace.
 */
class PlatformUsageTest extends TestCase
{
    use AuthTestHelpers, RankTrackingHelpers, RefreshDatabase;

    private Workspace $platform;

    private User $staffOwner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->travelTo('2026-11-20 10:00:00');
        $this->platform = $this->makeWorkspace('Indlela');
        $this->staffOwner = $this->ownerOf($this->platform);
        config(['indlela.platform_workspace' => $this->platform->slug]);
        $this->seed(RolePermissionSeeder::class);
    }

    private function platformRole(string $name): Role
    {
        return Role::inWorkspace($this->platform->id)->where('name', $name)->firstOrFail();
    }

    private function grid(User $user): array
    {
        return $this->actingAs($user, 'sanctum')->getJson('/api/settings/roles')->assertOk()->json('groups');
    }

    // --- Permission and access ------------------------------------------------

    public function test_platform_owner_and_superuser_read_both_endpoints(): void
    {
        $superuser = $this->makeUser(Role::SUPERUSER, workspace: $this->platform);

        foreach ([$this->staffOwner, $superuser] as $user) {
            $this->actingAs($user, 'sanctum')->getJson('/api/platform/usage')->assertOk()->assertJsonPath('data.month', '2026-11');
            $this->actingAs($user, 'sanctum')->getJson('/api/platform/usage/workspaces')->assertOk()->assertJsonStructure(['data', 'links', 'meta']);
            $this->assertContains('view_platform_usage', $this->actingAs($user, 'sanctum')->getJson('/api/me')->json('permissions'));
        }
    }

    public function test_the_superuser_role_sees_every_client_and_has_every_grantable_permission(): void
    {
        $role = $this->platformRole(Role::SUPERUSER);

        $this->assertTrue($role->all_clients);
        $this->assertSame('Indlela staff: everything, plus Platform › Usage', $role->description);
        $this->assertContains('view_platform_usage', $role->permissions->pluck('name'));
        $this->assertContains('update_rank_tracking', $role->permissions->pluck('name'));
        $this->assertNotContains('view_settings_users', $role->permissions->pluck('name'));
    }

    public function test_a_platform_manager_needs_the_permission_granted(): void
    {
        $manager = $this->makeUser(Role::MANAGER, workspace: $this->platform);
        $this->actingAs($manager, 'sanctum')->getJson('/api/platform/usage')->assertForbidden()
            ->assertJsonPath('message', 'Platform usage is only available to Indlela staff.');

        // The platform workspace's Roles grid offers it, and saving it works.
        $platformGroup = collect($this->grid($this->staffOwner))->firstWhere('key', 'platform');
        $this->assertSame([['key' => 'platform_usage', 'label' => 'Usage', 'actions' => ['view'], 'grantable' => true]], $platformGroup['resources']);

        $role = $this->platformRole(Role::MANAGER);
        $names = [...$role->permissions->pluck('name')->all(), 'view_platform_usage'];
        $this->actingAs($this->staffOwner, 'sanctum')->putJson("/api/settings/roles/{$role->id}/permissions", ['permissions' => $names])->assertOk();

        $this->actingAs($manager->fresh(), 'sanctum')->getJson('/api/platform/usage')->assertOk();
        $this->actingAs($manager->fresh(), 'sanctum')->getJson('/api/platform/usage/workspaces')->assertOk();
    }

    public function test_other_workspaces_never_have_the_permission(): void
    {
        $other = $this->makeWorkspace('Acme Digital');
        $owner = $this->ownerOf($other);

        $this->actingAs($owner, 'sanctum')->getJson('/api/platform/usage')->assertForbidden();
        $this->actingAs($owner, 'sanctum')->getJson('/api/platform/usage/workspaces')->assertForbidden();
        $this->actingAs($owner, 'sanctum')->patchJson("/api/platform/workspaces/{$other->id}/limits", ['rank_keyword_limit' => 5000])->assertForbidden();
        $this->assertNull($other->fresh()->rank_keyword_limit);

        $this->assertNotContains('view_platform_usage', $this->actingAs($owner, 'sanctum')->getJson('/api/me')->json('permissions'));
        $this->assertNull(collect($this->grid($owner))->firstWhere('key', 'platform'));
        $ownerRole = collect($this->actingAs($owner, 'sanctum')->getJson('/api/settings/roles')->json('roles'))->firstWhere('name', Role::OWNER);
        $this->assertNotContains('view_platform_usage', $ownerRole['permissions']);
        $this->assertFalse(Role::inWorkspace($other->id)->where('name', Role::SUPERUSER)->exists());

        $manager = Role::inWorkspace($other->id)->where('name', Role::MANAGER)->firstOrFail();
        $this->actingAs($owner, 'sanctum')->putJson("/api/settings/roles/{$manager->id}/permissions", ['permissions' => ['view_platform_usage']])
            ->assertUnprocessable()->assertJsonValidationErrors('permissions.0');
    }

    public function test_switching_to_the_platform_workspace_shows_it_and_switching_away_hides_it(): void
    {
        $other = $this->makeWorkspace('Acme Digital', $this->staffOwner);  // now current: Acme

        $this->actingAs($this->staffOwner, 'sanctum')->getJson('/api/platform/usage')->assertForbidden();
        $switched = $this->actingAs($this->staffOwner, 'sanctum')->postJson("/api/workspaces/{$this->platform->id}/switch")->assertOk();
        $this->assertContains('view_platform_usage', $switched->json('permissions'));
        $this->actingAs($this->staffOwner->fresh(), 'sanctum')->getJson('/api/platform/usage')->assertOk();

        $back = $this->actingAs($this->staffOwner->fresh(), 'sanctum')->postJson("/api/workspaces/{$other->id}/switch")->assertOk();
        $this->assertNotContains('view_platform_usage', $back->json('permissions'));
    }

    public function test_the_feature_is_off_without_a_platform_workspace(): void
    {
        config(['indlela.platform_workspace' => '']);

        $this->actingAs($this->staffOwner, 'sanctum')->getJson('/api/platform/usage')->assertForbidden();
        $this->actingAs($this->staffOwner, 'sanctum')->getJson('/api/platform/usage/workspaces')->assertForbidden();
        $this->assertNotContains('view_platform_usage', $this->actingAs($this->staffOwner, 'sanctum')->getJson('/api/me')->json('permissions'));
        $this->assertNull(collect($this->grid($this->staffOwner))->firstWhere('key', 'platform'));
    }

    public function test_the_seeder_is_idempotent_and_keeps_edits_to_the_superuser(): void
    {
        $role = $this->platformRole(Role::SUPERUSER);
        $role->syncPermissions(['view_dashboard', 'view_platform_usage']);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(1, Role::withTrashed()->inWorkspace($this->platform->id)->where('name', Role::SUPERUSER)->count());
        $this->assertEqualsCanonicalizing(['view_dashboard', 'view_platform_usage'], $role->fresh()->permissions->pluck('name')->all());
        $this->assertContains('view_platform_usage', $this->platformRole(Role::OWNER)->permissions->pluck('name'));
    }

    public function test_moving_the_platform_workspace_takes_the_permission_off_the_old_one(): void
    {
        $next = $this->makeWorkspace('Indlela Staff');
        config(['indlela.platform_workspace' => $next->slug]);

        $this->seed(RolePermissionSeeder::class);

        $this->assertNotContains('view_platform_usage', $this->platformRole(Role::OWNER)->permissions->pluck('name'));
        $this->assertNotContains('view_platform_usage', $this->platformRole(Role::SUPERUSER)->permissions->pluck('name'));
        $this->assertContains('view_platform_usage', Role::inWorkspace($next->id)->where('name', Role::OWNER)->firstOrFail()->permissions->pluck('name'));
        $this->assertTrue(Role::inWorkspace($next->id)->where('name', Role::SUPERUSER)->exists());
    }

    // --- Figures --------------------------------------------------------------

    /** Two customer workspaces with checks across months; returns [a, b]. */
    private function usageFixture(): array
    {
        $a = $this->makeWorkspace('Alpha Agency');
        $b = $this->makeWorkspace('Beta Bakery');
        $siteA = $this->rankSite($this->makeClient($a), 'https://alpha.co.za');
        $siteB = $this->rankSite($this->makeClient($b), 'https://beta.co.za');

        $k1 = $this->makeKeyword($siteA, 'one');
        $k2 = $this->makeKeyword($siteA, 'two');
        $k3 = $this->makeKeyword($siteA, 'three');
        $this->makeKeyword($siteA, 'paused', ['is_active' => false]);
        $this->makeKeyword($siteA, 'removed')->delete();
        $kb = $this->makeKeyword($siteB, 'bread', ['is_active' => false]);

        // Alpha, this month: 2 standard (0.0006), 1 live done (0.002, 1 request), 1 live pending (1 request).
        $this->addCheck($k1, '2026-11-02', 4);
        $this->addCheck($k2, '2026-11-03', 9, ['completed_at' => '2026-11-03 01:30:00']);
        $this->addCheck($k3, '2026-11-18', 2, ['mode' => RankCheck::LIVE, 'cost' => 0.002, 'live_requests' => 1, 'completed_at' => '2026-11-18 14:00:00']);
        $this->addCheck($k1, '2026-11-20', null, ['mode' => RankCheck::LIVE, 'status' => RankCheck::PENDING, 'cost' => 0, 'live_requests' => 1, 'completed_at' => null]);
        // Alpha, last month and three months ago.
        $this->addCheck($k1, '2026-10-15', 5, ['completed_at' => '2026-10-15 01:00:00']);
        $this->addCheck($k2, '2026-08-01', 7, ['cost' => 0.0012, 'completed_at' => '2026-08-01 01:00:00']);
        // Older than the series: not in it.
        $this->addCheck($k3, '2026-01-10', 7, ['cost' => 0.5]);

        // Beta, this month: one charged check and one skipped (never posted, not counted).
        $this->addCheck($kb, '2026-11-10', 3, ['completed_at' => '2026-11-10 01:00:00']);
        $this->addCheck($kb, '2026-11-11', null, ['status' => RankCheck::FAILED, 'posted_at' => null, 'cost' => 0, 'error' => 'Skipped: rank checks paused']);

        return [$a, $b];
    }

    public function test_the_overview_aggregates_every_workspace(): void
    {
        $this->usageFixture();
        config(['indlela.rank_tracking.monthly_spend_cap_usd' => 10]);

        $data = $this->actingAs($this->staffOwner, 'sanctum')->getJson('/api/platform/usage')->assertOk()->json('data');

        // Charged 0.0006 × 3 + 0.002 = 0.0038; pending live 0.002 → 0.0058.
        $this->assertSame('2026-11', $data['month']);
        $this->assertEquals(['this_month' => 0.0058, 'pending' => 0.002, 'cap' => 10, 'cap_used_pct' => 0.1], $data['spend']);
        $this->assertEquals(['configured' => false, 'sandbox' => true, 'balance' => null], $data['provider']);
        $this->assertEquals(['workspace_keyword_limit' => 500, 'check_now_daily_limit' => 20], $data['limits']);
        $this->assertEquals([
            'workspaces' => 3,  // the platform, Alpha, Beta
            'active_workspaces' => 1,
            'keywords_tracked' => 3,
            'checks_this_month' => 5,  // Alpha 4 (incl. the pending live), Beta 1
            'live_checks_this_month' => 2,
        ], $data['totals']);
        $this->assertEquals([
            ['month' => '2026-06', 'cost' => 0, 'checks' => 0],
            ['month' => '2026-07', 'cost' => 0, 'checks' => 0],
            ['month' => '2026-08', 'cost' => 0.0012, 'checks' => 1],
            ['month' => '2026-09', 'cost' => 0, 'checks' => 0],
            ['month' => '2026-10', 'cost' => 0.0006, 'checks' => 1],
            ['month' => '2026-11', 'cost' => 0.0038, 'checks' => 5],
        ], $data['series']);
    }

    public function test_the_provider_balance_is_cached_and_null_on_error(): void
    {
        $this->configureDataForSeo();
        $calls = 0;
        $this->dfsRoutes['/v3/appendix/user_data'] = function (Request $r) use (&$calls) {
            $calls++;

            return $this->envelope([['id' => 'u', 'status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0, 'result' => [['money' => ['balance' => 42.5]]]]]);
        };

        $this->actingAs($this->staffOwner, 'sanctum')->getJson('/api/platform/usage')->assertJsonPath('data.provider', ['configured' => true, 'sandbox' => true, 'balance' => 42.5]);
        $this->actingAs($this->staffOwner, 'sanctum')->getJson('/api/platform/usage')->assertJsonPath('data.provider.balance', 42.5);
        $this->assertSame(1, $calls);

        cache()->forget('platform-usage:balance');
        $this->dfsRoutes['/v3/appendix/user_data'] = fn () => $this->envelope([], 40100);
        $this->actingAs($this->staffOwner, 'sanctum')->getJson('/api/platform/usage')->assertOk()->assertJsonPath('data.provider.balance', null);
    }

    public function test_workspace_rows_carry_their_usage(): void
    {
        [$a, $b] = $this->usageFixture();
        $a->forceFill(['rank_keyword_limit' => 1000])->save();

        $rows = collect($this->actingAs($this->staffOwner, 'sanctum')->getJson('/api/platform/usage/workspaces')->assertOk()->json('data'))->keyBy('id');

        $alpha = $rows[$a->id];
        $owner = $this->ownerOf($a);
        $this->assertSame(['name' => $owner->displayName(), 'email' => $owner->email], $alpha['owner']);
        $this->assertSame($a->slug, $alpha['slug']);
        $this->assertSame(3, $alpha['keywords_tracked']);
        $this->assertSame(1000, $alpha['keyword_limit']);
        $this->assertSame(1000, $alpha['keyword_limit_override']);
        $this->assertSame(1, $alpha['sites_count']);
        $this->assertSame(4, $alpha['checks_this_month']);
        $this->assertSame(2, $alpha['live_checks_this_month']);
        $this->assertEquals(0.0032, $alpha['cost_this_month']);
        $this->assertEquals(0.0006, $alpha['cost_last_month']);
        $this->assertStringStartsWith('2026-11-20T10:00:00', $alpha['last_check_at']);  // addCheck's completed_at = now
        $this->assertEquals([0, 0, 0.0012, 0, 0.0006, 0.0032], array_column($alpha['series'], 'cost'));
        $this->assertSame(['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'], array_column($alpha['series'], 'month'));

        $beta = $rows[$b->id];
        $this->assertSame(0, $beta['keywords_tracked']);
        $this->assertSame(500, $beta['keyword_limit']);
        $this->assertNull($beta['keyword_limit_override']);
        $this->assertSame(1, $beta['checks_this_month']);
        $this->assertEquals(0.0006, $beta['cost_this_month']);
        $this->assertEquals(0, $beta['cost_last_month']);
        $this->assertStringStartsWith('2026-11-10T01:00:00', $beta['last_check_at']);

        $none = $rows[$this->platform->id];
        $this->assertSame(0, $none['checks_this_month']);
        $this->assertNull($none['last_check_at']);
        $this->assertCount(6, $none['series']);

        // Default sort: cost this month, highest first.
        $this->assertSame([$a->id, $b->id, $this->platform->id], $rows->keys()->all());
    }

    public function test_workspaces_sort_search_and_paginate(): void
    {
        [$a, $b] = $this->usageFixture();
        foreach (range(1, 10) as $i) {
            $this->makeWorkspace("Zulu {$i}");
        }
        $get = fn (string $query) => $this->actingAs($this->staffOwner, 'sanctum')->getJson('/api/platform/usage/workspaces'.$query)->assertOk();

        $page = $get('?per_page=10');
        $this->assertCount(10, $page->json('data'));
        $this->assertSame(13, $page->json('meta.total'));
        $this->assertSame(2, $page->json('meta.last_page'));
        $this->assertCount(3, $get('?per_page=10&page=2')->json('data'));
        $this->assertSame(13, $get('')->json('meta.total'));
        $this->assertSame(15, $get('')->json('meta.per_page'));

        $byName = array_column($get('?sort=name')->json('data'), 'name');
        $this->assertSame($a->name, $byName[0]);
        $this->assertSame($b->name, $byName[1]);
        $this->assertSame($a->id, $get('?sort=-keywords_tracked')->json('data.0.id'));
        $this->assertSame($a->id, $get('?sort=-cost_last_month')->json('data.0.id'));
        $this->assertSame($b->id, $get('?sort=-last_check_at')->json('data.1.id'));  // nulls last
        $this->assertSame($a->id, $get('?sort=-checks_this_month')->json('data.0.id'));
        $this->assertNotSame($a->id, $get('?sort=cost_this_month')->json('data.0.id'));

        $this->assertSame([$b->id], array_column($get('?search=beta')->json('data'), 'id'));
        $this->assertSame([$a->id], array_column($get('?search='.$a->slug)->json('data'), 'id'));
        $this->assertSame([$b->id], array_column($get('?search='.urlencode(strtoupper($this->ownerOf($b)->email)))->json('data'), 'id'));
        $this->assertSame([], $get('?search=%25')->json('data'));

        $this->actingAs($this->staffOwner, 'sanctum')->getJson('/api/platform/usage/workspaces?sort=slug')->assertUnprocessable();
        $this->actingAs($this->staffOwner, 'sanctum')->getJson('/api/platform/usage/workspaces?per_page=500')->assertUnprocessable();
    }

    public function test_staff_change_a_workspace_keyword_limit_and_it_applies(): void
    {
        [$a] = $this->usageFixture();
        $url = "/api/platform/workspaces/{$a->id}/limits";

        $this->actingAs($this->staffOwner, 'sanctum')->patchJson($url, ['rank_keyword_limit' => 5])->assertUnprocessable()->assertJsonValidationErrors('rank_keyword_limit');
        $this->actingAs($this->staffOwner, 'sanctum')->patchJson($url, ['rank_keyword_limit' => 100001])->assertUnprocessable();
        $this->actingAs($this->staffOwner, 'sanctum')->patchJson($url, [])->assertUnprocessable();
        $this->actingAs($this->staffOwner, 'sanctum')->patchJson('/api/platform/workspaces/999999/limits', ['rank_keyword_limit' => 10])->assertNotFound();

        $this->actingAs($this->staffOwner, 'sanctum')->patchJson($url, ['rank_keyword_limit' => 10])->assertOk()
            ->assertJsonPath('data.id', $a->id)
            ->assertJsonPath('data.keyword_limit', 10)
            ->assertJsonPath('data.keyword_limit_override', 10)
            ->assertJsonPath('data.keywords_tracked', 3);
        $this->assertSame(10, app(RankLimits::class)->keywordLimit($a->id));

        // Alpha's own users now hit 10 (3 tracked: 7 fit, 8 don't).
        $site = $this->rankSite($this->makeClient($a), 'https://alpha-two.co.za');
        $manager = $this->makeUser(Role::MANAGER, workspace: $a);
        $store = fn (int $n) => $this->actingAs($manager, 'sanctum')->postJson("/api/sites/{$site->id}/rank-keywords", [
            'keywords' => array_map(fn ($i) => "kw {$n} {$i}", range(1, $n)),
            'locations' => [['type' => 'code', 'code' => 2710, 'name' => 'South Africa']],
            'devices' => ['desktop'],
        ]);
        $store(8)->assertUnprocessable();
        $store(7)->assertCreated();

        // The change is in Alpha's change history; null goes back to the default.
        $this->assertDatabaseHas('audit_logs', ['table_name' => 'workspaces', 'record_id' => $a->id, 'workspace_id' => $a->id, 'action_type' => 'UPDATE']);
        $this->actingAs($this->staffOwner, 'sanctum')->patchJson($url, ['rank_keyword_limit' => null])->assertOk()
            ->assertJsonPath('data.keyword_limit', 500)
            ->assertJsonPath('data.keyword_limit_override', null);
        $this->assertSame(500, app(RankLimits::class)->keywordLimit($a->id));
    }
}
