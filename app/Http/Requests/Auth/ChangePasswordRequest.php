<?php

namespace App\Http\Requests\Auth;

use App\Rules\PasswordNotEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => [
                'required',
                'confirmed',
                'different:current_password',
                Password::defaults(),
                new PasswordNotEmail($this->user()->email),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.current_password' => 'Current password is not correct.',
            'password.different' => 'Choose a password you have not used before.',
            'password.confirmed' => 'The passwords do not match.',
        ];
    }
}
