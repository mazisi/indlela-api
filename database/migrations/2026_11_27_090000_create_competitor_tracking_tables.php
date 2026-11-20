<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Competitor tracking, inside rank tracking. No extra DataForSEO calls: it
 * reads the SERPs the rank checks already fetch.
 *
 * rank_checks.organic_domains: the organic results' hosts by position
 * (index i = position i + 1, bare host, null where no organic item), written
 * when a check is parsed. Checks from before this stay null.
 *
 * rank_keywords.is_key: a "key keyword"; competitor alerts fire only for these.
 *
 * site_competitors: up to rank_tracking.max_competitors_per_site active
 * domains per site (bare host, unique per site among non-deleted ones).
 * Soft deleted, audited.
 *
 * rank_competitor_positions: a competitor's best position in one check
 * (null = not in the top 100), written when the check finishes and
 * backfilled when a competitor is added.
 *
 * competitor_alerts: a competitor moved ahead of the site on a key keyword
 * (once per check and competitor); emailed in a daily digest.
 *
 * site_competitor_settings: one row per site, the alert emails' recipients.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rank_checks', function (Blueprint $table) {
            $table->json('organic_domains')->nullable();
        });

        Schema::table('rank_keywords', function (Blueprint $table) {
            $table->boolean('is_key')->default(false);
        });

        Schema::create('site_competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 255);
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['site_id', 'is_active']);
            $table->index('workspace_id');
        });

        DB::statement('CREATE UNIQUE INDEX site_competitors_unique ON site_competitors (site_id, domain) WHERE deleted_at IS NULL');

        Schema::create('rank_competitor_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competitor_id')->constrained('site_competitors')->cascadeOnDelete();
            $table->foreignId('keyword_id')->constrained('rank_keywords')->cascadeOnDelete();
            $table->foreignId('check_id')->constrained('rank_checks')->cascadeOnDelete();
            $table->date('checked_on');
            $table->unsignedSmallInteger('position')->nullable();

            $table->unique(['check_id', 'competitor_id']);
            $table->index(['keyword_id', 'checked_on']);
            $table->index(['competitor_id', 'checked_on']);
        });

        Schema::create('competitor_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('keyword_id')->constrained('rank_keywords')->cascadeOnDelete();
            $table->foreignId('competitor_id')->constrained('site_competitors')->cascadeOnDelete();
            $table->foreignId('check_id')->constrained('rank_checks')->cascadeOnDelete();
            $table->date('checked_on');
            $table->unsignedSmallInteger('our_position')->nullable();
            $table->unsignedSmallInteger('their_position')->nullable();
            $table->unsignedSmallInteger('previous_our_position')->nullable();
            $table->unsignedSmallInteger('previous_their_position')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['check_id', 'competitor_id']);
            $table->index(['site_id', 'checked_on']);
            $table->index(['emailed_at', 'created_at']);
        });

        Schema::create('site_competitor_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('alert_recipients')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_competitor_settings');
        Schema::dropIfExists('competitor_alerts');
        Schema::dropIfExists('rank_competitor_positions');
        Schema::dropIfExists('site_competitors');

        Schema::table('rank_keywords', function (Blueprint $table) {
            $table->dropColumn('is_key');
        });

        Schema::table('rank_checks', function (Blueprint $table) {
            $table->dropColumn('organic_domains');
        });
    }
};
