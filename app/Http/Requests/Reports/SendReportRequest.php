<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** POST /reports/{report}/send. */
class SendReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('create', $this->route('report'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'recipients' => ['required', 'array', 'min:1', 'max:'.config('indlela.reports.max_recipients')],
            'recipients.*' => ['required', 'email:rfc', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
