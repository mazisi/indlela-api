<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from workforce-api: the lockout counter and the one-time PIN
 * channel. Workforce's self-registration request columns are gone (sign-up
 * creates a workspace instead), and users belong to workspaces through
 * workspace_user; current_workspace_id (constrained in the workspaces
 * migration) is the one their requests act in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('surname')->nullable();
            $table->string('email')->unique();
            $table->string('mobile', 20)->nullable();
            // App\Enums\UserStatus
            $table->string('status', 20)->default('active')->index();
            // App\Enums\MfaChannel
            $table->string('mfa_channel', 10)->default('email');
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedBigInteger('current_workspace_id')->nullable()->index();

            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('slug')->unique();
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('expires_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
