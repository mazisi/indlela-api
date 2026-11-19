<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Enable/disable is an update; delete needs delete.
        return $this->user()->can(($this->input('action') === 'delete' ? 'delete' : 'update').'_settings_users');
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
        ];
    }
}
