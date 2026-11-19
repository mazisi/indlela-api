<?php

namespace Database\Factories;

use App\Enums\MfaChannel;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** Meets the password rules; reuse it in tests. */
    public const PASSWORD = 'Demo@2026';

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'full_name' => fake()->firstName(),
            'surname' => fake()->lastName(),
            'email' => fake()->unique()->userName().'@example.co.za',
            'mobile' => fake()->numerify('08########'),
            'status' => UserStatus::Active,
            'mfa_channel' => MfaChannel::Email,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make(self::PASSWORD),
            'remember_token' => Str::random(10),
        ];
    }

    public function withoutMfa(): static
    {
        return $this->state(['mfa_channel' => MfaChannel::Off]);
    }

    public function sms(): static
    {
        return $this->state(['mfa_channel' => MfaChannel::Sms]);
    }

    public function status(UserStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
