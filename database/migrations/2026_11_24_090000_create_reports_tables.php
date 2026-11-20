<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * White-label reports.
 *
 * workspaces.brand_color: the accent of the workspace's reports (#RRGGBB;
 * null = the default navy).
 *
 * reports: one generated report of a site for a period. `data` is the frozen
 * snapshot (the PDF, share page and email all read it, so they always
 * match), `summary` its plain-English bullets. The PDF lives on the default
 * disk at `pdf_path`. `share_token` (40 random chars) opens the public page
 * until `share_expires_at` (null = no expiry); revoking clears it.
 *
 * report_schedules: one monthly schedule per site: on `day_of_month` the
 * previous calendar month's report is generated and emailed to `recipients`.
 * `last_run_at` makes the run idempotent per month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('brand_color', 7)->nullable();
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('compare', 10)->default('previous'); // previous | year | none
            $table->json('sections');
            $table->text('note')->nullable();
            $table->string('status', 12)->default('generating'); // generating | ready | failed
            $table->string('error', 1000)->nullable();
            $table->json('data')->nullable();
            $table->json('summary')->nullable();
            $table->string('pdf_path')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger', 10)->default('manual'); // manual | scheduled
            $table->string('share_token', 40)->nullable()->unique();
            $table->timestamp('share_expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('sent_to')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['site_id', 'period_end']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('day_of_month')->default(1);
            $table->json('recipients');
            $table->json('sections');
            $table->string('compare', 10)->default('previous');
            $table->text('note')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('last_report_id')->nullable()->constrained('reports')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'day_of_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
        Schema::dropIfExists('reports');

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('brand_color');
        });
    }
};
