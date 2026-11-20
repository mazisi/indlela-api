<?php

namespace App\Http\Requests\RankTracking;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Rename a competitor (empty = its domain), or pause / resume it. */
class UpdateCompetitorRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('update', $this->route('competitor'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
