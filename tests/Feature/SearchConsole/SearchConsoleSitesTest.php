<?php

namespace Tests\Feature\SearchConsole;

use App\Jobs\SyncSearchConsole;
use App\Models\AuditLog;
use App\Models\GscDailyTotal;
use App\Models\GscQueryPageDaily;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AuthTestHelpers;
use Tests\Support\SearchConsoleHelpers;
use Tests\TestCase;

class SearchConsoleSitesTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase, SearchConsoleHelpers;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->manager = $this->makeUser(Role::MANAGER);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [
            ['siteUrl' => 'sc-domain:karoo.co.za', 'permissionLevel' => 'siteOwner'],
            ['siteUrl' => 'https://www.acme.co.za/', 'permissionLevel' => 'siteFullUser'],
        ]])]);
    }

    public function test_linking_a_property_queues_the_backfill_and_lists_the_site(): void
    {
        $this->connectGoogle();
        $client = $this->makeClient(state: ['name' => 'Karoo Coffee']);
        $site = Site::factory()->create(['client_id' => $client->id, 'name' => 'Karoo']);

        $this->actingAs($this->manager, 'sanctum')->putJson("/api/sites/{$site->id}/search-console", ['property' => 'sc-domain:karoo.co.za'])
            ->assertOk()
            ->assertJsonPath('data.gsc_property', 'sc-domain:karoo.co.za')
            ->assertJsonPath('data.gsc_sync_status', 'queued')
            ->assertJsonPath('data.client', ['id' => $client->id, 'name' => 'Karoo Coffee']);

        Queue::assertPushed(SyncSearchConsole::class, fn (SyncSearchConsole $job) => $job->siteId === $site->id && $job->workspaceId === $this->workspace()->id);

        $this->actingAs($this->manager, 'sanctum')->getJson('/api/search-console/sites')
            ->assertOk()
            ->assertJsonPath('data.0', [
                'id' => $site->id, 'name' => 'Karoo', 'url' => $site->url, 'client' => ['id' => $client->id, 'name' => 'Karoo Coffee'],
                'gsc_property' => 'sc-domain:karoo.co.za', 'gsc_sync_status' => 'queued', 'gsc_synced_at' => null,
                'gsc_data_through' => null, 'gsc_sync_error' => null,
            ]);

        // Linking is in the site's history; sync bookkeeping is not.
        $this->assertSame(1, AuditLog::query()->where('table_name', 'sites')->where('record_id', $site->id)->where('action_type', 'UPDATE')->count());
    }

    public function test_only_properties_the_google_account_can_read_and_not_twice(): void
    {
        $this->connectGoogle();
        $site = Site::factory()->create(['client_id' => $this->makeClient()->id]);
        $this->linkedSite(property: 'https://www.acme.co.za/', state: ['name' => 'Acme']);

        $this->actingAs($this->manager, 'sanctum')->putJson("/api/sites/{$site->id}/search-console", ['property' => 'sc-domain:someone-else.co.za'])
            ->assertJsonValidationErrors(['property' => 'no access']);
        $this->actingAs($this->manager, 'sanctum')->putJson("/api/sites/{$site->id}/search-console", ['property' => 'https://www.acme.co.za/'])
            ->assertJsonValidationErrors(['property' => 'already linked to Acme']);
        $this->actingAs($this->manager, 'sanctum')->putJson("/api/sites/{$site->id}/search-console", ['property' => 'not a property'])
            ->assertJsonValidationErrors('property');

        Queue::assertNothingPushed();
    }

    public function test_linking_without_google_is_409(): void
    {
        $site = Site::factory()->create(['client_id' => $this->makeClient()->id]);

        $this->actingAs($this->manager, 'sanctum')->putJson("/api/sites/{$site->id}/search-console", ['property' => 'sc-domain:karoo.co.za'])
            ->assertStatus(409)->assertJsonPath('error_type', 'google_not_connected');
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/sites/{$site->id}/search-console/sync")
            ->assertStatus(409)->assertJsonPath('error_type', 'gsc_not_linked');

        $linked = $this->linkedSite();
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/sites/{$linked->id}/search-console/sync")
            ->assertStatus(409)->assertJsonPath('error_type', 'google_not_connected');
    }

    public function test_sync_now_queues_a_sync_and_answers_202(): void
    {
        $this->connectGoogle();
        $site = $this->linkedSite();

        $this->actingAs($this->manager, 'sanctum')->postJson("/api/sites/{$site->id}/search-console/sync")
            ->assertStatus(202)->assertJsonPath('data.gsc_sync_status', 'queued');

        Queue::assertPushed(SyncSearchConsole::class, 1);
    }

    public function test_unlinking_deletes_the_sites_data_only(): void
    {
        $site = $this->linkedSite(state: ['gsc_data_through' => '2026-06-01']);
        $other = $this->linkedSite(property: 'sc-domain:other.co.za');
        foreach ([$site, $other] as $s) {
            $this->addRows($s, [['2026-06-01', 'coffee', 'https://x.co.za/', 1, 10, 3.0]]);
            $this->addTotals($s, [['2026-06-01', 1, 12, 3.0]]);
        }

        $this->actingAs($this->manager, 'sanctum')->deleteJson("/api/sites/{$site->id}/search-console")->assertNoContent();

        $site->refresh();
        $this->assertNull($site->gsc_property);
        $this->assertSame('idle', $site->gsc_sync_status);
        $this->assertNull($site->gsc_data_through);
        $this->assertSame(0, GscQueryPageDaily::withoutGlobalScopes()->where('site_id', $site->id)->count());
        $this->assertSame(0, GscDailyTotal::withoutGlobalScopes()->where('site_id', $site->id)->count());
        $this->assertSame(1, GscQueryPageDaily::withoutGlobalScopes()->where('site_id', $other->id)->count());
    }

    public function test_relinking_to_another_property_drops_the_old_data(): void
    {
        $this->connectGoogle();
        $site = $this->linkedSite(property: 'sc-domain:karoo.co.za', state: ['gsc_data_through' => '2026-06-01']);
        $this->addTotals($site, [['2026-06-01', 1, 12, 3.0]]);

        $this->actingAs($this->manager, 'sanctum')->putJson("/api/sites/{$site->id}/search-console", ['property' => 'sc-domain:karoo.co.za'])->assertOk();
        $this->assertSame(1, GscDailyTotal::withoutGlobalScopes()->count(), 'same property: data kept');

        $this->actingAs($this->manager, 'sanctum')->putJson("/api/sites/{$site->id}/search-console", ['property' => 'https://www.acme.co.za/'])
            ->assertOk()->assertJsonPath('data.gsc_data_through', null);
        $this->assertSame(0, GscDailyTotal::withoutGlobalScopes()->count());
    }

    public function test_client_logins_view_their_clients_sites_but_cannot_link_or_sync(): void
    {
        $this->connectGoogle();
        $theirs = $this->makeClient();
        $mine = $this->linkedSite($theirs);
        $notMine = $this->linkedSite(property: 'sc-domain:other.co.za');
        $login = $this->makeUser(Role::CLIENT, clients: [$theirs]);
        $as = fn () => $this->actingAs($login, 'sanctum');

        $as()->getJson('/api/search-console/sites')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $as()->getJson("/api/sites/{$mine->id}/search-console/overview")->assertOk();
        $as()->getJson("/api/sites/{$notMine->id}/search-console/overview")->assertForbidden();
        $as()->putJson("/api/sites/{$mine->id}/search-console", ['property' => 'sc-domain:karoo.co.za'])->assertForbidden();
        $as()->postJson("/api/sites/{$mine->id}/search-console/sync")->assertForbidden();
        $as()->deleteJson("/api/sites/{$mine->id}/search-console")->assertForbidden();

        // An Analyst manages Search Console, but only for assigned clients.
        $analyst = $this->makeUser(Role::ANALYST, clients: [$theirs]);
        $this->actingAs($analyst, 'sanctum')->postJson("/api/sites/{$mine->id}/search-console/sync")->assertStatus(202);
        $this->actingAs($analyst, 'sanctum')->postJson("/api/sites/{$notMine->id}/search-console/sync")->assertForbidden();
        $this->actingAs($analyst, 'sanctum')->putJson("/api/sites/{$notMine->id}/search-console", ['property' => 'sc-domain:karoo.co.za'])->assertForbidden();
    }

    public function test_another_workspaces_site_is_404_everywhere(): void
    {
        $this->connectGoogle();
        $rival = $this->makeWorkspace('Rival');
        $site = $this->linkedSite($this->makeClient($rival));
        $as = fn () => $this->actingAs($this->ownerOf($this->workspace()), 'sanctum');

        $as()->getJson('/api/search-console/sites')->assertJsonCount(0, 'data');
        foreach (['overview', 'queries', 'pages', 'quick-wins', 'low-ctr', 'content-decay'] as $report) {
            $as()->getJson("/api/sites/{$site->id}/search-console/{$report}")->assertNotFound();
        }
        $as()->putJson("/api/sites/{$site->id}/search-console", ['property' => 'sc-domain:karoo.co.za'])->assertNotFound();
        $as()->postJson("/api/sites/{$site->id}/search-console/sync")->assertNotFound();
        $as()->deleteJson("/api/sites/{$site->id}/search-console")->assertNotFound();
        $this->assertSame('sc-domain:example.co.za', $site->fresh()->gsc_property);
    }
}
