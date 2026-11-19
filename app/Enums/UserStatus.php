<?php

namespace App\Enums;

/**
 * Account lifecycle. Only ACTIVE may sign in; the login flow is an allowlist,
 * so any status added later is locked out by default. (Workforce's Pending and
 * Rejected went with its approval flow: Indlela sign-up creates a workspace.)
 *
 * The status belongs to the account, across every workspace it is in.
 */
enum UserStatus: string
{
    case Active = 'active';
    /** Too many wrong passwords. Cleared by a password reset or an Owner's unlock. */
    case Locked = 'locked';
    /** Deliberately suspended by an Owner. */
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Locked => 'Locked',
            self::Disabled => 'Disabled',
        };
    }

    /** The message a user sees when a correct password meets this status. */
    public function loginMessage(): string
    {
        return match ($this) {
            self::Disabled => 'This account is disabled. Contact the Owner of your workspace.',
            self::Locked => 'This account is locked after too many wrong passwords. Reset your password or ask the Owner of your workspace to unlock it.',
            self::Active => '',
        };
    }
}
