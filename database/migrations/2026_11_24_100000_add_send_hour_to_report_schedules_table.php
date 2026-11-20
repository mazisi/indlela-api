<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hour (0–23, Africa/Johannesburg) a scheduled report goes out on its
 * day of the month. reports:run-scheduled runs hourly and sends schedules
 * whose hour has come (a missed hour catches up later the same day).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_schedules', function (Blueprint $table) {
            $table->unsignedTinyInteger('send_hour')->default(8)->after('day_of_month');
        });
    }

    public function down(): void
    {
        Schema::table('report_schedules', function (Blueprint $table) {
            $table->dropColumn('send_hour');
        });
    }
};
