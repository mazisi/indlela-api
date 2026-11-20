<?php

namespace App\Http\Requests\Reports;

use App\Models\Report;
use App\Services\Reports\ReportPeriods;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** POST /sites/{site}/reports: generate a report (and optionally email it when ready). */
class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('createReports', $this->route('site'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'period' => ['required', Rule::in(ReportPeriods::PRESETS)],
            'start' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'end' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'compare' => ['nullable', Rule::in(Report::COMPARES)],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['string', 'distinct', Rule::in(Report::SECTIONS)],
            'note' => ['nullable', 'string', 'max:2000'],
            'title' => ['nullable', 'string', 'max:200'],
            'send_to' => ['nullable', 'array', 'max:'.config('indlela.reports.max_recipients')],
            'send_to.*' => ['required', 'email:rfc', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['sections.required' => 'Choose at least one section.', 'sections.min' => 'Choose at least one section.'];
    }
}
