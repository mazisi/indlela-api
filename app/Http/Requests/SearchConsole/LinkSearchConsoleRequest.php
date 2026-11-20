<?php

namespace App\Http\Requests\SearchConsole;

use App\Models\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class LinkSearchConsoleRequest extends FormRequest
{
    /** Before the rules: the "already linked" check must not leak other clients' sites. */
    public function authorize(): bool
    {
        Gate::authorize('manageSearchConsole', $this->site());

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'property' => [
                'required', 'string', 'max:255',
                // A domain property or a URL-prefix property, as Google names them.
                'regex:#^(sc-domain:[a-z0-9.-]+|https?://\S+/)$#i',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $taken = Site::query()->where('gsc_property', $value)->whereKeyNot($this->site()->id)->value('name');
                    if ($taken !== null) {
                        $fail("This property is already linked to {$taken}.");
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return ['property.regex' => 'Choose a Search Console property, e.g. sc-domain:example.co.za or https://www.example.co.za/.'];
    }

    public function site(): Site
    {
        return $this->route('site');
    }
}
