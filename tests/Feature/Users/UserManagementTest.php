<?php

namespace Tests\Feature\Users;

use App\Enums\LoginEventType;
use App\Enums\UserStatus;
use App\Mail\EmailTemplateType;
use App\Mail\TemplatedMail;
use App\Models\AuditLog;
use App\Models\LoginEvent;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AuthTestHelpers;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->owner = $this->ownerOf($this->workspace());
    }

    private function roleId(string $name, ?Workspace $workspace = null): int
    {
        return Role::inWorkspace(($workspace ?? $this->workspace())->id)->where('name', $name)->value('id');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => ' Thandi ',
            'surname' => 'Mokoena',
            'email' => 'Thandi@Example.co.za',
            'mobile' => '082 555 0199',
            'mfa_channel' => 'sms',
            'role_id' => $this->roleId(Role::ANALYST),
            'client_ids' => [$this->makeClient()->id],
        ], $overrides);
    }

    public function test_the_list_is_the_workspaces_members_paginated_15_searchable_with_status_counts(): void
    {
        foreach (range(1, 15) as $i) {
            $this->makeUser();
        }
        $kevin = $this->makeUser(Role::CLIENT, ['full_name' => 'Kevin', 'surname' => 'Adams', 'status' => UserStatus::Locked]);
        // Someone in another workspace only.
        $this->makeUser(workspace: $this->makeWorkspace('Elsewhere'));

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 17)
            ->assertJsonPath('summary.all', 17)
            ->assertJsonPath('summary.locked', 1);

        foreach (['kevin adams', 'CLIENT', $kevin->email] as $search) {
            $this->actingAs($this->owner, 'sanctum')->getJson('/api/users?search='.urlencode($search))
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.name', 'Kevin Adams')
                ->assertJsonPath('data.0.role.name', 'Client')
                ->assertJsonPath('data.0.status_label', 'Locked')
                ->assertJsonCount(1, 'data.0.clients');
        }

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/users?status=locked')->assertJsonCount(1, 'data');
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/users?search='.urlencode($this->owner->email))
            ->assertJsonPath('data.0.is_you', true)
            ->assertJsonPath('data.0.role.is_system', true);
    }

    public function test_adding_a_new_person_emails_a_72_hour_set_password_link_and_logs_it(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')->postJson('/api/users', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Thandi Mokoena')
            ->assertJsonPath('data.email', 'thandi@example.co.za')
            ->assertJsonPath('data.mobile', '0825550199')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.role.name', 'Analyst')
            ->assertJsonCount(1, 'data.clients');

        $user = User::find($response->json('data.id'));
        $this->assertFalse(Hash::check('Demo@2026', $user->password), 'no usable password until they choose one');
        $this->assertTrue($user->belongsToWorkspace($this->workspace()));
        $this->assertSame($this->workspace()->id, $user->current_workspace_id);
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $m) => $m->type === EmailTemplateType::UserInvited
            && $m->hasTo('thandi@example.co.za')
            && str_contains($m->url, '/reset-password?token=')
            && $m->vars['ttl'] === '72 hours'
            && $m->vars['workspace'] === $this->workspace()->name
            && $m->workspaceId === $this->workspace()->id);
        $this->assertTrue(LoginEvent::where('user_id', $user->id)->where('event', LoginEventType::Created)->exists());

        $history = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/users/history')->json('data'))
            ->where('record_id', (string) $user->id)->values()->all();
        $this->assertSame(['update', 'create'], array_column($history, 'action'));
        $this->assertSame($this->owner->displayName(), $history[0]['user']);
        $this->assertSame('Thandi Mokoena', $history[1]['record_label']);
        $this->assertContains(['field' => 'role', 'old' => null, 'new' => 'Analyst'], $history[0]['changes']);
        $this->assertNotContains('password', array_column($history[1]['changes'], 'field'));
    }

    public function test_adding_an_existing_account_adds_it_to_the_workspace_without_touching_it(): void
    {
        $elsewhere = $this->makeWorkspace('Other agency');
        $sipho = $this->makeUser(Role::MANAGER, ['full_name' => 'Sipho', 'surname' => 'Dlamini'], $elsewhere);
        $password = $sipho->password;

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/users', $this->payload(['email' => strtoupper($sipho->email), 'full_name' => 'Somebody', 'role_id' => $this->roleId(Role::CLIENT)]))
            ->assertCreated()
            ->assertJsonPath('data.id', $sipho->id)
            ->assertJsonPath('data.name', 'Sipho Dlamini')
            ->assertJsonPath('data.role.name', 'Client');

        $sipho->refresh();
        $this->assertSame($password, $sipho->password);
        $this->assertSame($elsewhere->id, $sipho->current_workspace_id, 'they stay in the workspace they were in');
        $this->assertTrue($sipho->belongsToWorkspace($this->workspace()));
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $m) => $m->type === EmailTemplateType::AddedToWorkspace && $m->hasTo($sipho->email));
        Mail::assertNotSent(TemplatedMail::class, fn (TemplatedMail $m) => $m->type === EmailTemplateType::UserInvited);

        // Their role elsewhere is untouched.
        $this->assertTrue($this->userCan($sipho, 'delete_clients_clients', $elsewhere));
        $this->assertFalse($this->userCan($sipho, 'update_clients_clients', $this->workspace()));

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/users', $this->payload(['email' => $sipho->email]))
            ->assertJsonPath('errors.email.0', "{$sipho->email} is already in this workspace.");
    }

    public function test_roles_that_see_every_client_need_none_and_others_need_some(): void
    {
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/users', $this->payload(['role_id' => $this->roleId(Role::MANAGER)]))
            ->assertCreated()
            ->assertJsonCount(0, 'data.clients')
            ->assertJsonPath('data.role.all_clients', true)
            ->json('data.id');
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $m) => $m->vars['clients'] === 'all clients');

        $edit = ['full_name' => 'Thandi', 'surname' => 'Mokoena', 'email' => 'thandi@example.co.za', 'mobile' => '0825550199', 'mfa_channel' => 'sms', 'status' => 'active'];
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/users/{$id}", [...$edit, 'role_id' => $this->roleId(Role::CLIENT)])
            ->assertJsonPath('errors.client_ids.0', 'Give access to at least one client.');
    }

    public function test_roles_and_clients_must_be_this_workspaces(): void
    {
        $other = $this->makeWorkspace('Other');
        $theirClient = $this->makeClient($other);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/users', $this->payload(['role_id' => $this->roleId(Role::ANALYST, $other), 'client_ids' => [$theirClient->id]]))
            ->assertJsonValidationErrors(['role_id', 'client_ids.0']);
    }

    public function test_editing_changes_details_role_clients_and_status_and_logs_each(): void
    {
        $user = $this->makeUser(Role::CLIENT);
        $user->createToken('phone');
        $client = $this->makeClient(state: ['name' => 'Zulu Foods']);

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/users/{$user->id}", $this->payload([
                'email' => $user->email, 'role_id' => $this->roleId(Role::ANALYST), 'client_ids' => [$client->id], 'status' => 'disabled',
            ]))
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled')
            ->assertJsonPath('data.role.name', 'Analyst')
            ->assertJsonPath('data.clients', [['id' => $client->id, 'name' => 'Zulu Foods']]);

        $this->assertSame(0, $user->tokens()->count(), 'a disabled user is signed out');
        $this->assertTrue(LoginEvent::where('user_id', $user->id)->where('event', LoginEventType::Disabled)->exists());

        $changes = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/users/history')->json('data'))
            ->where('record_id', (string) $user->id)->where('action', 'update')
            ->flatMap(fn ($e) => $e['changes'])->keyBy('field');
        $this->assertSame('Analyst', $changes['role']['new']);
        $this->assertSame('Zulu Foods', $changes['clients']['new']);
        $this->assertSame('disabled', $changes['status']['new']);
    }

    public function test_a_shared_accounts_details_and_status_are_its_own(): void
    {
        $user = $this->makeUser();
        $this->addToWorkspace($user, $this->makeWorkspace('Other'), Role::MANAGER);
        $base = ['full_name' => $user->full_name, 'surname' => $user->surname, 'email' => $user->email, 'mobile' => $user->mobile,
            'mfa_channel' => 'email', 'role_id' => $this->roleId(Role::MANAGER), 'status' => 'active'];

        // Role and clients: fine.
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/users/{$user->id}", $base)->assertOk()->assertJsonPath('data.role.name', 'Manager');

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/users/{$user->id}", [...$base, 'full_name' => 'Renamed'])
            ->assertJsonValidationErrors('email');
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/users/{$user->id}", [...$base, 'status' => 'disabled'])
            ->assertJsonValidationErrors('email');
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/users/bulk', ['action' => 'disable', 'ids' => [$user->id]])
            ->assertJsonValidationErrors('ids');

        $this->assertSame(UserStatus::Active, $user->fresh()->status);
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/users?search='.urlencode($user->email))
            ->assertJsonPath('data.0.shared_account', true);
    }

    public function test_unlocking_by_edit_clears_the_lockout(): void
    {
        $user = $this->makeUser(state: ['status' => UserStatus::Locked, 'failed_login_attempts' => 5, 'locked_at' => now()]);

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/users/{$user->id}", $this->payload(['email' => $user->email, 'status' => 'active']))
            ->assertOk();

        $this->assertSame(0, $user->fresh()->failed_login_attempts);
        $this->assertNull($user->fresh()->locked_at);
        $this->assertTrue(LoginEvent::where('user_id', $user->id)->where('event', LoginEventType::Unlocked)->exists());
    }

    public function test_sign_ins_do_not_fill_the_change_history(): void
    {
        $user = $this->makeUser(state: ['mfa_channel' => 'off']);
        AuditLog::query()->delete();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Demo@2026'])->assertOk();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Wrong@2026']);

        $this->assertSame(0, AuditLog::where('table_name', 'users')->count());
    }

    public function test_you_cannot_disable_or_remove_yourself_or_the_last_owner(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/users/{$this->owner->id}", $this->payload(['email' => $this->owner->email, 'status' => 'disabled', 'role_id' => $this->roleId(Role::OWNER)]))
            ->assertJsonPath('errors.status.0', 'You cannot disable or lock your own account.');

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/users/{$this->owner->id}", $this->payload(['email' => $this->owner->email, 'status' => 'active']))
            ->assertJsonPath('errors.role_id.0', 'This is the only active Owner of the workspace. Make someone else an Owner first.');

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/users/{$this->owner->id}")
            ->assertJsonPath('errors.ids.0', 'You cannot remove yourself from the workspace.');

        // A second Owner may disable the first only while one stays active.
        $other = $this->makeOwner();
        $this->actingAs($other, 'sanctum')->postJson('/api/users/bulk', ['action' => 'disable', 'ids' => [$this->owner->id]])->assertOk();
        $this->actingAs($other, 'sanctum')->deleteJson("/api/users/{$this->owner->id}")->assertNoContent();
    }

    public function test_an_owner_in_another_workspace_does_not_count_here(): void
    {
        $other = $this->makeWorkspace('Other');
        $second = $this->makeUser(Role::OWNER, workspace: $other);
        $this->addToWorkspace($second, $this->workspace(), Role::MANAGER);

        // The only Owner here can't be demoted, though the other workspace has Owners.
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/users/{$this->owner->id}", $this->payload(['email' => $this->owner->email, 'status' => 'active']))
            ->assertJsonValidationErrors('role_id');
    }

    public function test_removing_takes_the_user_out_of_the_workspace_and_deletes_the_account_only_when_it_was_their_last(): void
    {
        $only = $this->makeUser();
        $only->createToken('phone');
        $shared = $this->makeUser();
        $other = $this->makeWorkspace('Other');
        $this->addToWorkspace($shared, $other, Role::MANAGER);

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/users/bulk', ['action' => 'delete', 'ids' => [$only->id, $shared->id]])
            ->assertOk()->assertJsonPath('affected', 2);

        $this->assertSoftDeleted($only);
        $this->assertSame(0, $only->tokens()->count());
        $this->assertNotSoftDeleted($shared);
        $shared->refresh();
        $this->assertFalse($shared->belongsToWorkspace($this->workspace()));
        $this->assertSame(0, $this->inWorkspace($this->workspace(), fn () => $shared->assignedClients()->count()));
        $this->assertTrue($this->userCan($shared, 'delete_clients_clients', $other), 'their other workspace is untouched');

        // Next request lands them in their other workspace.
        $this->actingAs($shared, 'sanctum')->getJson('/api/me')->assertJsonPath('workspace.id', $other->id);
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/users')->assertJsonPath('meta.total', 1);
    }

    public function test_a_removed_account_added_again_gets_a_fresh_invitation(): void
    {
        $user = $this->makeUser();
        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/users/{$user->id}")->assertNoContent();
        $this->assertSoftDeleted($user);

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/users', $this->payload(['email' => $user->email]))
            ->assertCreated()->assertJsonPath('data.id', $user->id)->assertJsonPath('data.name', 'Thandi Mokoena');
        $this->assertFalse(Hash::check('Demo@2026', $user->fresh()->password));
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $m) => $m->type === EmailTemplateType::UserInvited && $m->hasTo($user->email));
    }

    public function test_bulk_enable_disable_and_remove_are_all_or_nothing(): void
    {
        [$a, $b] = [$this->makeUser(), $this->makeUser()];

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/users/bulk', ['action' => 'disable', 'ids' => [$a->id, $b->id]])
            ->assertOk()->assertJsonPath('affected', 2);
        $this->assertSame(UserStatus::Disabled, $a->fresh()->status);

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/users/bulk', ['action' => 'enable', 'ids' => [$a->id]])->assertOk();
        $this->assertSame(UserStatus::Active, $a->fresh()->status);

        // Including yourself fails the whole batch.
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/users/bulk', ['action' => 'delete', 'ids' => [$a->id, $this->owner->id]])
            ->assertJsonValidationErrors('ids');
        $this->assertNotSoftDeleted($a);
    }

    public function test_only_owners_can_manage_users(): void
    {
        $manager = $this->makeUser(Role::MANAGER);
        $user = $this->makeUser();

        $this->actingAs($manager, 'sanctum')->getJson('/api/users')->assertForbidden();
        $this->actingAs($manager, 'sanctum')->getJson('/api/users/options')->assertForbidden();
        $this->actingAs($manager, 'sanctum')->postJson('/api/users', $this->payload())->assertForbidden();
        $this->actingAs($manager, 'sanctum')->putJson("/api/users/{$user->id}", $this->payload())->assertForbidden();
        $this->actingAs($manager, 'sanctum')->deleteJson("/api/users/{$user->id}")->assertForbidden();
        $this->actingAs($manager, 'sanctum')->postJson('/api/users/bulk', ['action' => 'disable', 'ids' => [$user->id]])->assertForbidden();
    }

    public function test_form_options_are_this_workspaces_roles_and_active_clients(): void
    {
        $this->makeClient(state: ['name' => 'Closed', 'is_active' => false]);
        $this->makeClient($this->makeWorkspace('Other'), ['name' => 'Not ours']);
        $open = $this->makeClient(state: ['name' => 'Open']);

        $response = $this->actingAs($this->owner, 'sanctum')->getJson('/api/users/options')->assertOk();

        $this->assertSame([Role::OWNER, Role::ANALYST, Role::CLIENT, Role::MANAGER], array_column($response->json('roles'), 'name'));
        $this->assertTrue($response->json('roles.0.is_system'));
        $this->assertSame(['Open'], array_column($response->json('clients'), 'name'));
        $this->assertSame($open->id, $response->json('clients.0.id'));
    }
}
