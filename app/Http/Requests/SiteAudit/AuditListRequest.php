<?php

namespace App\Http\Requests\SiteAudit;

use App\Models\SiteAudit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The shared parameters of an audit's lists (pages, issue URLs, comparison):
 * authorises viewing the audit, then validates paging and filters.
 */
class AuditListRequest extends FormRequest
{
    public const PAGE_SORTS = ['url', 'status_code', 'response_ms', 'inlinks_count', 'word_count', 'depth'];

    public function authorize(): bool
    {
        Gate::authorize('view', $this->audit());

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $sorts = array_merge(self::PAGE_SORTS, array_map(fn (string $s) => "-{$s}", self::PAGE_SORTS));

        return [
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(['2xx', '3xx', '4xx', '5xx'])],
            'indexable' => ['nullable', Rule::in(['0', '1'])],
            'sort' => ['nullable', Rule::in($sorts)],
            'filter' => ['nullable', Rule::in(['fixed', 'new'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ];
    }

    public function audit(): SiteAudit
    {
        return $this->route('audit');
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }

    /** Case-insensitive "contains" pattern for LIKE, or null. */
    public function like(): ?string
    {
        $search = trim((string) $this->validated('search'));

        return $search === '' ? null : '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($search)).'%';
    }
}
