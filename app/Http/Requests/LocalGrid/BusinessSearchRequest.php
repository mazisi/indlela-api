<?php

namespace App\Http\Requests\LocalGrid;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Find the Google Maps listing for a new grid: a name, optionally near a point. */
class BusinessSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('createLocalGrid', $this->route('site'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'min:2', 'max:100'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
        ];
    }
}
