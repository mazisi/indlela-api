<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles belong to a workspace (spatie teams: roles.workspace_id), carry the
 * one-line description shown in Settings › Roles, and are soft-deleted (as in
 * workforce-api) so a removed role never orphans the audit trail.
 *
 * `all_clients`: members see every client of the workspace (Owner, Manager);
 * otherwise only the clients assigned to them in client_user (Analyst, Client).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('description')->nullable()->after('name');
            $table->boolean('all_clients')->default(false)->after('description');
            $table->softDeletes();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
            $table->dropColumn(['description', 'all_clients']);
            $table->dropSoftDeletes();
        });
    }
};
