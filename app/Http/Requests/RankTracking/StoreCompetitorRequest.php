<?php

namespace App\Http\Requests\RankTracking;

use App\Services\RankTracking\Competitors;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Add a competitor to a site: a domain or any URL on it (kept as the bare host), and an optional name. */
class StoreCompetitorRequest extends FormRequest
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
            'domain' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) {
                if (Competitors::normalise((string) $value) === null) {
                    $fail('Enter a website address, e.g. rival.co.za.');
                }
            }],
            'name' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** @return array{domain: string, name: ?string} */
    public function competitor(): array
    {
        return ['domain' => (string) Competitors::normalise($this->validated('domain')), 'name' => $this->validated('name')];
    }
}
