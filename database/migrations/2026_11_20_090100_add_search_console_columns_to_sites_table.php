<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A site's Search Console property and its sync state. gsc_data_through is
 * the latest date held in gsc_daily_totals (reports default to the 28 days
 * ending there).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('gsc_property')->nullable()->after('is_active');
            $table->string('gsc_sync_status', 20)->default('idle')->after('gsc_property'); // idle | queued | syncing | done | failed
            $table->timestamp('gsc_synced_at')->nullable()->after('gsc_sync_status');
            $table->date('gsc_data_through')->nullable()->after('gsc_synced_at');
            $table->string('gsc_sync_error', 500)->nullable()->after('gsc_data_through');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['gsc_property', 'gsc_sync_status', 'gsc_synced_at', 'gsc_data_through', 'gsc_sync_error']);
        });
    }
};
