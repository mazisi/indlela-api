<?php

namespace App\Http\Requests\LocalGrid;

use App\Models\MapGrid;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * A new grid for a site: keyword, the business (a business search
 * candidate), centre, size, spacing, device, schedule, and whether to scan
 * it at once.
 */
class StoreMapGridRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('createLocalGrid', $this->route('site'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'keyword' => ['required', 'string', 'min:2', 'max:100'],
            'business' => ['required', 'array:title,place_id,cid,address,lat,lng'],
            'business.title' => ['required', 'string', 'max:255'],
            'business.place_id' => ['nullable', 'string', 'max:255'],
            'business.cid' => ['nullable', 'regex:/^[0-9]{1,40}$/'],
            'business.address' => ['nullable', 'string', 'max:500'],
            'business.lat' => ['required', 'numeric', 'between:-90,90'],
            'business.lng' => ['required', 'numeric', 'between:-180,180'],
            ...self::settings(),
            'scan_now' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> the grid's settings (required here, `sometimes` on update) */
    public static function settings(string $presence = 'required'): array
    {
        return [
            'center_lat' => [$presence, 'numeric', 'between:-90,90'],
            'center_lng' => [$presence, 'numeric', 'between:-180,180'],
            'size' => [$presence, 'integer', Rule::in(MapGrid::SIZES)],
            'spacing_km' => [$presence, 'numeric', Rule::in(array_map(fn ($s) => (string) $s, MapGrid::SPACINGS))],
            'device' => [$presence === 'required' ? 'nullable' : $presence, Rule::in(MapGrid::DEVICES)],
            'schedule' => [$presence === 'required' ? 'nullable' : $presence, Rule::in(MapGrid::SCHEDULES)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return self::messageList();
    }

    /** @return array<string, string> */
    public static function messageList(): array
    {
        return [
            'size.in' => 'Choose a 3 x 3, 5 x 5 or 7 x 7 grid.',
            'spacing_km.in' => 'Choose a spacing of 0.5, 1, 2, 3 or 5 km.',
            'business.cid.regex' => 'The business\'s Google id is not valid.',
            'business.required' => 'Search for the business and pick it.',
        ];
    }
}
