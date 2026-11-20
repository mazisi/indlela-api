<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Search Console performance data, per site and day.
 *
 * gsc_daily_totals: dimension [date], the accurate totals (query rows leave
 * out anonymised queries, so they never add up to these).
 *
 * gsc_query_page_daily: dimensions [date, query, page]. Query and page can be
 * long, so uniqueness and lookups use md5 hashes of them. The unique index
 * (site_id, date, query_hash, page_hash) also serves the site + date range
 * scans every report starts with. This is the big table (millions of rows for
 * a busy site): it has no workspace foreign key or index of its own; rows go
 * with their site (cascade), which goes with its workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsc_daily_totals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('clicks');
            $table->unsignedInteger('impressions');
            $table->double('ctr');
            $table->double('position');

            $table->unique(['site_id', 'date']);
            $table->index('workspace_id');
        });

        Schema::create('gsc_query_page_daily', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->text('query');
            $table->text('page');
            $table->char('query_hash', 32);
            $table->char('page_hash', 32);
            $table->unsignedInteger('clicks');
            $table->unsignedInteger('impressions');
            $table->double('ctr');
            $table->double('position');

            $table->unique(['site_id', 'date', 'query_hash', 'page_hash'], 'gsc_qpd_site_date_query_page_unique');
            $table->index(['site_id', 'query_hash'], 'gsc_qpd_site_query_index');
            $table->index(['site_id', 'page_hash'], 'gsc_qpd_site_page_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_query_page_daily');
        Schema::dropIfExists('gsc_daily_totals');
    }
};
