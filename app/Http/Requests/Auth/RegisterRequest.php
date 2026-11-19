<?php

namespace App\Http\Requests\Auth;

use App\Rules\PasswordNotEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Workspace sign-up: the person and the workspace (agency or business) they create.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = fn ($v) => is_string($v) ? preg_replace('/\s+/', ' ', trim($v)) : $v;

        $this->merge([
            'name' => $clean($this->input('name')),
            'email' => is_string($this->input('email')) ? strtolower(trim($this->input('email'))) : $this->input('email'),
            'mobile' => is_string($this->input('mobile')) ? (preg_replace('/\s+/', '', $this->input('mobile')) ?: null) : $this->input('mobile'),
            'workspace_name' => $clean($this->input('workspace_name')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'mobile' => ['nullable', 'regex:/^0\d{9}$/'],
            'workspace_name' => ['required', 'string', 'min:2', 'max:100'],
            'password' => ['required', 'confirmed', Password::defaults(), new PasswordNotEmail($this->input('email'))],
        ];
    }

    /**
     * The account's full_name and surname from the one name field: the last
     * word is the surname ("Thandi Mokoena"), a single word has none.
     *
     * @return array{full_name: string, surname: ?string, email: string, mobile: ?string, password: string, workspace_name: string}
     */
    public function account(): array
    {
        $parts = explode(' ', $this->validated('name'));
        $surname = count($parts) > 1 ? array_pop($parts) : null;

        return [
            'full_name' => implode(' ', $parts),
            'surname' => $surname,
            'email' => $this->validated('email'),
            'mobile' => $this->validated('mobile'),
            'password' => $this->validated('password'),
            'workspace_name' => $this->validated('workspace_name'),
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter your name.',
            'email.unique' => 'There is already an account for this email. Sign in or reset your password; to join a workspace, ask its Owner to add you.',
            'mobile.regex' => 'Enter a 10-digit cell number, e.g. 082 555 0100.',
            'workspace_name.required' => 'Name your workspace: your agency or business.',
            'password.confirmed' => 'The passwords do not match.',
        ];
    }
}
