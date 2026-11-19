<?php

namespace Database\Seeders;

use App\Enums\MfaChannel;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Workspaces\WorkspaceProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The first account and its workspace (it is the Owner), from SEED_ADMIN_*
 * and SEED_WORKSPACE_NAME in .env. Without a password a random one is
 * generated and printed once. Idempotent: an existing account is left alone.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower((string) env('SEED_ADMIN_EMAIL', 'admin@indlela.local'));

        if (User::whereEmail($email)->exists()) {
            return;
        }

        $password = env('SEED_ADMIN_PASSWORD') ?: Str::password(24);

        $user = User::create([
            'full_name' => env('SEED_ADMIN_NAME', 'Indlela'),
            'surname' => env('SEED_ADMIN_SURNAME', 'Admin'),
            'email' => $email,
            'mobile' => env('SEED_ADMIN_MOBILE') ?: null,
            'password' => $password,
            'status' => UserStatus::Active,
            'mfa_channel' => MfaChannel::Email,
        ]);
        $user->forceFill(['email_verified_at' => now()])->saveQuietly();

        $workspace = app(WorkspaceProvisioner::class)->create((string) env('SEED_WORKSPACE_NAME', 'Indlela Demo'), $user);

        // A generated password is shown once, here; otherwise use "Forgot password?".
        if (! env('SEED_ADMIN_PASSWORD')) {
            $this->command?->warn("Created {$email} with generated password: {$password}");
        }
        $this->command?->info("{$email} is the Owner of workspace \"{$workspace->name}\".");
    }
}
