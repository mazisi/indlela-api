<?php

namespace App\Http\Requests\RankTracking;

use App\Models\RankKeyword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** The keyword list's bulk bar. */
class BulkRankKeywordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('manageRankTracking', $this->route('site'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['activate', 'deactivate', 'delete', 'set_frequency', 'mark_key', 'unmark_key'])],
            'ids' => ['required', 'array', 'min:1', 'max:'.config('indlela.rank_tracking.max_bulk_ids')],
            'ids.*' => ['required', 'integer', 'distinct'],
            'frequency' => ['nullable', 'required_if:action,set_frequency', Rule::in(RankKeyword::FREQUENCIES)],
        ];
    }
}
