<?php

namespace App\Http\Requests\Auth;

use App\Enums\MfaChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('mobile')) {
            $this->merge(['mobile' => preg_replace('/\s+/', '', (string) $this->input('mobile'))]);
        }
    }

    public function rules(): array
    {
        // Only an Owner (of the current workspace) may switch the one-time PIN off.
        $channels = $this->user()->isOwner()
            ? MfaChannel::cases()
            : [MfaChannel::Email, MfaChannel::Sms];

        return [
            'mobile' => ['sometimes', 'required', 'regex:/^0\d{9}$/'],
            'mfa_channel' => ['sometimes', 'required', Rule::enum(MfaChannel::class)->only($channels)],
        ];
    }

    public function messages(): array
    {
        return [
            'mobile.regex' => 'Enter a 10-digit cell number, e.g. 082 555 0100.',
            'mfa_channel.enum' => 'Choose email or SMS for your one-time PIN.',
        ];
    }

    /**
     * SMS needs a number to send to.
     */
    public function after(): array
    {
        return [function ($validator) {
            $channel = $this->input('mfa_channel');
            $mobile = $this->input('mobile', $this->user()->mobile);
            if ($channel === MfaChannel::Sms->value && ! $mobile) {
                $validator->errors()->add('mobile', 'Add a cell number to get your PIN by SMS.');
            }
        }];
    }
}
