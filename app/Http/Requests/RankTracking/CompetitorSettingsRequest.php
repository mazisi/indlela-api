<?php

namespace App\Http\Requests\RankTracking;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** PUT /sites/{site}/competitor-settings: who gets the competitor alert emails (none = no email). */
class CompetitorSettingsRequest extends FormRequest
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
            'alert_recipients' => ['present', 'array', 'max:'.config('indlela.rank_tracking.max_alert_recipients')],
            'alert_recipients.*' => ['required', 'email:rfc', 'max:255'],
        ];
    }
}
