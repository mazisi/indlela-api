<?php

namespace App\Services\Users;

use App\Enums\LoginEventType;
use App\Enums\UserStatus;
use App\Mail\EmailTemplateType;
use App\Mail\TemplatedMail;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\LoginChallengeService;
use App\Services\Auth\LoginEventLogger;
use App\Services\Auth\PasswordResetTokenService;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Settings › Users and access in the current workspace (ported from
 * workforce-api): adding (inviting), changing and removing its members.
 *
 * Tenancy rules on top of workforce's:
 *  - Adding an email that has no account creates it and emails a 72-hour
 *    set-password link; an existing account is just added to the workspace
 *    (and told so). Its role and clients are this workspace's.
 *  - Name, email, mobile, PIN channel and status are the account's, shared by
 *    every workspace it is in: a workspace may change them only while the
 *    account is in no other workspace.
 *  - "Delete" removes the user from this workspace; the account itself is
 *    deleted only when that leaves it in no workspace.
 *
 * Guards: nobody disables or removes themselves, and the workspace's last
 * active Owner can't be disabled, removed or given another role.
 */
class UserManagementService
{
    /** Statuses an Owner can set by editing a user. */
    public const EDITABLE_STATUSES = [UserStatus::Active, UserStatus::Disabled, UserStatus::Locked];

    /** Fields that belong to the account rather than the workspace membership. */
    private const ACCOUNT_FIELDS = ['full_name', 'surname', 'email', 'mobile', 'mfa_channel'];

    /** pg_advisory_xact_lock key (with the workspace id) for changes the Owner guards protect. */
    private const OWNER_CHANGES_LOCK = 7_340_001;

    public function __construct(
        private readonly PasswordResetTokenService $tokens,
        private readonly LoginChallengeService $challenges,
        private readonly LoginEventLogger $events,
        private readonly CurrentWorkspace $current,
    ) {}

