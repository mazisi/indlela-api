<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable content for the code-owned transactional email types
 * (App\Mail\EmailTemplateType), one row per type per workspace: an agency
 * words (and later brands) the emails its people and client logins get.
 * Missing rows fall back to the code defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('subject');
            $table->text('body');
            $table->timestamps();

            $table->unique(['workspace_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
