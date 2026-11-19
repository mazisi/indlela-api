<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workspaces are the tenants: an agency or an SME. Users join them through
 * workspace_user (one user may belong to several) and act in
 * users.current_workspace_id. Roles are per workspace (spatie teams,
 * roles.workspace_id). logo_path is for white-label reports later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->string('logo_path')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        // Unique among live workspaces only, so a deleted workspace's slug can be reused.
        DB::statement('CREATE UNIQUE INDEX workspaces_slug_unique ON workspaces (slug) WHERE deleted_at IS NULL');

        Schema::create('workspace_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('current_workspace_id')->references('id')->on('workspaces')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_workspace_id']);
        });
        Schema::dropIfExists('workspace_user');
        Schema::dropIfExists('workspaces');
    }
};
