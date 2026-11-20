<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Local map pack grid (DataForSEO Google Maps SERP, Live mode).
 *
 * map_grids: a saved setup for a site: keyword, the Google Maps listing
 * tracked (business_*), centre, size (3/5/7), spacing (km), device and
 * schedule. `business_key` (place id, else cid, else the lower-cased title)
 * makes the partial unique index simple. Soft deleted, audited.
 *
 * map_grid_scans: one run of a grid with a frozen copy of its settings,
 * counts and metrics. map_grid_points: one cell of a scan (row 0 = north,
 * col 0 = west); rank null = not in the top 20; `top` = the first 3 results
 * there, `results` = all of them (compact, for the competitors table).
 * The costs are what DataForSEO charged: platform-only.
 *
 * map_grid_searches: the business searches made when creating grids (a
 * ledger: the daily allowance and their cost).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_grids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('keyword', 100);
            $table->string('business_title', 255);
            $table->string('business_place_id', 255)->nullable();
            $table->string('business_cid', 40)->nullable();
            $table->string('business_address', 500)->nullable();
            $table->decimal('business_lat', 10, 7);
            $table->decimal('business_lng', 10, 7);
            $table->string('business_key', 255);
            $table->decimal('center_lat', 10, 7);
            $table->decimal('center_lng', 10, 7);
            $table->unsignedTinyInteger('size'); // 3 | 5 | 7
            $table->decimal('spacing_km', 4, 1);
            $table->string('device', 10)->default('mobile'); // mobile | desktop
            $table->string('schedule', 10)->default('none'); // none | weekly | monthly
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['site_id', 'is_active']);
            $table->index(['workspace_id', 'is_active']);
        });

        DB::statement('CREATE UNIQUE INDEX map_grids_unique ON map_grids (site_id, LOWER(keyword), business_key, center_lat, center_lng) WHERE deleted_at IS NULL');

        Schema::create('map_grid_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grid_id')->constrained('map_grids')->cascadeOnDelete();
            $table->string('status', 10)->default('queued'); // queued | running | done | failed
            $table->string('trigger', 10)->default('manual'); // manual | scheduled
            $table->date('scanned_on'); // the SAST day it was started: daily limits, months of spend
            $table->string('keyword', 100);
            $table->decimal('center_lat', 10, 7);
            $table->decimal('center_lng', 10, 7);
            $table->unsignedTinyInteger('size');
            $table->decimal('spacing_km', 4, 1);
            $table->string('device', 10);
            $table->string('business_title', 255);
            $table->string('business_place_id', 255)->nullable();
            $table->string('business_cid', 40)->nullable();
            $table->string('business_domain', 255)->nullable();
            $table->unsignedSmallInteger('points_total')->default(0);
            $table->unsignedSmallInteger('points_done')->default(0);
            $table->unsignedSmallInteger('points_found')->default(0);
            $table->decimal('arp', 5, 2)->nullable();
            $table->decimal('atrp', 5, 2)->nullable();
            $table->decimal('solv', 5, 1)->nullable();
            $table->decimal('found_pct', 5, 1)->nullable();
            $table->decimal('cost', 10, 6)->default(0);
            $table->string('error', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['grid_id', 'id']);
            $table->index(['workspace_id', 'scanned_on']);
            $table->index(['status', 'scanned_on']);
        });

        Schema::create('map_grid_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained('map_grid_scans')->cascadeOnDelete();
            $table->unsignedTinyInteger('row');
            $table->unsignedTinyInteger('col');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('status', 10)->default('pending'); // pending | done | failed
            $table->unsignedSmallInteger('rank')->nullable();
            $table->json('top')->nullable();
            $table->json('results')->nullable();
            $table->decimal('cost', 10, 6)->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('checked_at')->nullable();

            $table->unique(['scan_id', 'row', 'col']);
            $table->index(['scan_id', 'status']);
        });

        Schema::create('map_grid_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('query', 100);
            $table->date('searched_on');
            $table->string('status', 10)->default('pending'); // pending | done | failed
            $table->decimal('cost', 10, 6)->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'searched_on']);
            $table->index(['searched_on', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_grid_searches');
        Schema::dropIfExists('map_grid_points');
        Schema::dropIfExists('map_grid_scans');
        Schema::dropIfExists('map_grids');
    }
};
