<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pending second factor: issued once the password is right, consumed when
 * the one-time PIN is verified. Only hashes are stored: neither the challenge
 * token the browser holds nor the PIN can be read back from the DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('code_hash');
            $table->string('channel', 10);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('resends')->default(0);
            $table->timestamp('sent_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_challenges');
    }
};
