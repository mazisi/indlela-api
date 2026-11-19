<?php

namespace App\Http\Requests\Clients;

use App\Models\Client;
use App\Models\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SiteRequest extends FormRequest
{
    /**
     * Runs before the rules, so someone without access to the client can't
     * use the "already tracked" check to probe its sites.
     */
    public function authorize(): bool
    {
        /** @var Site|null $site */
        $site = $this->route('site');

        if ($site) {
            Gate::authorize('update', $site);
        } else {
            // An unknown (or another workspace's) client_id leaves only the permission; the rules reject it.
            Gate::authorize('create', [Site::class, Client::find($this->integer('client_id'))]);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalised = is_string($this->url) ? Site::normalise($this->url) : null;

        $this->merge([
            'name' => is_string($this->name) ? (preg_replace('/\s+/', ' ', trim($this->name)) ?: null) : $this->name,
            'url' => $normalised['url'] ?? $this->url,
            'domain' => $normalised['domain'] ?? null,
        ]);
    }

    /**
     * A site stays with the client it was added to, so client_id is only
     * taken when adding. The name defaults to the domain.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Site|null $site */
        $site = $this->route('site');

        return [
            'client_id' => $site ? ['prohibited'] : ['required', 'integer', Rule::exists('clients', 'id')->where('workspace_id', getPermissionsTeamId())->whereNull('deleted_at')],
            'name' => ['nullable', 'string', 'max:120'],
            'url' => [
                'required', 'string', 'max:2048',
                function (string $attribute, mixed $value, \Closure $fail) use ($site) {
                    $domain = $this->input('domain');
                    if ($domain === null) {
                        $fail('Enter the website address, e.g. www.example.co.za.');

                        return;
                    }
                    // One site per domain in the workspace (the Site scope).
                    $taken = Site::where('domain', $domain)->when($site, fn ($q) => $q->whereKeyNot($site->getKey()))->exists();
                    if ($taken) {
                        $fail("{$domain} is already a site in this workspace.");
                    }
                },
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> what the site is saved with */
    public function siteData(): array
    {
        $data = $this->safe()->only(['client_id', 'url', 'is_active']);
        $data['domain'] = $this->input('domain');
        $data['name'] = $this->validated('name') ?? $data['domain'];

        return array_filter($data, fn ($v) => $v !== null);
    }
}
