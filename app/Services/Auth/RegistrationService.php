<?php

namespace App\Services\Auth;

use App\Enums\LoginEventType;
use App\Enums\MfaChannel;
use App\Enums\UserStatus;
use App\Mail\EmailTemplateType;
use App\Mail\TemplatedMail;
use App\Models\User;
use App\Services\Workspaces\WorkspaceProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Workspace sign-up (replaces workforce's self-registration with approval):
 * a new account and a new workspace, with the account as its Owner. Joining
 * an existing workspace is by invitation only (UserManagementService).
 */
class RegistrationService
{
    public function __construct(
        private readonly WorkspaceProvisioner $provisioner,
        private readonly LoginEventLogger $events,
    ) {}

    /**
     * @param  array{full_name: string, surname: ?string, email: string, mobile?: ?string, password: string, workspace_name: string}  $data
     */
    public function register(array $data): User
    {
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'full_name' => $data['full_name'],
                'surname' => $data['surname'] ?? null,
                'email' => $data['email'],
                'mobile' => $data['mobile'] ?? null,
                'password' => $data['password'],
                'status' => UserStatus::Active,
                'mfa_channel' => MfaChannel::Email,
            ]);

            $workspace = $this->provisioner->create($data['workspace_name'], $user);
            $this->events->record(LoginEventType::Registered, $user, "Created workspace {$workspace->name}");

            return $user;
        });

        // The account exists either way; a failed welcome email is reported, not fatal.
        try {
            Mail::to($user->email)->send(new TemplatedMail(EmailTemplateType::WorkspaceCreated, [
                'name' => $user->full_name,
                'workspace' => $user->currentWorkspace->name,
            ], rtrim((string) config('app.frontend_url'), '/').'/login', $user->current_workspace_id));
        } catch (Throwable $e) {
            report($e);
        }

        return $user;
    }
}
