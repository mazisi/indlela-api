<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A client's websites: what Search Console, the site audit and rank tracking
 * attach to later. `domain` is the normalised host (lower case, no scheme,
 * no www.), `url` the home page as entered (scheme + host + path).
 * workspace_id is denormalised from the client so every query can be scoped
 * to the workspace without a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url', 2048);
            $table->string('domain');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['workspace_id', 'client_id']);
        });
        // A domain is tracked once per workspace among live sites.
        DB::statement('CREATE UNIQUE INDEX sites_workspace_domain_unique ON sites (workspace_id, domain) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
