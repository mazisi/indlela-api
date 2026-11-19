<?php

namespace Tests\Feature\Tenancy;

use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\ClientPolicy;
use App\Policies\SitePolicy;
use App\Support\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthTestHelpers;
use Tests\TestCase;

/**
 * A user in workspace A can never read or change workspace B's clients,
 * sites, users, roles or history, whatever their role in A.
 */
class TenancyIsolationTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase;

    private Workspace $a;

    private Workspace $b;

    private User $ownerA;

    private Client $clientB;

    private Site $siteB;

    private User $memberB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();

        $this->a = $this->workspace();
        $this->ownerA = $this->ownerOf($this->a);
        $this->b = $this->makeWorkspace('Rival Agency');
        $this->clientB = $this->makeClient($this->b, ['name' => 'Rival client']);
        $this->siteB = Site::factory()->create(['client_id' => $this->clientB->id, 'name' => 'Rival site']);
        $this->memberB = $this->makeUser(Role::ANALYST, ['full_name' => 'Rival', 'surname' => 'Member'], $this->b, [$this->clientB]);

        // Some history in B.
        $this->actingAs($this->ownerOf($this->b), 'sanctum')->putJson("/api/clients/{$this->clientB->id}", ['name' => 'Rival client renamed'])->assertOk();
    }

    public function test_clients_of_another_workspace_are_invisible_and_untouchable(): void
    {
        $as = $this->actingAs($this->ownerA, 'sanctum');
        $id = $this->clientB->id;

        $as->getJson('/api/clients')->assertOk()->assertJsonPath('meta.total', 0);
        $as->getJson('/api/clients?search=rival')->assertJsonCount(0, 'data');
        $as->getJson("/api/clients/{$id}")->assertNotFound();
        $as->putJson("/api/clients/{$id}", ['name' => 'Hijacked'])->assertNotFound();
        $as->deleteJson("/api/clients/{$id}")->assertNotFound();
        $as->postJson('/api/clients/bulk', ['action' => 'delete', 'ids' => [$id]])->assertStatus(422);
        $as->getJson('/api/clients/history')->assertJsonCount(0, 'data');

        // Same name is fine in A: names are unique per workspace only.
        $as->postJson('/api/clients', ['name' => 'Rival client renamed'])->assertCreated();

        $this->assertSame('Rival client renamed', Client::withoutGlobalScopes()->find($id)->name);
        $this->assertNotSoftDeleted($this->clientB);
    }

    public function test_sites_of_another_workspace_are_invisible_and_untouchable(): void
    {
        $as = $this->actingAs($this->ownerA, 'sanctum');
        $id = $this->siteB->id;

        $as->getJson('/api/sites')->assertJsonPath('meta.total', 0);
        $as->getJson("/api/sites?client_id={$this->clientB->id}")->assertNotFound();
        $as->getJson("/api/sites/{$id}")->assertNotFound();
        $as->putJson("/api/sites/{$id}", ['url' => 'hijack.co.za'])->assertNotFound();
        $as->deleteJson("/api/sites/{$id}")->assertNotFound();
        $as->postJson('/api/sites/bulk', ['action' => 'deactivate', 'ids' => [$id]])->assertStatus(422);
        $as->getJson('/api/sites/history')->assertJsonCount(0, 'data');

        // Adding a site to B's client is refused, and the same domain is fine in A.
        $as->postJson('/api/sites', ['client_id' => $this->clientB->id, 'url' => 'sneaky.co.za'])->assertJsonValidationErrors('client_id');
        $clientA = $this->makeClient($this->a);
        $as->postJson('/api/sites', ['client_id' => $clientA->id, 'url' => $this->siteB->domain])->assertCreated();

        $this->assertSame(0, Site::withoutGlobalScopes()->where('client_id', $this->clientB->id)->where('domain', 'sneaky.co.za')->count());
        $this->assertTrue(Site::withoutGlobalScopes()->find($id)->is_active);
    }

    public function test_users_of_another_workspace_are_invisible_and_untouchable(): void
    {
        $as = $this->actingAs($this->ownerA, 'sanctum');
        $id = $this->memberB->id;

        $as->getJson('/api/users')->assertJsonPath('meta.total', 1);
        $as->getJson('/api/users?search=rival')->assertJsonCount(0, 'data');
        $as->putJson("/api/users/{$id}", ['full_name' => 'X', 'surname' => 'Y', 'email' => $this->memberB->email, 'mfa_channel' => 'email',
            'role_id' => Role::inWorkspace($this->a->id)->where('name', Role::MANAGER)->value('id'), 'status' => 'disabled'])->assertNotFound();
        $as->deleteJson("/api/users/{$id}")->assertNotFound();
        $as->postJson("/api/users/{$id}/unlock")->assertNotFound();
        $as->postJson("/api/users/{$id}/password-reset")->assertNotFound();
        $as->postJson('/api/users/bulk', ['action' => 'disable', 'ids' => [$id]])->assertStatus(422);

        $this->login($this->memberB, 'Wrong@2026');
        $as->getJson('/api/login-history')->assertJsonCount(0, 'data');
        $this->assertEmpty(collect($as->getJson('/api/users/history')->json('data'))->where('record_id', (string) $id));

        $this->assertSame('Rival', $this->memberB->fresh()->full_name);
        $this->assertTrue($this->memberB->fresh()->isActive());
    }

    public function test_roles_of_another_workspace_are_invisible_and_untouchable(): void
    {
        $as = $this->actingAs($this->ownerA, 'sanctum');
        $roleB = Role::inWorkspace($this->b->id)->where('name', Role::ANALYST)->firstOrFail();

        $names = array_column($as->getJson('/api/settings/roles')->json('roles'), 'id');
        $this->assertNotContains($roleB->id, $names);
        $as->putJson("/api/settings/roles/{$roleB->id}", ['name' => 'Hijacked'])->assertNotFound();
        $as->putJson("/api/settings/roles/{$roleB->id}/permissions", ['permissions' => ['view_reports']])->assertNotFound();
        $as->deleteJson("/api/settings/roles/{$roleB->id}")->assertNotFound();
        $this->assertEmpty(collect($as->getJson('/api/settings/roles/history')->json('data'))->where('record_id', (string) $roleB->id));

        // Nor can one be handed to a user in A.
        $as->postJson('/api/users', ['full_name' => 'A', 'surname' => 'B', 'email' => 'new@example.co.za', 'mfa_channel' => 'email',
            'role_id' => $roleB->id, 'client_ids' => [$this->clientB->id]])->assertJsonValidationErrors(['role_id', 'client_ids.0']);

        $this->assertSame(Role::ANALYST, $roleB->fresh()->name);
    }

    public function test_a_member_of_both_sees_only_the_current_workspace(): void
    {
        $both = $this->makeUser(Role::MANAGER, workspace: $this->a);
        $this->addToWorkspace($both, $this->b, Role::CLIENT, [$this->clientB]);
        $this->makeClient($this->a, ['name' => 'Home client']);

        $this->actingAs($both, 'sanctum')->getJson('/api/clients')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Home client');
        $this->actingAs($both, 'sanctum')->getJson("/api/clients/{$this->clientB->id}")->assertNotFound();

        $this->actingAs($both, 'sanctum')->postJson("/api/workspaces/{$this->b->id}/switch")->assertOk();
        $this->actingAs($both, 'sanctum')->getJson('/api/clients')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Rival client renamed');
        // A Client there: read only, though a Manager in A.
        $this->actingAs($both, 'sanctum')->putJson("/api/clients/{$this->clientB->id}", ['name' => 'Nope'])->assertForbidden();
    }

    public function test_policies_refuse_another_workspaces_record_even_without_the_scope(): void
    {
        // The second wall: a record fetched around the scope still can't be acted on.
        $client = Client::withoutGlobalScopes()->find($this->clientB->id);
        $site = Site::withoutGlobalScopes()->find($this->siteB->id);

        app(CurrentWorkspace::class)->set($this->a);
        $owner = $this->ownerA->fresh();

        $this->assertFalse((new ClientPolicy)->view($owner, $client)->allowed());
        $this->assertFalse((new ClientPolicy)->update($owner, $client)->allowed());
        $this->assertFalse((new SitePolicy)->delete($owner, $site)->allowed());
        $this->assertFalse($owner->canAccessClient($client));
        app(CurrentWorkspace::class)->set(null);
    }
}
