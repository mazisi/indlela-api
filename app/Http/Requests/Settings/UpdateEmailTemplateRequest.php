<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update_settings_email_templates');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:20000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'subject.required' => 'Enter a subject.',
            'body.required' => 'Enter the message.',
        ];
    }
}
