<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\GoogleConnection;
use App\Models\Report;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\ChangeHistory;
use App\Support\Permissions;
use App\Traits\Auditable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthTestHelpers;
use Tests\TestCase;

/** Settings › Change history: what each user sees, and Undo. */
class ChangeHistoryTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->manager = $this->makeUser(Role::MANAGER);
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    private function entry(string $table, string $action): AuditLog
    {
        return AuditLog::query()->where('table_name', $table)->where('action_type', $action)->latest('id')->firstOrFail();
    }

    public function test_every_user_editable_model_is_audited_and_in_the_catalogue(): void
    {
        foreach (ChangeHistory::TABLES as $table => [$model]) {
            $this->assertContains(Auditable::class, class_uses_recursive($model), "{$model} is not Auditable");
            $this->assertSame($table, (new $model)->getTable());
        }
        $this->assertArrayHasKey('settings_change_history', Permissions::resources());
    }

    public function test_reports_and_google_log_without_their_secrets(): void
    {
        $client = $this->makeClient();
        $site = Site::factory()->create(['workspace_id' => $this->workspace()->id, 'client_id' => $client->id]);
        $report = $this->inWorkspace($this->workspace(), fn () => Report::create([
            'site_id' => $site->id, 'client_id' => $client->id, 'title' => 'October report',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'sections' => ['search_console'],
        ]));
        $this->inWorkspace($this->workspace(), fn () => $report->forceFill(['share_token' => str_repeat('a', 40)])->save());

        $shared = $this->entry('reports', 'UPDATE');
        $this->assertSame(['shared' => ['old' => false, 'new' => true]], $shared->field_changes);
        $this->assertStringNotContainsString(str_repeat('a', 40), json_encode($shared->getAttributes()));

        $this->inWorkspace($this->workspace(), fn () => GoogleConnection::create([
            'google_email' => 'seo@acme.co.za', 'access_token' => 'secret-access', 'refresh_token' => 'secret-refresh', 'status' => 'active',
        ]));
        $google = $this->entry('google_connections', 'CREATE');
        $this->assertSame('seo@acme.co.za', $google->field_changes['google_email']['new']);
        $this->assertStringNotContainsString('secret', json_encode($google->getAttributes()));
    }

    public function test_lists_labelled_changes_with_undo_and_filters(): void
    {
        $id = $this->as($this->manager)->postJson('/api/clients', ['name' => 'Durban Dental'])->json('data.id');
        $this->as($this->manager)->putJson("/api/clients/{$id}", ['name' => 'Durban Dental Group'])->assertOk();

        $this->as($this->manager)->getJson('/api/settings/change-history?page_key=clients_clients')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'update')
            ->assertJsonPath('data.0.page', 'Clients')
            ->assertJsonPath('data.0.record_type', 'Client')
            ->assertJsonPath('data.0.record_label', 'Durban Dental Group')
            ->assertJsonPath('data.0.changes.0', ['field' => 'name', 'old' => 'Durban Dental', 'new' => 'Durban Dental Group', 'label' => 'Name'])
            ->assertJsonPath('data.0.undo', ['available' => true, 'reason' => null])
            ->assertJsonPath('data.1.action', 'create')
            ->assertJsonPath('data.1.undo', ['available' => false, 'reason' => 'To undo adding it, delete it on its page.']);

        $options = $this->as($this->manager)->getJson('/api/settings/change-history/options')->assertOk();
        $this->assertContains(['key' => 'clients_clients', 'label' => 'Clients'], $options->json('pages'));
        $this->assertSame([['id' => $this->manager->id, 'name' => $this->manager->displayName()]], $options->json('users'));

        $this->as($this->manager)->getJson('/api/settings/change-history?action=create')->assertJsonCount(1, 'data');
        $this->as($this->manager)->getJson('/api/settings/change-history?search=durban')->assertJsonCount(2, 'data');
        $this->as($this->manager)->getJson('/api/settings/change-history?page_key=settings_users')->assertJsonValidationErrors('page_key');
    }

    public function test_users_see_only_their_pages_and_their_clients(): void
    {
        $mine = $this->makeClient(state: ['name' => 'Mine']);
        $theirs = $this->makeClient(state: ['name' => 'Theirs']);
        $role = $this->inWorkspace($this->workspace(), fn () => tap(Role::create(['name' => 'Auditor', 'guard_name' => 'web']))
            ->syncPermissions(['view_settings_change_history', 'view_clients_clients']));
        $auditor = User::factory()->create();
        $this->addToWorkspace($auditor, $this->workspace(), $role->name, [$mine]);

        foreach ([$mine, $theirs] as $client) {
            $this->as($this->manager)->putJson("/api/clients/{$client->id}", ['name' => $client->name.' renamed'])->assertOk();
        }
        $this->as($this->manager)->postJson('/api/sites', ['client_id' => $mine->id, 'url' => 'mine.co.za'])->assertCreated();

        $labels = collect($this->as($auditor)->getJson('/api/settings/change-history')->assertOk()->json('data'))->pluck('record_label');
        $this->assertContains('Mine renamed', $labels);
        $this->assertNotContains('Theirs renamed', $labels);
        $this->assertCount(0, collect($this->as($auditor)->getJson('/api/settings/change-history')->json('data'))->where('page_key', 'clients_sites'));

        $other = AuditLog::query()->where('table_name', 'clients')->where('record_id', (string) $theirs->id)->latest('id')->first();
        $this->as($auditor)->postJson("/api/settings/change-history/{$other->id}/undo")->assertNotFound();

        // Undo needs update_settings_change_history.
        $own = AuditLog::query()->where('table_name', 'clients')->where('record_id', (string) $mine->id)->where('action_type', 'UPDATE')->first();
        $this->as($auditor)->getJson('/api/settings/change-history')->assertJsonPath('data.0.undo', ['available' => false, 'reason' => null]);
        $this->as($auditor)->postJson("/api/settings/change-history/{$own->id}/undo")->assertForbidden();

        $this->as($this->makeUser(Role::CLIENT))->getJson('/api/settings/change-history')->assertForbidden();
    }

    public function test_undo_an_edit_puts_it_back_marks_it_and_logs_the_undo(): void
    {
        $client = $this->makeClient(state: ['name' => 'Acme', 'is_active' => true, 'notes' => null]);
        $this->as($this->manager)->putJson("/api/clients/{$client->id}", ['name' => 'Acme Holdings', 'is_active' => false, 'notes' => 'Moved'])->assertOk();
        $edit = $this->entry('clients', 'UPDATE');

        $this->as($this->manager)->postJson("/api/settings/change-history/{$edit->id}/undo")
            ->assertOk()
            ->assertJsonPath('data.undone_by', $this->manager->displayName())
            ->assertJsonPath('data.undo', ['available' => false, 'reason' => null]);

        $client->refresh();
        $this->assertSame(['Acme', true, null], [$client->name, $client->is_active, $client->notes]);
        $undo = AuditLog::query()->where('undo_of', $edit->id)->firstOrFail();
        $this->assertSame(['old' => 'Acme Holdings', 'new' => 'Acme'], $undo->field_changes['name']);

        $this->as($this->manager)->postJson("/api/settings/change-history/{$edit->id}/undo")
            ->assertStatus(409)->assertJsonPath('error_type', 'not_undoable')->assertJsonPath('message', 'Already undone.');
        // The undo itself can be undone (redo).
        $this->as($this->manager)->postJson("/api/settings/change-history/{$undo->id}/undo")->assertOk();
        $this->assertSame('Acme Holdings', $client->fresh()->name);
    }

    public function test_an_edit_changed_again_since_must_be_undone_last_first(): void
    {
        $client = $this->makeClient(state: ['name' => 'Acme']);
        $this->as($this->manager)->putJson("/api/clients/{$client->id}", ['name' => 'Acme 2'])->assertOk();
        $first = $this->entry('clients', 'UPDATE');
        $this->as($this->manager)->putJson("/api/clients/{$client->id}", ['name' => 'Acme 3'])->assertOk();

        $this->as($this->manager)->postJson("/api/settings/change-history/{$first->id}/undo")
            ->assertStatus(409)->assertJsonPath('error_type', 'changed_since')
            ->assertJsonPath('message', 'Name has changed again since. Undo the later change first.');
        $this->assertSame('Acme 3', $client->fresh()->name);
        $this->assertNull($first->fresh()->undone_at);
    }

    public function test_undo_a_deletion_restores_it_once_its_parent_is_back(): void
    {
        $client = $this->makeClient();
        $site = Site::factory()->create(['workspace_id' => $this->workspace()->id, 'client_id' => $client->id]);
        $this->as($this->manager)->deleteJson("/api/sites/{$site->id}")->assertNoContent();
        $deleted = $this->entry('sites', 'DELETE');
        $this->inWorkspace($this->workspace(), fn () => $client->delete());

        $this->as($this->manager)->postJson("/api/settings/change-history/{$deleted->id}/undo")
            ->assertStatus(409)->assertJsonPath('message', 'Its client was deleted: undo that first.');

        $this->inWorkspace($this->workspace(), fn () => $client->restore());
        $this->as($this->manager)->postJson("/api/settings/change-history/{$deleted->id}/undo")->assertOk();
        $this->assertFalse($site->fresh()->trashed());
        $this->assertSame((string) $deleted->id, (string) $this->entry('sites', 'RESTORE')->undo_of);
    }

    public function test_undo_a_roles_permission_change(): void
    {
        $owner = $this->ownerOf($this->workspace());
        $role = $this->inWorkspace($this->workspace(), fn () => Role::create(['name' => 'Intern', 'guard_name' => 'web']));
        $this->inWorkspace($this->workspace(), fn () => $role->syncPermissions(['view_reports']));
        $this->as($owner)->putJson("/api/settings/roles/{$role->id}/permissions", ['permissions' => ['view_reports', 'create_reports', 'view_site_audit']])->assertOk();
        $change = $this->entry('roles', 'UPDATE');

        $this->as($owner)->postJson("/api/settings/change-history/{$change->id}/undo")->assertOk();

        $this->assertSame(['view_reports'], $role->fresh()->permissions->pluck('name')->sort()->values()->all());
        $undo = AuditLog::query()->where('undo_of', $change->id)->firstOrFail();
        $this->assertSame(['old' => 'View, Create', 'new' => 'View'], $undo->field_changes['reports']);
    }

    public function test_what_cant_be_undone_says_why(): void
    {
        $this->as($this->manager)->postJson('/api/clients', ['name' => 'Fresh'])->assertCreated();
        $created = $this->entry('clients', 'CREATE');
        $this->as($this->manager)->postJson("/api/settings/change-history/{$created->id}/undo")
            ->assertStatus(409)->assertJsonPath('error_type', 'not_undoable');

        $user = $this->makeUser(Role::ANALYST);
        $this->inWorkspace($this->workspace(), fn () => $user->update(['surname' => 'Changed']));
        $owner = $this->ownerOf($this->workspace());
        $edit = $this->entry('users', 'UPDATE');
        $this->as($owner)->postJson("/api/settings/change-history/{$edit->id}/undo")
            ->assertStatus(409)->assertJsonPath('message', 'Change people\'s accounts on Users and access.');

        // Gone for good (hard-deleted).
        $client = $this->makeClient(state: ['name' => 'Short lived']);
        $this->as($this->manager)->putJson("/api/clients/{$client->id}", ['name' => 'Shorter'])->assertOk();
        $edit = $this->entry('clients', 'UPDATE');
        Client::withTrashed()->whereKey($client->id)->forceDelete();
        $this->as($this->manager)->postJson("/api/settings/change-history/{$edit->id}/undo")
            ->assertStatus(409)->assertJsonPath('error_type', 'record_gone');
    }

    public function test_another_workspaces_entries_are_404(): void
    {
        $other = $this->makeWorkspace('Other');
        $client = $this->makeClient($other);
        $this->inWorkspace($other, fn () => $client->update(['name' => 'Elsewhere']));
        $entry = AuditLog::query()->where('workspace_id', $other->id)->where('table_name', 'clients')->latest('id')->firstOrFail();

        $this->as($this->manager)->postJson("/api/settings/change-history/{$entry->id}/undo")->assertNotFound();
        $this->assertNotContains('Elsewhere', collect($this->as($this->manager)->getJson('/api/settings/change-history')->json('data'))->pluck('record_label'));
    }
}
