<?php

namespace Tests\Feature\Auth;

use App\Mail\EmailTemplateType;
use App\Mail\TemplatedMail;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AuthTestHelpers;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => '  Thandi   Mokoena ',
            'email' => 'Thandi@Acme.co.za',
            'password' => 'Fresh#2027',
            'password_confirmation' => 'Fresh#2027',
            'workspace_name' => 'Acme Digital',
        ], $overrides);
    }

    public function test_sign_up_creates_the_account_and_workspace_and_signs_in_as_owner(): void
    {
        $response = $this->postJson('/api/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('requires_mfa', false)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'thandi@acme.co.za')
            ->assertJsonPath('user.full_name', 'Thandi')
            ->assertJsonPath('user.surname', 'Mokoena')
            ->assertJsonPath('user.role', 'Owner')
            ->assertJsonPath('roles', ['Owner'])
            ->assertJsonPath('workspace.name', 'Acme Digital')
            ->assertJsonPath('workspace.slug', 'acme-digital')
            ->assertJsonPath('workspaces.0.role', 'Owner')
            ->assertJsonStructure(['message', 'user', 'token', 'token_type', 'roles', 'permissions', 'workspace', 'workspaces']);

        $workspace = Workspace::where('slug', 'acme-digital')->firstOrFail();
        $user = User::whereEmail('thandi@acme.co.za')->firstOrFail();
        $this->assertSame($workspace->id, $user->current_workspace_id);
        $this->assertEqualsCanonicalizing(
            [Role::OWNER, Role::MANAGER, Role::ANALYST, Role::CLIENT],
            Role::inWorkspace($workspace->id)->pluck('name')->all(),
        );
        $this->assertContains('update_settings_roles', $response->json('permissions'));

        // The token works straight away.
        $this->withToken($response->json('token'))->getJson('/api/clients')->assertOk();

        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $m) => $m->type === EmailTemplateType::WorkspaceCreated && $m->hasTo('thandi@acme.co.za'));
        $this->assertDatabaseHas('login_events', ['user_id' => $user->id, 'event' => 'registered', 'note' => 'Created workspace Acme Digital']);
    }

    public function test_slugs_stay_unique(): void
    {
        $this->postJson('/api/register', $this->payload())->assertCreated();
        $this->postJson('/api/register', $this->payload(['email' => 'other@acme.co.za']))
            ->assertCreated()
            ->assertJsonPath('workspace.slug', 'acme-digital-2');
    }

    public function test_an_existing_email_is_sent_to_sign_in_instead(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/register', $this->payload(['email' => strtoupper($user->email)]))
            ->assertJsonPath('errors.email.0', 'There is already an account for this email. Sign in or reset your password; to join a workspace, ask its Owner to add you.');
    }

    public function test_the_form_is_validated(): void
    {
        $this->postJson('/api/register', $this->payload(['name' => '', 'workspace_name' => '', 'password' => 'weak', 'password_confirmation' => 'weak', 'mobile' => '123']))
            ->assertJsonValidationErrors(['name', 'workspace_name', 'password', 'mobile']);

        $this->postJson('/api/register', $this->payload(['password_confirmation' => 'Other#2027']))
            ->assertJsonPath('errors.password.0', 'The passwords do not match.');

        $this->assertSame(0, User::count());
    }

    public function test_a_single_name_has_no_surname(): void
    {
        $this->postJson('/api/register', $this->payload(['name' => 'Cher']))
            ->assertCreated()
            ->assertJsonPath('user.name', 'Cher')
            ->assertJsonPath('user.surname', '');
    }
}
