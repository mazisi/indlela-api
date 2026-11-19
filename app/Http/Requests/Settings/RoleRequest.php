<?php

namespace App\Http\Requests\Settings;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

class RoleRequest extends FormRequest
{
    /** Before the rules, so the name-taken check can't be used without the permission. */
    public function authorize(): bool
    {
        return $this->user()->can($this->route('role') ? 'update_settings_roles' : 'create_settings_roles');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->name) ? preg_replace('/\s+/', ' ', trim($this->name)) : $this->name,
            'description' => is_string($this->description) ? (trim($this->description) ?: null) : $this->description,
        ]);
    }

    /**
     * Names are unique within the workspace (another workspace may have its own "Analyst").
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $role = $this->route('role');

        return [
            'name' => [
                'required', 'string', 'min:2', 'max:60',
                function (string $attribute, mixed $value, \Closure $fail) use ($role) {
                    $taken = Role::inCurrentWorkspace()
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $value)])
                        ->when($role, fn ($q) => $q->whereKeyNot($role->getKey()))
                        ->exists();
                    if ($taken) {
                        $fail('There is already a role with this name.');
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:160'],
            // Members see every client, not only those assigned to them.
            'all_clients' => ['sometimes', 'boolean'],
        ];
    }
}
