<?php

namespace App\Http\Requests\RankTracking;

use App\Models\RankKeyword;
use App\Services\RankTracking\RankKeywords;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add keywords to a site: every keyword x location x device (weekly unless
 * a frequency is given). Keywords are trimmed and de-duplicated (ignoring
 * case) before the 1–200 count applies.
 * A coordinate is "lat,lng"; the radius is added on save.
 */
class StoreRankKeywordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('manageRankTracking', $this->route('site'));

        return true;
    }

    protected function prepareForValidation(): void
    {
        $keywords = $this->input('keywords');
        if (is_array($keywords) && collect($keywords)->every(fn ($k) => $k === null || is_string($k))) {
            $this->merge(['keywords' => RankKeywords::cleanKeywords(array_map('strval', $keywords))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $config = config('indlela.rank_tracking');

        return [
            'keywords' => ['required', 'array', 'min:1', 'max:'.$config['max_keywords_per_request']],
            'keywords.*' => ['required', 'string', 'max:200'],
            'locations' => ['required', 'array', 'min:1', 'max:'.$config['max_locations_per_request']],
            'locations.*' => ['required', 'array:type,code,coordinate,name'],
            'locations.*.type' => ['required', Rule::in([RankKeyword::CODE, RankKeyword::COORDINATE])],
            'locations.*.code' => ['nullable', 'required_if:locations.*.type,code', 'integer', 'min:1'],
            'locations.*.coordinate' => ['nullable', 'required_if:locations.*.type,coordinate', 'string', 'regex:/^\s*-?\d{1,3}(\.\d+)?\s*,\s*-?\d{1,3}(\.\d+)?\s*$/'],
            'locations.*.name' => ['required', 'string', 'max:200'],
            'devices' => ['required', 'array', 'min:1', 'max:2'],
            'devices.*' => ['required', 'distinct', Rule::in(RankKeyword::DEVICES)],
            'frequency' => ['nullable', Rule::in(RankKeyword::FREQUENCIES)], // weekly when omitted
            'language_code' => ['nullable', 'string', 'regex:/^[a-z]{2,3}(-[a-z]{2,4})?$/i'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['required', 'string', 'max:30'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach ((array) $this->input('locations', []) as $i => $location) {
                if (($location['type'] ?? null) !== RankKeyword::COORDINATE || ! is_string($location['coordinate'] ?? null)) {
                    continue;
                }
                [$lat, $lng] = array_map('floatval', array_pad(explode(',', $location['coordinate']), 2, '0'));
                if (abs($lat) > 90 || abs($lng) > 180) {
                    $validator->errors()->add("locations.{$i}.coordinate", 'Enter a latitude between -90 and 90 and a longitude between -180 and 180.');
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'locations.*.coordinate.regex' => 'Enter the coordinate as "latitude,longitude", e.g. -26.1076,28.0567.',
        ];
    }
}
