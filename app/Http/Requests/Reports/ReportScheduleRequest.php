<?php

namespace App\Http\Requests\Reports;

use App\Models\Report;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** PUT /sites/{site}/report-schedule: the site's monthly report. */
class ReportScheduleRequest extends FormRequest
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
            'is_active' => ['required', 'boolean'],
            'day_of_month' => ['required', 'integer', 'min:1', 'max:28'],
            // Hour of the day, Africa/Johannesburg; kept (or 08:00 on a new schedule) when omitted.
            'send_hour' => ['sometimes', 'integer', 'min:0', 'max:23'],
            'recipients' => ['required', 'array', 'min:1', 'max:'.config('indlela.reports.max_recipients')],
            'recipients.*' => ['required', 'email:rfc', 'max:255'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['string', 'distinct', Rule::in(Report::SECTIONS)],
            'compare' => ['required', Rule::in(Report::COMPARES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
