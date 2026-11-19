<?php

namespace App\Http\Requests\Auth;

use App\Rules\PasswordNotEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::defaults(), new PasswordNotEmail($this->input('email'))],
        ];
    }

    public function messages(): array
    {
        return ['password.confirmed' => 'The passwords do not match.'];
    }
}
