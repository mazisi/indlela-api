<?php

namespace App\Http\Requests\Users;

use App\Enums\MfaChannel;
use App\Models\Role;
use App\Models\User;
use App\Services\Users\UserManagementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add a user to the current workspace (POST) or edit one (PUT, which also
 * sets the status). Roles and clients must be this workspace's.
 */
class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->isMethod('post') ? 'create_settings_users' : 'update_settings_users');
    }

    protected function prepareForValidation(): void
    {
        $clean = fn ($v) => is_string($v) ? preg_replace('/\s+/', ' ', trim($v)) : $v;

        $this->merge([
            'full_name' => $clean($this->full_name),
            'surname' => $clean($this->surname),
            'email' => is_string($this->email) ? strtolower(trim($this->email)) : $this->email,
            'mobile' => is_string($this->mobile) ? (preg_replace('/\s+/', '', $this->mobile) ?: null) : $this->mobile,
        ]);
    }

    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->route('user');
        $workspaceId = getPermissionsTeamId();

        return [
            'full_name' => ['required', 'string', 'max:100'],
            'surname' => ['required', 'string', 'max:100'],
            // Adding an existing account's email adds that account (the service checks it isn't already a member).
            'email' => ['required', 'email', 'max:255', ...($user ? [Rule::unique('users', 'email')->ignore($user->id)] : [])],
            'mobile' => [Rule::requiredIf($this->input('mfa_channel') === MfaChannel::Sms->value), 'nullable', 'regex:/^0\d{9}$/'],
            'mfa_channel' => ['required', Rule::enum(MfaChannel::class)],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at')],
            // Roles that see every client need none ticked.
            'client_ids' => [Rule::requiredIf(! $this->allClientsRole()), 'array'],
            'client_ids.*' => ['integer', 'distinct', Rule::exists('clients', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at')],
            'status' => [Rule::requiredIf($user !== null), Rule::in(array_map(fn ($s) => $s->value, UserManagementService::EDITABLE_STATUSES))],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Another user already has this email address.',
            'mobile.regex' => 'Enter a 10-digit cell number starting with 0.',
            'mobile.required' => 'Add a cell number to send the PIN by SMS.',
            'client_ids.required' => 'Give access to at least one client.',
        ];
    }

    private function allClientsRole(): bool
    {
        $role = Role::inCurrentWorkspace()->find($this->integer('role_id'));

        return (bool) ($role?->isSystem() || $role?->all_clients);
    }
}
