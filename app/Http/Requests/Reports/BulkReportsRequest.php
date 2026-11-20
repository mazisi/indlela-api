<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** The site's report list bulk bar: delete, or revoke share links. Each report is authorised again (delete_reports / create_reports). */
class BulkReportsRequest extends FormRequest
{
    public const ACTIONS = ['delete', 'unshare'];

    public function authorize(): bool
    {
        Gate::authorize('viewReports', $this->route('site'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(self::ACTIONS)],
            'ids' => ['required', 'array', 'min:1', 'max:'.config('indlela.reports.max_bulk_ids')],
            'ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