    /**
     * Add someone to the current workspace: a new account (emailed a link to
     * choose their password) or an existing one (emailed that they were added).
     *
     * @param  array{full_name: string, surname: string, email: string, mobile?: ?string, mfa_channel: string, role_id: int, client_ids?: list<int>}  $data
     */
    public function create(array $data, User $admin): User
    {
        $workspace = $this->workspace();
        $role = $this->role($data['role_id']);
        $clientIds = $this->clientIdsFor($role, $data);

        $existing = User::withTrashed()->whereEmail($data['email'])->first();
        if ($existing && ! $existing->trashed() && $existing->belongsToWorkspace($workspace)) {
            $this->fail('email', "{$existing->email} is already in this workspace.");
        }
        $isNew = ! $existing || $existing->trashed();

        $user = DB::transaction(function () use ($data, $role, $clientIds, $admin, $workspace, $existing, $isNew) {
            $details = collect($data)->only(self::ACCOUNT_FIELDS)->all();

            if (! $existing) {
                $user = User::create([
                    ...$details,
                    // Unusable until they set their own through the emailed link.
                    'password' => Str::random(64),
                    'status' => UserStatus::Active,
                ]);
            } elseif ($existing->trashed()) {
                // A deleted account comes back as a new one: fresh details, no usable password.
                $user = $existing;
                $user->restore();
                $user->forceFill([...$details, 'password' => Str::random(64), 'status' => UserStatus::Active,
                    'failed_login_attempts' => 0, 'locked_at' => null])->save();
            } else {
                $user = $existing;
            }

            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? ($isNew ? now() : null)])->saveQuietly();
            $user->workspaces()->syncWithoutDetaching([$workspace->id]);
            if ($user->current_workspace_id === null) {
                $user->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();
            }

            $user->unsetRelation('roles');
            $user->syncRoles([$role]);
            $this->syncClients($user, $clientIds);
            $this->logAccess($user, null, null, $role, $clientIds);

            $this->events->record(LoginEventType::Created, $user, 'To '.$workspace->name.' by '.$admin->displayName().' as '.$role->name);

            return $user;
        });

        $this->sendInvite($user, $role, $admin, $workspace, $isNew);

        return $this->fresh($user);
    }

    /**
     * @param  array{full_name: string, surname: string, email: string, mobile?: ?string, mfa_channel: string, role_id: int, client_ids?: list<int>, status: string}  $data
     */
    public function update(User $user, array $data, User $admin): User
    {
        $role = $this->role($data['role_id']);
        $clientIds = $this->clientIdsFor($role, $data);
        $status = UserStatus::from($data['status']);

        $this->serialised(function () use ($user, $data, $role, $clientIds, $status, $admin) {
            // Guards read fresh data under the lock, so two Owners can't both pass them at once.
            $user->refresh();
            $user->unsetRelation('roles');

            if ($user->is($admin) && $status !== UserStatus::Active) {
                $this->fail('status', 'You cannot disable or lock your own account.');
            }
            if (($status !== UserStatus::Active || ! $role->isSystem()) && $this->isLastActiveOwner($user)) {
                $this->fail('role_id', 'This is the only active Owner of the workspace. Make someone else an Owner first.');
            }

            $details = collect($data)->only(self::ACCOUNT_FIELDS)->all();
            $user->fill($details);
            $accountChanged = $user->isDirty(self::ACCOUNT_FIELDS) || $status !== $user->status;
            if ($accountChanged && $user->belongsToOtherWorkspaces($this->workspace())) {
                $this->fail('email', "{$user->displayName()} is also in another workspace, so their name, email, cell number, PIN channel and status are theirs to change. You can change their role and clients here.");
            }

            $oldRole = $user->currentRole();
            $oldClientIds = $user->assignedClients()->pluck('clients.id')->all();
            $wasStatus = $user->status;

            $user->status = $status;
            if ($status !== $wasStatus) {
                // Locking by hand looks like a lockout; anything else clears it.
                $user->forceFill($status === UserStatus::Locked
                    ? ['locked_at' => now()]
                    : ['failed_login_attempts' => 0, 'locked_at' => null]);
            }
            $user->save();

            $user->syncRoles([$role]);
            $this->syncClients($user, $clientIds);
            $this->logAccess($user, $oldRole, $oldClientIds, $role, $clientIds);

            if ($status !== $wasStatus) {
                $this->recordStatusChange($user, $wasStatus, $admin);
            }
        });

        return $this->fresh($user);
    }

    /** Remove the user from the current workspace (and delete the account if that was its last). */
    public function delete(User $user, User $admin): void
    {
        $this->serialised(function () use ($user, $admin) {
            $user->refresh();
            $user->unsetRelation('roles');
            $this->assertRemovable($user, $admin, 'remove');
            $this->removeFromWorkspace($user);
        });
    }

    /**
     * Enable, disable or remove several users; all or nothing.
     *
     * @param  Collection<int, User>  $users
     */
    public function bulk(string $action, Collection $users, User $admin): void
    {
        $this->serialised(function () use ($action, $users, $admin) {
            $users->each(fn (User $u) => $u->refresh()->unsetRelation('roles'));

            foreach ($users as $user) {
                if ($action === 'delete' || $action === 'disable') {
                    $this->assertRemovable($user, $admin, $action === 'delete' ? 'remove' : 'disable', $users);
                }
                if ($action !== 'delete' && $user->belongsToOtherWorkspaces($this->workspace())) {
                    $this->fail('ids', "{$user->displayName()} is also in another workspace, so can't be enabled or disabled here. Remove them from this workspace instead.");
                }
            }

            foreach ($users as $user) {
                match ($action) {
                    'delete' => $this->removeFromWorkspace($user),
                    default => $this->setStatus($user, $action === 'enable' ? UserStatus::Active : UserStatus::Disabled, $admin),
                };
            }
        });
    }

    private function removeFromWorkspace(User $user): void
    {
        $workspace = $this->workspace();
        $oldRole = $user->currentRole();
        $oldClientIds = $user->assignedClients()->pluck('clients.id')->all();

        $user->syncRoles([]);
        $this->syncClients($user, []);
        $user->workspaces()->detach($workspace->id);
        $user->logAuditChange('DELETE', array_filter([
            'role' => $oldRole ? ['old' => $oldRole->name, 'new' => null] : null,
            'clients' => $oldClientIds ? ['old' => $this->clientNames($oldClientIds), 'new' => null] : null,
            'workspace' => ['old' => $workspace->name, 'new' => null],
        ]), [], ['name' => $user->displayName(), 'full_name' => $user->full_name, 'surname' => $user->surname]);

        if ($user->workspaces()->doesntExist()) {
            // Their last workspace: the account goes too.
            $user->tokens()->delete();
            $this->challenges->closeAllFor($user);
            $user->forceFill(['current_workspace_id' => null])->saveQuietly();
            $user->delete();
        } elseif ($user->current_workspace_id === $workspace->id) {
            // Next request moves them to another of their workspaces.
            $user->forceFill(['current_workspace_id' => null])->saveQuietly();
        }
    }

    /**
     * Runs $work in a transaction holding the workspace's lock, so changes
     * that could remove its last active Owner happen one at a time: each
     * re-checks the guard after the previous one committed. The lock is
     * released when the transaction ends (and nests, for bulk()).
     */
    private function serialised(callable $work): mixed
    {
        return DB::transaction(function () use ($work) {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::OWNER_CHANGES_LOCK, $this->workspace()->id]);

            return $work();
        });
    }

    private function setStatus(User $user, UserStatus $status, User $admin): void
    {
        $was = $user->status;
        if ($was === $status) {
            return;
        }

        $user->forceFill(['status' => $status, 'failed_login_attempts' => 0, 'locked_at' => null])->save();
        $this->recordStatusChange($user, $was, $admin);
    }

    private function recordStatusChange(User $user, UserStatus $was, User $admin): void
    {
        $by = 'By '.$admin->displayName();

        match (true) {
            $user->status === UserStatus::Disabled => $this->events->record(LoginEventType::Disabled, $user, $by),
            $user->status === UserStatus::Locked => $this->events->record(LoginEventType::AccountLocked, $user, $by),
            $was === UserStatus::Locked => $this->events->record(LoginEventType::Unlocked, $user, $by),
            default => $this->events->record(LoginEventType::Enabled, $user, $by),
        };

        // A disabled or locked user is signed out everywhere straight away.
        if ($user->status !== UserStatus::Active) {
            $user->tokens()->delete();
            $this->challenges->closeAllFor($user);
        }
    }

    /**
     * @param  Collection<int, User>|null  $batch  other users changing in the same bulk action
     */
    private function assertRemovable(User $user, User $admin, string $verb, ?Collection $batch = null): void
    {
        if ($user->is($admin)) {
            $this->fail('ids', $verb === 'remove' ? 'You cannot remove yourself from the workspace.' : 'You cannot disable your own account.');
        }

        if ($user->isOwner() && $user->isActive()) {
            $leaving = $batch ? $batch->filter(fn (User $u) => $u->isOwner() && $u->isActive())->modelKeys() : [$user->id];
            if ($this->activeOwners()->whereKeyNot($leaving)->doesntExist()) {
                $this->fail('ids', "You cannot {$verb} the only active Owner of the workspace.");
            }
        }
    }

    private function isLastActiveOwner(User $user): bool
    {
        return $user->isOwner()
            && $user->isActive()
            && $this->activeOwners()->whereKeyNot($user->id)->doesntExist();
    }

    /** Active members of the current workspace holding its Owner role. */
    private function activeOwners()
    {
        $ownerId = Role::inCurrentWorkspace()->where('name', Role::OWNER)->value('id');

        return User::active()->inWorkspace($this->workspace())
            ->whereHas('roles', fn ($q) => $q->whereKey($ownerId));
    }

    /**
     * Roles that see every client keep no assignments (none are needed);
     * everyone else keeps the ones ticked.
     *
     * @param  array{client_ids?: list<int>|null}  $data
     * @return list<int>
     */
    private function clientIdsFor(Role $role, array $data): array
    {
        return ($role->isSystem() || $role->all_clients) ? [] : array_values(array_map('intval', $data['client_ids'] ?? []));
    }

    /**
     * Replace the user's client assignments in this workspace only:
     * BelongsToMany::sync() would also drop their other workspaces' clients.
     *
     * @param  list<int>  $clientIds  this workspace's (validated)
     */
    private function syncClients(User $user, array $clientIds): void
    {
        $current = $user->assignedClients()->pluck('clients.id')->all();
        $user->clients()->detach(array_values(array_diff($current, $clientIds)));
        $user->clients()->attach(array_values(array_diff($clientIds, $current)));
    }

    /**
     * Role and clients live in link tables the model events don't see, so
     * their changes are written to the change history here.
     *
     * @param  list<int>|null  $oldClientIds
     * @param  list<int>  $newClientIds
     */
    private function logAccess(User $user, ?Role $oldRole, ?array $oldClientIds, Role $newRole, array $newClientIds): void
    {
        $all = fn (?Role $role) => $role && ($role->isSystem() || $role->all_clients);

        $changes = [];
        if ($oldRole?->id !== $newRole->id) {
            $changes['role'] = ['old' => $oldRole?->name, 'new' => $newRole->name];
        }
        $old = $oldClientIds === null ? null : collect($oldClientIds)->map(fn ($id) => (int) $id)->sort()->values()->all();
        $new = collect($newClientIds)->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($old !== $new || $all($oldRole) !== $all($newRole)) {
            $before = $all($oldRole) ? 'All clients' : ($old === null ? null : $this->clientNames($old));
            $after = $all($newRole) ? 'All clients' : $this->clientNames($new);
            if ($before !== $after) {
                $changes['clients'] = ['old' => $before, 'new' => $after];
            }
        }

        if ($changes) {
            $user->logAuditChange('UPDATE', $changes, ['name' => $user->displayName()]);
        }
    }

    /** @param  list<int>  $ids */
    private function clientNames(array $ids): ?string
    {
        return Client::whereKey($ids)->orderBy('name')->pluck('name')->implode(', ') ?: null;
    }

    private function sendInvite(User $user, Role $role, User $admin, Workspace $workspace, bool $isNew): void
    {
        $hours = (int) config('indlela.auth.invite_ttl_hours');
        $vars = [
            'name' => $user->full_name,
            'added_by' => $admin->displayName(),
            'workspace' => $workspace->name,
            'role' => $role->name,
            'clients' => ($role->isSystem() || $role->all_clients) ? 'all clients' : ($this->clientNames($user->assignedClients()->pluck('clients.id')->all()) ?? 'no clients yet'),
        ];

        try {
            $mail = $isNew
                ? new TemplatedMail(EmailTemplateType::UserInvited, $vars + ['ttl' => "{$hours} hours"], $this->tokens->issueUrl($user->email, $hours * 60), $workspace->id)
                : new TemplatedMail(EmailTemplateType::AddedToWorkspace, $vars, rtrim((string) config('app.frontend_url'), '/').'/login', $workspace->id);
            Mail::to($user->email)->send($mail);
        } catch (Throwable $e) {
            // The user is in the workspace either way; an Owner can resend with "Reset password".
            report($e);
        }
    }

    private function role(int $id): Role
    {
        return Role::inCurrentWorkspace()->findOrFail($id);
    }

    private function workspace(): Workspace
    {
        return $this->current->get() ?? throw new \LogicException('User management needs a current workspace.');
    }

    private function fresh(User $user): User
    {
        $user = $user->fresh();
        $user->load(['roles', 'assignedClients']);

        return $user;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
