<?php

namespace App\Enums;

/**
 * Where the sign-in one-time PIN is delivered. OFF skips the second factor
 * and may only be chosen by a workspace Owner.
 */
enum MfaChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Off = 'off';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Sms => 'SMS',
            self::Off => 'Off',
        };
    }
}
