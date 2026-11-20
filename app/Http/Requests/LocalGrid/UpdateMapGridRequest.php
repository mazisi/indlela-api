<?php

namespace App\Http\Requests\LocalGrid;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Change a grid's keyword, centre, size, spacing, device, schedule or status (the business is fixed). */
class UpdateMapGridRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('update', $this->route('grid'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'keyword' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            ...StoreMapGridRequest::settings('sometimes'),
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return StoreMapGridRequest::messageList();
    }
}
