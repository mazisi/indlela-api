<?php

namespace App\Http\Requests\Settings;

use App\Support\CurrentWorkspace;
use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update_settings_roles');
    }

    /**
     * The role's full set of permissions. Only catalogue permissions a role
     * may hold; Users and access, Roles, Login history and Workspace stay
     * with Owners, and the platform pages exist only in the platform workspace.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array', 'max:500'],
            'permissions.*' => ['string', 'distinct', Rule::in(Permissions::grantableIn(app(CurrentWorkspace::class)->get()))],
        ];
    }

    public function messages(): array
    {
        return ['permissions.*.in' => ':input cannot be given to a role.'];
    }

    /** @return list<string> with view added wherever another action is held */
    public function permissionNames(): array
    {
        return Permissions::withView($this->validated('permissions'));
    }
}
