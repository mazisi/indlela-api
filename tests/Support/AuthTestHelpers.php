<?php

namespace Tests\Support;

use App\Mail\EmailTemplateType;
use App\Mail\TemplatedMail;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sms\SmsSender;
use App\Services\Workspaces\WorkspaceProvisioner;
use App\Support\CurrentWorkspace;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Mail;

/**
 * Ported from workforce-api's helpers, with workspaces: every user is made in
 * a workspace (by default the test's own, `$this->workspace`), with a role
 * there and, for roles that don't see every client, one client assigned.
 */
trait AuthTestHelpers
{
    protected FakeSmsSender $sms;

    private ?Workspace $defaultWorkspace = null;

    /** The permission catalogue is seeded once per run (TestCase::$seeder). */
    protected function setUpAuth(): void
    {
        Mail::fake();
        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
    }

    /** The test's default workspace (made with its Owner on first use). */
    protected function workspace(): Workspace
    {
        return $this->defaultWorkspace ??= $this->makeWorkspace();
    }

    /** A workspace with its default roles; $owner (or a new user) is its Owner. */
    protected function makeWorkspace(string $name = 'Acme Digital', ?User $owner = null): Workspace
    {
        $owner ??= User::factory()->create();

        return app(WorkspaceProvisioner::class)->create($name.' '.fake()->unique()->numberBetween(1, 999999), $owner);
    }

    /** The first Owner of a workspace (the one makeWorkspace made). */
    protected function ownerOf(Workspace $workspace): User
    {
        return $workspace->users()->orderBy('workspace_user.id')->firstOrFail();
    }

    /**
     * A member of $workspace (default: the test's) with $role there.
     *
     * @param  list<Client>|null  $clients  assigned clients; null = one new client for roles that need some
     */
    protected function makeUser(string $role = Role::ANALYST, array $state = [], ?Workspace $workspace = null, ?array $clients = null): User
    {
        $workspace ??= $this->workspace();
        $user = User::factory()->create($state);
        $this->addToWorkspace($user, $workspace, $role, $clients);

        return $user;
    }

    protected function makeOwner(?Workspace $workspace = null): User
    {
        return $this->makeUser(Role::OWNER, workspace: $workspace);
    }

    /** @param  list<Client>|null  $clients */
    protected function addToWorkspace(User $user, Workspace $workspace, string $role, ?array $clients = null): void
    {
        $user->workspaces()->syncWithoutDetaching([$workspace->id]);
        if ($user->current_workspace_id === null) {
            $user->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();
        }

        $this->inWorkspace($workspace, function () use ($user, $workspace, $role, $clients) {
            $model = Role::inWorkspace($workspace->id)->where('name', $role)->firstOrFail();
            $user->unsetRelation('roles');
            $user->assignRole($model);
            if ($clients === null && ! ($model->isSystem() || $model->all_clients)) {
                $clients = [$this->makeClient($workspace)];
            }
            $user->clients()->attach(collect($clients ?? [])->pluck('id')->all());
        });
    }

    protected function makeClient(?Workspace $workspace = null, array $state = []): Client
    {
        return Client::factory()->create(['workspace_id' => ($workspace ?? $this->workspace())->id, ...$state]);
    }

    /** Runs $work with $workspace current (spatie team, scopes), e.g. to check $user->can(). */
    protected function inWorkspace(Workspace $workspace, \Closure $work): mixed
    {
        return app(CurrentWorkspace::class)->using($workspace, $work);
    }

    /** Whether $user holds $permission in $workspace. */
    protected function userCan(User $user, string $permission, ?Workspace $workspace = null): bool
    {
        return $this->inWorkspace($workspace ?? $this->workspace(), fn () => $user->fresh()->can($permission));
    }

    /** POST /login with the factory password. */
    protected function login(User $user, string $password = UserFactory::PASSWORD)
    {
        return $this->postJson('/api/login', ['email' => $user->email, 'password' => $password]);
    }

    /** The PIN from the most recent sign-in PIN email to this address. */
    protected function lastEmailedPin(string $email): ?string
    {
        $mail = Mail::sent(TemplatedMail::class, fn (TemplatedMail $m) => $m->type === EmailTemplateType::LoginPin && $m->hasTo($email))->last();

        return $mail?->vars['code'];
    }

    /** The reset URL from the most recent reset email to this address. */
    protected function lastResetUrl(string $email): ?string
    {
        $mail = Mail::sent(TemplatedMail::class, fn (TemplatedMail $m) => in_array($m->type, [EmailTemplateType::PasswordReset, EmailTemplateType::UserInvited], true) && $m->hasTo($email))->last();

        return $mail?->url;
    }
}
