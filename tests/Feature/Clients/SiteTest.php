<?php

namespace Tests\Feature\Clients;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthTestHelpers;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->manager = $this->makeUser(Role::MANAGER);
    }

    public function test_adding_a_site_normalises_the_address_and_names_it_after_the_domain(): void
    {
        $client = $this->makeClient(state: ['name' => 'Karoo Coffee']);

        $this->actingAs($this->manager, 'sanctum')->postJson('/api/sites', ['client_id' => $client->id, 'url' => 'WWW.KarooCoffee.co.za/?utm=x'])
            ->assertCreated()
            ->assertJsonPath('data.url', 'https://www.karoocoffee.co.za')
            ->assertJsonPath('data.domain', 'karoocoffee.co.za')
            ->assertJsonPath('data.name', 'karoocoffee.co.za')
            ->assertJsonPath('data.client', ['id' => $client->id, 'name' => 'Karoo Coffee']);

        $this->actingAs($this->manager, 'sanctum')->postJson('/api/sites', ['client_id' => $client->id, 'url' => 'https://karoocoffee.co.za/shop'])
            ->assertJsonPath('errors.url.0', 'karoocoffee.co.za is already a site in this workspace.');
        $this->actingAs($this->manager, 'sanctum')->postJson('/api/sites', ['client_id' => $client->id, 'url' => 'not a website'])
            ->assertJsonPath('errors.url.0', 'Enter the website address, e.g. www.example.co.za.');

        $history = $this->actingAs($this->manager, 'sanctum')->getJson('/api/sites/history')->json('data.0');
        $this->assertSame('karoocoffee.co.za · Karoo Coffee', $history['record_label']);
    }

    public function test_sites_are_listed_by_client_15_per_page_changed_and_deleted(): void
    {
        $client = $this->makeClient();
        Site::factory()->count(16)->create(['client_id' => $client->id]);
        $elsewhere = Site::factory()->create(['client_id' => $this->makeClient()->id]);

        $this->actingAs($this->manager, 'sanctum')->getJson("/api/sites?client_id={$client->id}")
            ->assertOk()->assertJsonPath('meta.per_page', 15)->assertJsonPath('meta.total', 16)->assertJsonPath('summary.total', 16);
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/sites')->assertJsonPath('meta.total', 17);

        $this->actingAs($this->manager, 'sanctum')->putJson("/api/sites/{$elsewhere->id}", ['name' => 'Main site', 'url' => 'shop.example.co.za', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Main site')
            ->assertJsonPath('data.domain', 'shop.example.co.za')
            ->assertJsonPath('data.is_active', false);
        $this->actingAs($this->manager, 'sanctum')->putJson("/api/sites/{$elsewhere->id}", ['client_id' => $client->id, 'url' => 'shop.example.co.za'])
            ->assertJsonValidationErrors('client_id');

        $this->actingAs($this->manager, 'sanctum')->deleteJson("/api/sites/{$elsewhere->id}")->assertNoContent();
        $this->assertSoftDeleted($elsewhere);

        $ids = Site::withoutGlobalScopes()->where('client_id', $client->id)->limit(2)->pluck('id')->all();
        $this->actingAs($this->manager, 'sanctum')->postJson('/api/sites/bulk', ['action' => 'deactivate', 'ids' => $ids])->assertOk()->assertJsonPath('affected', 2);
    }

    public function test_client_logins_see_only_their_clients_sites_and_change_nothing(): void
    {
        $theirs = $this->makeClient();
        $mine = Site::factory()->create(['client_id' => $theirs->id]);
        $notMine = Site::factory()->create(['client_id' => $this->makeClient()->id]);
        $login = $this->makeUser(Role::CLIENT, clients: [$theirs]);

        $this->actingAs($login, 'sanctum')->getJson('/api/sites')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->actingAs($login, 'sanctum')->getJson("/api/sites?client_id={$notMine->client_id}")->assertForbidden();
        $this->actingAs($login, 'sanctum')->getJson("/api/sites/{$mine->id}")->assertOk();
        $this->actingAs($login, 'sanctum')->getJson("/api/sites/{$notMine->id}")->assertForbidden();
        $this->actingAs($login, 'sanctum')->postJson('/api/sites', ['client_id' => $theirs->id, 'url' => 'new.co.za'])->assertForbidden();
        $this->actingAs($login, 'sanctum')->putJson("/api/sites/{$mine->id}", ['url' => 'x.co.za'])->assertForbidden();
        $this->actingAs($login, 'sanctum')->deleteJson("/api/sites/{$mine->id}")->assertForbidden();
    }

    public function test_analysts_add_sites_only_for_their_clients(): void
    {
        $theirs = $this->makeClient();
        $other = $this->makeClient();
        $analyst = $this->makeUser(Role::ANALYST, clients: [$theirs]);
        $this->inWorkspace($this->workspace(), function () {
            Role::inWorkspace($this->workspace()->id)->where('name', Role::ANALYST)->first()->givePermissionTo(['view_clients_sites', 'create_clients_sites']);
        });

        $this->actingAs($analyst, 'sanctum')->postJson('/api/sites', ['client_id' => $theirs->id, 'url' => 'ours.co.za'])->assertCreated();
        $this->actingAs($analyst, 'sanctum')->postJson('/api/sites', ['client_id' => $other->id, 'url' => 'theirs.co.za'])
            ->assertForbidden()->assertJsonPath('message', 'You do not have access to this client.');
    }
}
