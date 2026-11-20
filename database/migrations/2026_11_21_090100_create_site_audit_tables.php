<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site audits: one row per crawl (site_audits) and what it found.
 *
 * site_audit_pages: every URL fetched (redirects and errors included);
 * site_audit_links: every link (and image) found on those pages, one row per
 * source page and target; site_audit_issues: what the analysis flagged, with
 * a fingerprint (sha1 of type + URL + a stable detail key) to compare against
 * the previous audit; site_audit_vitals: PageSpeed Insights results.
 *
 * URLs can be long: lookups use md5 url hashes. Detail rows go with their
 * audit (cascade). Pruning (site-audit:run-scheduled) deletes the detail rows
 * of all but the latest audits of a site and keeps their summaries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('queued'); // queued | crawling | analysing | done | failed | cancelled
            $table->string('trigger', 20)->default('manual'); // manual | scheduled
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('page_limit');
            $table->unsignedInteger('pages_crawled')->default(0);
            $table->unsignedInteger('pages_found')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error', 1000)->nullable();
            $table->unsignedTinyInteger('health_score')->nullable();
            $table->json('counts')->nullable();
            $table->foreignId('previous_audit_id')->nullable()->constrained('site_audits')->nullOnDelete();
            $table->json('comparison')->nullable();
            $table->string('vitals_status', 20)->default('pending'); // pending | done | unavailable
            $table->string('vitals_message', 500)->nullable();
            // What the crawl learnt about the site itself (robots.txt, sitemaps, home page).
            $table->json('crawl_meta')->nullable();
            $table->timestamp('pruned_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
            $table->index(['site_id', 'finished_at']);
            $table->index('workspace_id');
        });

        Schema::create('site_audit_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained('site_audits')->cascadeOnDelete();
            $table->text('url');
            $table->char('url_hash', 32);
            $table->unsignedSmallInteger('depth')->nullable(); // null: only in the sitemap
            $table->unsignedSmallInteger('status_code'); // 0 = no response (timeout, DNS, refused)
            $table->json('redirect_chain')->nullable(); // [{url, status_code}] hops, the final one last
            $table->text('final_url')->nullable();
            $table->unsignedSmallInteger('final_status_code')->nullable();
            $table->string('content_type', 150)->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->string('fetch_error', 500)->nullable();
            $table->text('title')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('h1')->nullable();
            $table->unsignedSmallInteger('h1_count')->default(0);
            $table->text('canonical')->nullable();
            $table->unsignedSmallInteger('canonical_count')->default(0);
            $table->string('meta_robots', 500)->nullable();
            $table->string('x_robots_tag', 500)->nullable();
            $table->boolean('is_indexable')->default(false);
            $table->boolean('in_sitemap')->default(false);
            $table->unsignedInteger('inlinks_count')->default(0);
            $table->unsignedInteger('outlinks_count')->default(0);
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedInteger('images_count')->default(0);
            $table->unsignedInteger('images_missing_alt')->default(0);
            $table->json('extras')->nullable(); // {missing_alt_srcs: [...]}

            $table->index(['audit_id', 'url_hash']);
        });

        Schema::create('site_audit_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained('site_audits')->cascadeOnDelete();
            $table->foreignId('from_page_id')->constrained('site_audit_pages')->cascadeOnDelete();
            $table->text('to_url');
            $table->char('to_url_hash', 32);
            $table->string('kind', 10)->default('link'); // link (<a href>) | image (<img src>)
            $table->boolean('is_internal');
            $table->unsignedSmallInteger('status_code')->nullable(); // null = not checked
            $table->boolean('nofollow')->default(false);

            $table->index(['audit_id', 'to_url_hash']);
            $table->index('from_page_id');
        });

        Schema::create('site_audit_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained('site_audits')->cascadeOnDelete();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('site_id');
            $table->string('type', 60);
            $table->string('severity', 10); // critical | warning | notice
            $table->foreignId('page_id')->nullable()->constrained('site_audit_pages')->cascadeOnDelete();
            $table->text('url')->nullable();
            $table->json('details')->nullable();
            $table->char('fingerprint', 40);
            $table->boolean('is_new')->default(false); // not in the previous audit

            $table->index(['audit_id', 'type']);
            $table->index(['audit_id', 'fingerprint']);
            $table->index('page_id');
            $table->index('workspace_id');
        });

        Schema::create('site_audit_vitals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained('site_audits')->cascadeOnDelete();
            $table->text('url');
            $table->string('strategy', 10); // mobile | desktop
            $table->unsignedTinyInteger('performance_score')->nullable();
            $table->json('lab')->nullable();
            $table->json('field')->nullable();
            $table->string('status', 10); // done | failed
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index('audit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_audit_vitals');
        Schema::dropIfExists('site_audit_issues');
        Schema::dropIfExists('site_audit_links');
        Schema::dropIfExists('site_audit_pages');
        Schema::dropIfExists('site_audits');
    }
};
