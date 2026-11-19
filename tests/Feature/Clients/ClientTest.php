<?php

namespace Tests\Feature\Clients;

use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthTestHelpers;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->manager = $this->makeUser(Role::MANAGER);
    }

    public function test_the_list_is_paginated_15_searchable_sortable_with_counts(): void
    {
        foreach (range(1, 15) as $i) {
            $this->makeClient();
        }
        $karoo = $this->makeClient(state: ['name' => 'Karoo Coffee', 'contact_email' => 'hello@karoo.co.za', 'is_active' => false]);
        Site::factory()->create(['client_id' => $karoo->id]);

        $this->actingAs($this->manager, 'sanctum')->getJson('/api/clients')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 16)
            ->assertJsonPath('summary', ['active' => 15, 'total' => 16])
            ->assertJsonPath('data.14.is_active', true);

        foreach (['karoo', 'HELLO@KAROO'] as $search) {
            $this->actingAs($this->manager, 'sanctum')->getJson('/api/clients?search='.urlencode($search))
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.name', 'Karoo Coffee')
                ->assertJsonPath('data.0.sites_count', 1);
        }
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/clients?status=inactive')->assertJsonCount(1, 'data');
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/clients?sort=status&direction=desc')->assertJsonPath('data.0.name', 'Karoo Coffee');
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/clients?search=%25')->assertJsonCount(0, 'data');
    }

    public function test_clients_are_added_changed_and_deleted_with_history(): void
    {
        $id = $this->actingAs($this->manager, 'sanctum')->postJson('/api/clients', [
            'name' => '  Durban   Dental ', 'contact_name' => 'Dr Naidoo', 'contact_email' => 'Info@DurbanDental.co.za',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Durban Dental')
            ->assertJsonPath('data.contact_email', 'info@durbandental.co.za')
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');
        $this->assertSame($this->workspace()->id, Client::withoutGlobalScopes()->find($id)->workspace_id);

        $this->actingAs($this->manager, 'sanctum')->postJson('/api/clients', ['name' => 'durban dental'])
            ->assertJsonPath('errors.name.0', 'There is already a client with this name.');

        $this->actingAs($this->manager, 'sanctum')->getJson("/api/clients/{$id}")->assertOk()->assertJsonPath('data.sites_count', 0);
        $this->actingAs($this->manager, 'sanctum')->putJson("/api/clients/{$id}", ['name' => 'Durban Dental Group', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->actingAs($this->manager, 'sanctum')->deleteJson("/api/clients/{$id}")->assertNoContent();
        $this->assertSoftDeleted('clients', ['id' => $id]);
        // The name is free again.
        $this->actingAs($this->manager, 'sanctum')->postJson('/api/clients', ['name' => 'Durban Dental Group'])->assertCreated();

        $history = $this->actingAs($this->manager, 'sanctum')->getJson('/api/clients/history')->json('data');
        $this->assertSame(['create', 'delete', 'update', 'create'], array_column($history, 'action'));
        $this->assertSame('Durban Dental Group', $history[2]['record_label']);
    }

    public function test_a_client_with_sites_is_not_deleted(): void
    {
        $client = $this->makeClient(state: ['name' => 'Busy']);
        Site::factory()->create(['client_id' => $client->id]);

        $this->actingAs($this->manager, 'sanctum')->deleteJson("/api/clients/{$client->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', "Busy has sites, so it can't be deleted. Delete its sites first, or make it inactive.");
        $this->actingAs($this->manager, 'sanctum')->postJson('/api/clients/bulk', ['action' => 'delete', 'ids' => [$client->id]])->assertStatus(409);
        $this->assertNotSoftDeleted($client);
    }

    public function test_bulk_activate_deactivate_and_delete_are_all_or_nothing(): void
    {
        [$a, $b] = [$this->makeClient(), $this->makeClient()];

        $this->actingAs($this->manager, 'sanctum')->postJson('/api/clients/bulk', ['action' => 'deactivate', 'ids' => [$a->id, $b->id]])
            ->assertOk()->assertJsonPath('affected', 2);
        $this->assertFalse($a->fresh()->is_active);

        $this->actingAs($this->manager, 'sanctum')->postJson('/api/clients/bulk', ['action' => 'delete', 'ids' => [$a->id, 999999]])
            ->assertStatus(422);
        $this->assertNotSoftDeleted($a);

        $this->actingAs($this->manager, 'sanctum')->postJson('/api/clients/bulk', ['action' => 'delete', 'ids' => [$a->id, $b->id]])->assertOk();
        $this->assertSoftDeleted($a);
    }

    public function test_client_logins_see_only_their_clients_read_only(): void
    {
        $theirs = $this->makeClient(state: ['name' => 'Theirs']);
        $other = $this->makeClient(state: ['name' => 'Someone else']);
        $login = $this->makeUser(Role::CLIENT, clients: [$theirs]);

        $this->actingAs($login, 'sanctum')->getJson('/api/clients')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Theirs')
            ->assertJsonPath('summary.total', 1);
        $this->actingAs($login, 'sanctum')->getJson("/api/clients/{$theirs->id}")->assertOk();
        $this->actingAs($login, 'sanctum')->getJson("/api/clients/{$other->id}")->assertForbidden();
        $this->actingAs($login, 'sanctum')->putJson("/api/clients/{$theirs->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->actingAs($login, 'sanctum')->postJson('/api/clients', ['name' => 'New'])->assertForbidden();
        $this->actingAs($login, 'sanctum')->deleteJson("/api/clients/{$theirs->id}")->assertForbidden();
        $this->actingAs($login, 'sanctum')->postJson('/api/clients/bulk', ['action' => 'deactivate', 'ids' => [$theirs->id]])->assertForbidden();

        // History only shows their client's changes.
        $this->actingAs($this->manager, 'sanctum')->putJson("/api/clients/{$other->id}", ['name' => 'Someone else (renamed)'])->assertOk();
        $labels = array_column($this->actingAs($login, 'sanctum')->getJson('/api/clients/history')->json('data'), 'record_label');
        $this->assertNotContains('Someone else (renamed)', $labels);
    }

    public function test_analysts_work_on_their_clients_but_do_not_add_clients(): void
    {
        $theirs = $this->makeClient();
        $analyst = $this->makeUser(Role::ANALYST, clients: [$theirs]);

        $this->actingAs($analyst, 'sanctum')->getJson('/api/clients')->assertJsonCount(1, 'data');
        $this->actingAs($analyst, 'sanctum')->postJson('/api/clients', ['name' => 'New'])->assertForbidden();
    }
}
