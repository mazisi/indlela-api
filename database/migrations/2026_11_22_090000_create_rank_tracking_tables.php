<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rank tracking (DataForSEO SERP API).
 *
 * rank_keywords: what is tracked, one row per keyword x location x device of
 * a site; `location_key` ("code:2710" or "coord:lat,lng,radius") makes the
 * partial unique index simple. Soft deleted: a deleted keyword keeps its
 * history and can be added again.
 *
 * rank_checks: one SERP check of a keyword on a day (unique per keyword and
 * day: a live "check now" replaces that day's row). Position null = not in
 * the top 100. The costs summed here are what DataForSEO charged.
 *
 * rank_locations: DataForSEO's location list (global, not tenant data),
 * downloaded on first use and refreshed monthly, searched locally.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('keyword', 200);
            $table->string('location_type', 10); // code | coordinate
            $table->unsignedInteger('location_code')->nullable();
            $table->string('location_coordinate', 60)->nullable(); // "lat,lng,radius"
            $table->string('location_name', 200);
            $table->string('location_key', 80);
            $table->string('language_code', 10)->default('en');
            $table->string('device', 10); // desktop | mobile
            $table->string('frequency', 10)->default('daily'); // daily | weekly
            $table->unsignedTinyInteger('weekly_day')->default(0); // 0 = Sunday … 6 = Saturday
            $table->json('tags')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['site_id', 'is_active']);
            $table->index(['workspace_id', 'is_active']);
        });

        DB::statement('CREATE UNIQUE INDEX rank_keywords_unique ON rank_keywords (site_id, LOWER(keyword), location_key, device) WHERE deleted_at IS NULL');

        Schema::create('rank_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('keyword_id')->constrained('rank_keywords')->cascadeOnDelete();
            $table->date('checked_on');
            $table->string('mode', 10)->default('standard'); // standard | live
            $table->string('status', 10)->default('pending'); // pending | done | failed
            $table->string('task_id', 64)->nullable();
            $table->unsignedSmallInteger('position')->nullable();
            $table->text('url')->nullable();
            $table->json('matching_urls')->nullable(); // [{url, position}]
            $table->json('serp_features')->nullable();
            $table->unsignedBigInteger('result_count')->nullable();
            $table->decimal('cost', 10, 6)->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['keyword_id', 'checked_on']);
            $table->index('task_id');
            $table->index(['site_id', 'checked_on']);
            $table->index(['status', 'posted_at']);
            $table->index(['workspace_id', 'checked_on']);
        });

        Schema::create('rank_locations', function (Blueprint $table) {
            $table->unsignedInteger('code')->primary();
            $table->string('name', 255);
            $table->string('type', 60)->nullable();
            $table->string('country_iso', 2)->nullable();
            $table->unsignedInteger('parent_code')->nullable();
            $table->timestamp('fetched_at');

            $table->index('country_iso');
        });

        DB::statement('CREATE INDEX rank_locations_name_lower ON rank_locations (LOWER(name) text_pattern_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_locations');
        Schema::dropIfExists('rank_checks');
        Schema::dropIfExists('rank_keywords');
    }
};
