<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rank tracking beta limits.
 *
 * workspaces.rank_keyword_limit: this workspace's own keyword limit (null =
 * config indlela.rank_tracking.workspace_keyword_limit), for when pricing
 * plans arrive.
 *
 * rank_checks.live_requests: "check now" requests made on this check's day
 * (a live check reuses the day's row, so a keyword checked twice counts
 * twice). Counted when requested, not when answered: a live call that
 * fails is still billed. Summed per workspace and day for the daily limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->unsignedInteger('rank_keyword_limit')->nullable();
        });

        Schema::table('rank_checks', function (Blueprint $table) {
            $table->unsignedSmallInteger('live_requests')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('rank_checks', function (Blueprint $table) {
            $table->dropColumn('live_requests');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('rank_keyword_limit');
        });
    }
};
