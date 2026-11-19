<?php

namespace App\Http\Requests\Clients;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ClientRequest extends FormRequest
{
    /**
     * Runs before the rules, so the "already have a client by that name"
     * check can't be used without the right to add or change clients.
     */
    public function authorize(): bool
    {
        /** @var Client|null $client */
        $client = $this->route('client');
        $client ? Gate::authorize('update', $client) : Gate::authorize('create', Client::class);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $text = fn ($v) => is_string($v) ? (preg_replace('/\s+/', ' ', trim($v)) ?: null) : $v;

        $this->merge([
            'name' => $text($this->name),
            'contact_name' => $text($this->contact_name),
            'contact_email' => is_string($this->contact_email) ? (strtolower(trim($this->contact_email)) ?: null) : $this->contact_email,
            'notes' => is_string($this->notes) ? (trim($this->notes) ?: null) : $this->notes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Client|null $client */
        $client = $this->route('client');

        return [
            'name' => [
                'required', 'string', 'min:2', 'max:120',
                // Unique within the workspace (the Client scope); other workspaces may use the name.
                function (string $attribute, mixed $value, \Closure $fail) use ($client) {
                    $taken = Client::whereRaw('LOWER(name) = ?', [mb_strtolower((string) $value)])
                        ->when($client, fn ($q) => $q->whereKeyNot($client->getKey()))
                        ->exists();
                    if ($taken) {
                        $fail('There is already a client with this name.');
                    }
                },
            ],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
