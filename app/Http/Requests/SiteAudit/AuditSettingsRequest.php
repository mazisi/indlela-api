<?php

namespace App\Http\Requests\SiteAudit;

use App\Models\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** PUT /sites/{site}/audit-settings: how often and how deep the site is audited. */
class AuditSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('manageSiteAudit', $this->route('site'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'audit_schedule' => ['required', Rule::in(Site::AUDIT_SCHEDULES)],
            'audit_page_limit' => ['required', 'integer', 'min:10', 'max:'.config('indlela.audit.max_page_limit')],
        ];
    }
}
