<?php

namespace App\Http\Requests\SearchConsole;

use App\Models\Site;
use App\Services\SearchConsole\ReportPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The shared parameters of the Search Console reports. `sort` is checked
 * against each report's columns by SearchConsoleReports.
 */
class SearchConsoleReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('viewSearchConsole', $this->site());

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
            'compare' => ['nullable', Rule::in(['previous', 'year', 'none'])],
            'search' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'string', 'regex:/^-?[a-z0-9_]+$/'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ];
    }

    public function site(): Site
    {
        return $this->route('site');
    }

    public function period(): ReportPeriod
    {
        return ReportPeriod::resolve($this->site(), $this->validated('start'), $this->validated('end'), $this->validated('compare') ?? 'previous');
    }

    /** @return array{search?: string, sort?: string, page?: int, per_page?: int} */
    public function options(): array
    {
        return array_filter([
            'search' => $this->validated('search'),
            'sort' => $this->validated('sort'),
            'page' => $this->validated('page') ? (int) $this->validated('page') : null,
            'per_page' => $this->validated('per_page') ? (int) $this->validated('per_page') : null,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
