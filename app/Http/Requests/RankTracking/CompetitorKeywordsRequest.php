<?php

namespace App\Http\Requests\RankTracking;

use App\Services\RankTracking\CompetitorReports;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** The head-to-head list: authorises viewing the site's rank tracking, then the filters. */
class CompetitorKeywordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('viewRankTracking', $this->route('site'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:200'],
            'only' => ['nullable', Rule::in(CompetitorReports::ONLY)],
            'sort' => ['nullable', Rule::in([...CompetitorReports::SORTS, ...array_map(fn ($s) => "-{$s}", CompetitorReports::SORTS)])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }
}
