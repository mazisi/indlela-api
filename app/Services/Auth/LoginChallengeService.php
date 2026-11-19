<?php

namespace App\Services\Auth;

use App\Enums\LoginEventType;
use App\Enums\MfaChannel;
use App\Exceptions\AuthFlowException;
use App\Mail\EmailTemplateType;
use App\Mail\TemplatedMail;
use App\Models\LoginChallenge;
use App\Models\User;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The sign-in one-time PIN, by SMS or email (ported from workforce-api).
 *
 * After a correct password the browser gets an opaque challenge token, never a
 * session. It trades token + PIN for a session in verify(). Rules: 6 digits, valid 5 minutes, 3 wrong tries closes the challenge and
 * the user must sign in again; "Send a new code" re-arms it.
 */
class LoginChallengeService
{
    /** Resends re-arm the PIN, but a challenge never outlives this. */
    private const MAX_CHALLENGE_AGE_MINUTES = 30;

    public function __construct(
        private readonly SmsSender $sms,
        private readonly LoginEventLogger $events,
    ) {}

    /**
     * Open a challenge for the user and deliver its PIN.
     *
     * @return array{challenge: LoginChallenge, token: string}
     */
    public function issue(User $user): array
    {
        $token = Str::random(64);
        $code = $this->newCode();

        $challenge = DB::transaction(function () use ($user, $token, $code) {
            // Only the newest challenge is live; an older tab can't be used.
            LoginChallenge::where('user_id', $user->id)->open()->update(['consumed_at' => now()]);

            return LoginChallenge::create([
                'user_id' => $user->id,
                'token_hash' => $this->hash($token),
                'code_hash' => Hash::make($code),
                'channel' => $user->mfa_channel,
                'sent_at' => now(),
                'expires_at' => now()->addMinutes($this->ttl()),
                'ip_address' => request()->ip(),
            ]);
        });

        $this->deliver($user, $code);

        return ['challenge' => $challenge, 'token' => $token];
    }

    /**
     * Check the PIN. Returns the user on success and closes the challenge.
     */
    public function verify(string $token, string $code): User
    {
        $challenge = $this->openChallenge($token);
        $user = $challenge->user;

        if ($challenge->isExpired()) {
            $this->events->record(LoginEventType::PinExpired, $user);

            throw new AuthFlowException('The code has expired. Send a new code.', 'pin_expired', 'code');
        }

        if (! Hash::check($code, $challenge->code_hash)) {
            $max = (int) config('indlela.auth.pin_max_attempts');
            $challenge->increment('attempts');
            $this->events->record(LoginEventType::WrongPin, $user, "Attempt {$challenge->attempts} of {$max}");

            if ($challenge->attempts >= $max) {
                $challenge->update(['consumed_at' => now()]);

                throw new AuthFlowException('Too many wrong codes. Sign in again.', 'challenge_closed', 'code');
            }

            throw new AuthFlowException('That code is not correct.', 'invalid_pin', 'code', extra: [
                'attempts_left' => $max - $challenge->attempts,
            ]);
        }

        $challenge->update(['consumed_at' => now()]);

        return $user;
    }

    /**
     * "Send a new code": fresh PIN and expiry, wrong-try counter reset.
     */
    public function resend(string $token): LoginChallenge
    {
        $challenge = $this->openChallenge($token);

        $cooldown = (int) config('indlela.auth.pin_resend_cooldown_seconds');
        $wait = $cooldown - (int) $challenge->sent_at->diffInSeconds(now());
        if ($wait > 0) {
            throw new AuthFlowException("Wait {$wait} seconds before asking for another code.", 'resend_too_soon', 'code', 429, [
                'retry_after' => $wait,
            ]);
        }

        if ($challenge->resends >= (int) config('indlela.auth.pin_max_resends')) {
            $challenge->update(['consumed_at' => now()]);

            throw new AuthFlowException('Too many new codes requested. Sign in again.', 'challenge_closed', 'code');
        }

        $code = $this->newCode();
        $challenge->update([
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'resends' => $challenge->resends + 1,
            'sent_at' => now(),
            'expires_at' => now()->addMinutes($this->ttl()),
        ]);

        $this->deliver($challenge->user, $code);
        $this->events->record(LoginEventType::PinSent, $challenge->user, 'New one-time PIN sent by '.$challenge->channel->label());

        return $challenge;
    }

    /** Close every open challenge for a user (password reset, lock, unlock). */
    public function closeAllFor(User $user): void
    {
        LoginChallenge::where('user_id', $user->id)->open()->update(['consumed_at' => now()]);
    }

    /** Where the PIN went, masked for display: "082•••0100" or "n•••@beneckegroup.com". */
    public function maskedDestination(User $user): string
    {
        if ($user->mfa_channel === MfaChannel::Sms) {
            $digits = preg_replace('/\D/', '', (string) $user->mobile) ?? '';

            return substr($digits, 0, 3).'•••'.substr($digits, -4);
        }

        return preg_replace('/^(.).*(@.*)$/', '$1•••$2', $user->email) ?? $user->email;
    }

    private function openChallenge(string $token): LoginChallenge
    {
        $challenge = LoginChallenge::with('user')
            ->where('token_hash', $this->hash($token))
            ->open()
            ->first();

        // A deleted/deactivated user mid-challenge is treated like a stale token.
        if (! $challenge
            || ! $challenge->user?->isActive()
            || $challenge->created_at->lt(now()->subMinutes(self::MAX_CHALLENGE_AGE_MINUTES))) {
            throw new AuthFlowException('This sign-in has expired. Sign in again.', 'challenge_closed', 'code');
        }

        return $challenge;
    }

    private function deliver(User $user, string $code): void
    {
        $ttl = $this->ttl().' minutes';

        if ($user->mfa_channel === MfaChannel::Sms) {
            $this->sms->send(
                (string) $user->mobile,
                config('app.name')." sign-in PIN: {$code}. It expires in {$ttl}. Never share this code.",
            );

            return;
        }

        Mail::to($user->email)->send(new TemplatedMail(EmailTemplateType::LoginPin, [
            'name' => $user->full_name,
            'code' => $code,
            'ttl' => $ttl,
        ], workspaceId: $user->current_workspace_id));
    }

    private function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function ttl(): int
    {
        return (int) config('indlela.auth.pin_ttl_minutes');
    }
}
