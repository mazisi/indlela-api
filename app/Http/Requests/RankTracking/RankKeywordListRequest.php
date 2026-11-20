<?php

namespace App\Http\Requests\RankTracking;

use App\Models\RankKeyword;
use App\Models\Site;
use App\Services\RankTracking\RankReports;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** A site's keyword list: authorises viewing the site's rank tracking, then the filters. */
class RankKeywordListRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('viewRankTracking', $this->site());

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:200'],
            'device' => ['nullable', Rule::in(RankKeyword::DEVICES)],
            'location' => ['nullable', 'string', 'max:200'],
            'tag' => ['nullable', 'string', 'max:30'],
            'bucket' => ['nullable', Rule::in(RankReports::BUCKETS)],
            'is_key' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in([...RankReports::SORTS, ...array_map(fn ($s) => "-{$s}", RankReports::SORTS)])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ];
    }

    public function site(): Site
    {
        return $this->route('site');
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }
}
