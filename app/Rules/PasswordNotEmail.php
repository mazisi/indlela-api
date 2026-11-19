<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * "Not the same as your email" from the prototype's password rules: the
 * password may not equal the part of the email before the @.
 */
class PasswordNotEmail implements ValidationRule
{
    public function __construct(private readonly ?string $email) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $local = strtolower((string) strstr((string) $this->email, '@', true));

        if ($local !== '' && strtolower((string) $value) === $local) {
            $fail('The password may not be the same as your email name.');
        }
    }
}
