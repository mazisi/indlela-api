<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings › Login history. Append-only; `email` is kept even when no user
 * matched. Sign-ins belong to the account, not a workspace: a workspace's
 * history shows its members' events (unknown-email attempts are kept for
 * operators but shown to no workspace).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->index();
            // App\Enums\LoginEventType
            $table->string('event', 30)->index();
            $table->string('note')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
    }
};
