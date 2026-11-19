<?php

namespace App\Services\Auth;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Single-use password tokens in the framework password_reset_tokens table
 * (ported from workforce-api). Only a hash is stored; the plaintext
 * travels only in the emailed link.
 */
class PasswordResetTokenService
{
    /**
     * Replace any live token for the email with a fresh one and return the
     * frontend URL that carries it.
     */
    public function issueUrl(string $email, int $ttlMinutes): string
    {
        $email = strtolower($email);
        $now = Carbon::now();

        $token = Str::random(64);

        DB::transaction(function () use ($email, $token, $now, $ttlMinutes) {
            // One live token per email — drop any previous one before issuing.
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            DB::table('password_reset_tokens')->insert([
                'email' => $email,
                'token' => Hash::make($token),
                'created_at' => $now,
                'expires_at' => $now->copy()->addMinutes($ttlMinutes),
            ]);
        });

        return rtrim((string) config('app.frontend_url'), '/')
            .'/reset-password?token='.$token.'&email='.urlencode($email);
    }

    /**
     * Whether the token is the live, unexpired one for this email: 'ok',
     * 'expired' (the right token, too late) or 'invalid' (wrong, used, or
     * replaced by a newer link). 'expired' needs the real token, so it says
     * nothing about which emails exist. An expired token is deleted on sight.
     *
     * @return 'ok'|'expired'|'invalid'
     */
    public function check(string $email, string $token): string
    {
        $email = strtolower($email);
        $row = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (! $row || ! Hash::check($token, $row->token)) {
            return 'invalid';
        }

        if (Carbon::parse($row->expires_at)->isPast()) {
            $this->forget($email);

            return 'expired';
        }

        return 'ok';
    }

    public function forget(string $email): void
    {
        DB::table('password_reset_tokens')->where('email', strtolower($email))->delete();
    }
}
