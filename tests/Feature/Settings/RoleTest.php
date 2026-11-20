<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\AuthTestHelpers;
use Tests\TestCase;

class RoleTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->owner = $this->ownerOf($this->workspace());
    }

    private function role(string $name): Role
    {
        return Role::inWorkspace($this->workspace()->id)->where('name', $name)->firstOrFail();
    }

    private function newRole(string $name): Role
    {
        return $this->inWorkspace($this->workspace(), fn () => Role::create(['name' => $name, 'guard_name' => 'web']));
    }

    public function test_owners_get_the_workspaces_roles_and_the_grid_grouped_like_the_sidebar(): void
    {
        $this->makeUser(Role::ANALYST);
        $this->makeWorkspace('Other'); // its roles are not listed here

        $response = $this->actingAs($this->owner, 'sanctum')->getJson('/api/settings/roles')->assertOk();

        $this->assertSame([Role::OWNER, Role::ANALYST, Role::CLIENT, Role::MANAGER], array_column($response->json('roles'), 'name'));
        $this->assertTrue($response->json('roles.0.is_system'));
        $this->assertCount(count(Permissions::forWorkspace($this->workspace())), $response->json('roles.0.permissions'));

        $analyst = collect($response->json('roles'))->firstWhere('name', 'Analyst');
        $this->assertSame(1, $analyst['users_count']);
        $this->assertFalse($analyst['all_clients']);
        $this->assertContains('update_site_audit', $analyst['permissions']);
        $this->assertNotContains('update_clients_clients', $analyst['permissions']);

        $clients = collect($response->json('groups'))->firstWhere('key', 'clients');
        $this->assertSame('Clients', $clients['label']);
        $this->assertSame(['key' => 'clients_clients', 'label' => 'Clients', 'actions' => ['view', 'create', 'update', 'delete'], 'grantable' => true], $clients['resources'][0]);
        $users = collect(collect($response->json('groups'))->firstWhere('key', 'settings')['resources'])->firstWhere('key', 'settings_users');
        $this->assertFalse($users['grantable']);
    }

    public function test_the_default_roles_have_the_agreed_access(): void
    {
        $manager = $this->makeUser(Role::MANAGER);
        $client = $this->makeUser(Role::CLIENT);

        $this->assertTrue($this->userCan($manager, 'delete_reports'));
        $this->assertTrue($this->userCan($manager, 'update_settings_email_templates'));
        $this->assertFalse($this->userCan($manager, 'view_settings_users'));
        $this->assertTrue($this->userCan($client, 'view_reports'));
        $this->assertFalse($this->userCan($client, 'create_clients_sites'));
        $this->assertFalse($this->userCan($client, 'view_settings_email_templates'));
        foreach (Permissions::forWorkspace($this->workspace()) as $name) {
            $this->assertTrue($this->userCan($this->owner, $name), $name);
        }
    }

    public function test_only_owners_can_see_or_change_roles(): void
    {
        $manager = $this->makeUser(Role::MANAGER);
        $role = $this->role(Role::ANALYST);

        $this->actingAs($manager, 'sanctum')->getJson('/api/settings/roles')->assertForbidden();
        $this->actingAs($manager, 'sanctum')->postJson('/api/settings/roles', ['name' => 'Intern'])->assertForbidden();
        $this->actingAs($manager, 'sanctum')->putJson("/api/settings/roles/{$role->id}/permissions", ['permissions' => []])->assertForbidden();
    }

    public function test_saving_permissions_adds_view_applies_at_once_and_logs_each_page_that_changed(): void
    {
        $role = $this->newRole('Intern');
        $this->inWorkspace($this->workspace(), fn () => $role->syncPermissions(['view_reports']));
        $member = User::factory()->create();
        $this->addToWorkspace($member, $this->workspace(), 'Intern');

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/settings/roles/{$role->id}/permissions", ['permissions' => ['view_reports', 'create_reports', 'update_site_audit']])
            ->assertOk()
            ->assertJsonPath('data.permissions', ['create_reports', 'update_site_audit', 'view_reports', 'view_site_audit']);

        $this->assertTrue($this->userCan($member, 'create_reports'));
        $this->assertTrue($this->userCan($member, 'view_site_audit'), 'view comes with any other action');
        $this->assertFalse($this->userCan($member, 'delete_reports'));

        $entry = $this->actingAs($this->owner, 'sanctum')->getJson('/api/settings/roles/history')->json('data.0');
        $this->assertSame('update', $entry['action']);
        $this->assertSame('Intern', $entry['record_label']);
        $this->assertEqualsCanonicalizing([
            ['field' => 'reports', 'old' => 'View', 'new' => 'View, Create'],
            ['field' => 'site_audit', 'old' => 'No access', 'new' => 'View, Update'],
        ], $entry['changes']);
    }

    public function test_a_page_whose_permissions_are_not_in_the_database_yet_can_still_be_given(): void
    {
        DB::table('permissions')->where('name', 'like', '%_rank_tracking')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = $this->newRole('Buyer');

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/settings/roles/{$role->id}/permissions", ['permissions' => ['update_rank_tracking']])
            ->assertOk()
            ->assertJsonPath('data.permissions', ['update_rank_tracking', 'view_rank_tracking']);

        $this->assertSame(0, DB::table('permissions')->where('name', 'delete_rank_tracking')->count());
    }

    public function test_saving_unchanged_permissions_logs_nothing(): void
    {
        $role = $this->newRole('Intern');
        AuditLog::query()->delete();

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/settings/roles/{$role->id}/permissions", ['permissions' => []])->assertOk();

        $this->assertSame(0, AuditLog::count());
    }

    public function test_only_catalogue_permissions_a_role_may_hold_are_accepted(): void
    {
        $role = $this->role(Role::ANALYST);

        $errors = $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/settings/roles/{$role->id}/permissions", ['permissions' => ['view_settings_users', 'approve_dashboard', 'launch_rockets']])
            ->assertJsonValidationErrors(['permissions.0', 'permissions.1', 'permissions.2'])
            ->json('errors');
        $this->assertSame('view_settings_users cannot be given to a role.', $errors['permissions.0'][0]);

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/settings/roles/{$role->id}/permissions", [])->assertJsonValidationErrors('permissions');
        $this->assertFalse($role->fresh()->hasPermissionTo('view_settings_users'));
    }

    public function test_roles_can_be_added_renamed_and_deleted_when_empty(): void
    {
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/settings/roles', ['name' => '  Content   Writer ', 'description' => 'Writes briefs', 'all_clients' => true])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Content Writer')
            ->assertJsonPath('data.all_clients', true)
            ->assertJsonPath('data.permissions', [])
            ->json('data.id');
        $this->assertSame($this->workspace()->id, Role::find($id)->workspace_id);

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/settings/roles', ['name' => 'content writer'])
            ->assertJsonPath('errors.name.0', 'There is already a role with this name.');

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/settings/roles/{$id}", ['name' => 'Copywriter', 'description' => ''])
            ->assertOk()->assertJsonPath('data.name', 'Copywriter')->assertJsonPath('data.description', null);

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/settings/roles/{$id}")->assertNoContent();
        $this->assertSoftDeleted('roles', ['id' => $id]);

        // Adding the name again brings the role back, empty.
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/settings/roles', ['name' => 'Copywriter'])
            ->assertCreated()->assertJsonPath('data.id', $id);
    }

    public function test_another_workspace_may_use_the_same_role_name(): void
    {
        $other = $this->makeWorkspace('Other');
        $this->inWorkspace($other, fn () => Role::create(['name' => 'Copywriter', 'guard_name' => 'web']));

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/settings/roles', ['name' => 'Copywriter'])->assertCreated();
    }

    public function test_a_role_with_users_cannot_be_deleted(): void
    {
        $this->makeUser(Role::CLIENT);
        $role = $this->role(Role::CLIENT);

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/settings/roles/{$role->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Client still has 1 user. Give them another role first.');
        $this->assertNotSoftDeleted($role);
    }

    public function test_the_owner_role_cannot_be_renamed_changed_or_deleted(): void
    {
        $role = $this->role(Role::OWNER);

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/settings/roles/{$role->id}", ['name' => 'Boss'])->assertForbidden();
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/settings/roles/{$role->id}/permissions", ['permissions' => ['view_reports']])->assertForbidden();
        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/settings/roles/{$role->id}")->assertForbidden();

        $this->assertSame(Role::OWNER, $role->fresh()->name);
        $this->assertCount(count(Permissions::forWorkspace($this->workspace())), $role->fresh()->permissions);
    }

    public function test_owner_only_pages_still_cannot_be_given_to_another_role(): void
    {
        $role = $this->newRole('Old Clerk');

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/settings/roles/{$role->id}/permissions", ['permissions' => ['update_settings_workspace']])
            ->assertUnprocessable();
    }
}
