<?php

namespace App\Enums;

/**
 * Entries in Settings › Login history (ported from workforce-api; the
 * registration-approval entries became Registered = workspace sign-up).
 */
enum LoginEventType: string
{
    case SignedIn = 'signed_in';
    case SignedOut = 'signed_out';
    case UnknownUser = 'unknown_user';
    case WrongPassword = 'wrong_password';
    case AccountLocked = 'account_locked';
    case BlockedStatus = 'blocked_status';
    case PinSent = 'pin_sent';
    case WrongPin = 'wrong_pin';
    case PinExpired = 'pin_expired';
    /** Signed up, creating a workspace. */
    case Registered = 'registered';
    case ResetRequested = 'reset_requested';
    case PasswordReset = 'password_reset';
    case PasswordChanged = 'password_changed';
    case Unlocked = 'unlocked';
    /** Added to a workspace by one of its users (new account or existing). */
    case Created = 'created';
    case Disabled = 'disabled';
    case Enabled = 'enabled';
    case SwitchedWorkspace = 'switched_workspace';

    public function label(): string
    {
        return match ($this) {
            self::SignedIn => 'Signed in',
            self::SignedOut => 'Signed out',
            self::UnknownUser => 'Unknown user',
            self::WrongPassword => 'Wrong password',
            self::AccountLocked => 'Account locked',
            self::BlockedStatus => 'Sign-in refused',
            self::PinSent => 'Password correct',
            self::WrongPin => 'Wrong one-time PIN',
            self::PinExpired => 'One-time PIN expired',
            self::Registered => 'Signed up',
            self::ResetRequested => 'Reset requested',
            self::PasswordReset => 'Password reset',
            self::PasswordChanged => 'Password changed',
            self::Unlocked => 'Unlocked',
            self::Created => 'Added to a workspace',
            self::Disabled => 'Account disabled',
            self::Enabled => 'Account enabled',
            self::SwitchedWorkspace => 'Switched workspace',
        };
    }
}
