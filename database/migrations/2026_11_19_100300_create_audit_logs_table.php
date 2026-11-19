<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The change history written by App\Traits\Auditable: structured field-level
 * diffs (old/new) for each page's History drawer. Ported from workforce-api;
 * company_id became workspace_id, so a workspace only ever reads its own
 * entries (null for records that belong to no workspace, e.g. a user's
 * account, whose membership changes are logged per workspace explicitly).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->timestamp('timestamp')->index();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name')->nullable();
            $table->string('action_type')->index();
            $table->string('table_name')->index();
            $table->string('record_id')->index();
            $table->json('field_changes')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_browser')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'table_name', 'timestamp']);
            $table->index(['table_name', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
