<?php

namespace App\Http\Requests\RankTracking;

use App\Models\RankKeyword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Change a keyword's schedule, tags, status or "key keyword" flag (any of them). */
class UpdateRankKeywordRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('update', $this->route('rankKeyword'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'frequency' => ['sometimes', 'required', Rule::in(RankKeyword::FREQUENCIES)],
            'weekly_day' => ['sometimes', 'required', 'integer', 'between:0,6'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:10'],
            'tags.*' => ['required', 'string', 'max:30'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'is_key' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
