<?php

namespace Tests\Feature\Workspaces;

use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AuthTestHelpers;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
    }

    public function test_the_switcher_lists_my_workspaces_with_my_role_in_each(): void
    {
        $user = $this->makeUser(Role::CLIENT);
        $agency = $this->makeWorkspace('Zebra Agency');
        $this->addToWorkspace($user, $agency, Role::MANAGER);
        $this->makeWorkspace('Not mine');

        $this->actingAs($user, 'sanctum')->getJson('/api/workspaces')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', $this->workspace()->name)
            ->assertJsonPath('data.0.role', 'Client')
            ->assertJsonPath('data.1', ['id' => $agency->id, 'name' => $agency->name, 'slug' => $agency->slug, 'logo_url' => null, 'role' => 'Manager']);
    }

    public function test_switching_changes_roles_permissions_and_data_without_a_new_token(): void
    {
        $user = $this->makeUser(Role::CLIENT, ['mfa_channel' => 'off']);
        $agency = $this->makeWorkspace('Agency');
        $this->addToWorkspace($user, $agency, Role::MANAGER);
        $theirClient = $this->makeClient($agency, ['name' => 'Agency client']);
        $token = $this->login($user)->json('token');

        $this->withToken($token)->getJson('/api/clients')->assertJsonMissing(['name' => 'Agency client']);
        $this->withToken($token)->postJson('/api/clients', ['name' => 'New'])->assertForbidden();

        $response = $this->withToken($token)->postJson("/api/workspaces/{$agency->id}/switch")
            ->assertOk()
            ->assertJsonMissingPath('token')
            ->assertJsonPath('workspace.id', $agency->id)
            ->assertJsonPath('roles', ['Manager'])
            ->assertJsonPath('user.role', 'Manager');
        $this->assertContains('create_clients_clients', $response->json('permissions'));
        $this->assertSame($agency->id, $user->fresh()->current_workspace_id);
        $this->assertDatabaseHas('login_events', ['user_id' => $user->id, 'event' => 'switched_workspace']);

        $this->withToken($token)->getJson('/api/clients')->assertJsonPath('data.0.id', $theirClient->id);
        $this->withToken($token)->postJson('/api/clients', ['name' => 'New'])->assertCreated();
        $this->withToken($token)->getJson('/api/me')->assertJsonPath('workspace.id', $agency->id);
    }

    public function test_you_cannot_switch_to_a_workspace_you_are_not_in(): void
    {
        $user = $this->makeUser();
        $other = $this->makeWorkspace('Other');

        $this->actingAs($user, 'sanctum')->postJson("/api/workspaces/{$other->id}/switch")->assertNotFound();
        $this->assertSame($this->workspace()->id, $user->fresh()->current_workspace_id);
    }

    public function test_anyone_can_create_another_workspace_and_owns_it(): void
    {
        $user = $this->makeUser(Role::CLIENT);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', ['name' => 'My Side Hustle'])
            ->assertCreated()
            ->assertJsonPath('workspace.name', 'My Side Hustle')
            ->assertJsonPath('roles', ['Owner'])
            ->assertJsonCount(2, 'workspaces');

        $this->assertSame($response->json('workspace.id'), $user->fresh()->current_workspace_id);
        $this->assertTrue($this->userCan($user, 'update_settings_roles', Workspace::find($response->json('workspace.id'))));
        $this->assertFalse($this->userCan($user, 'view_settings_roles', $this->workspace()), 'still a Client in the first');
    }

    public function test_owners_rename_the_workspace_and_set_its_logo(): void
    {
        Storage::fake('s3');
        $owner = $this->ownerOf($this->workspace());

        $this->actingAs($owner, 'sanctum')->getJson('/api/workspace')->assertOk()->assertJsonPath('data.id', $this->workspace()->id);

        $response = $this->actingAs($owner, 'sanctum')->patch('/api/workspace', [
            'name' => '  Acme   Digital  ',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 80),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.name', 'Acme Digital');

        $path = $this->workspace()->fresh()->logo_path;
        Storage::disk('s3')->assertExists($path);
        $this->assertStringStartsWith("workspaces/{$this->workspace()->id}/", $path);
        $this->assertNotNull($response->json('data.logo_url'));

        $this->actingAs($owner, 'sanctum')->patchJson('/api/workspace', ['remove_logo' => true])->assertOk()->assertJsonPath('data.logo_url', null);
        Storage::disk('s3')->assertMissing($path);

        $history = $this->actingAs($owner, 'sanctum')->getJson('/api/workspace/history')->json('data');
        $fields = collect($history)->where('action', 'update')->flatMap(fn ($e) => array_column($e['changes'], 'field'));
        $this->assertContains('name', $fields);
        $this->assertContains('logo_path', $fields);
    }

    public function test_only_owners_change_the_workspace(): void
    {
        $manager = $this->makeUser(Role::MANAGER);

        $this->actingAs($manager, 'sanctum')->getJson('/api/workspace')->assertForbidden();
        $this->actingAs($manager, 'sanctum')->patchJson('/api/workspace', ['name' => 'Mine now'])->assertForbidden();
    }

    public function test_a_user_removed_from_every_workspace_is_refused(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/me')->assertForbidden()->assertJsonPath('error_type', 'no_workspace');
    }
}
