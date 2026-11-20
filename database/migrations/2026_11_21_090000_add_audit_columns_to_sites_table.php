<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A site's site-audit settings: how often it is crawled on schedule and how
 * many pages a crawl may fetch (capped at config indlela.audit.max_page_limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('audit_schedule', 10)->default('off')->after('gsc_sync_error'); // off | weekly | monthly
            $table->unsignedInteger('audit_page_limit')->default(500)->after('audit_schedule');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['audit_schedule', 'audit_page_limit']);
        });
    }
};
