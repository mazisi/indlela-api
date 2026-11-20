<?php

namespace Database\Seeders;

use App\Models\Report;
use App\Models\Site;
use App\Models\Workspace;
use App\Services\Reports\Reports;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Seeder;

/**
 * A demo white-label report, so the Reports page and the share page have
 * something real. Not part of DatabaseSeeder: run it on purpose (after
 * GscDemoSeeder, AuditDemoSeeder and RankDemoSeeder),
 *
 *     php artisan db:seed --class=ReportDemoSeeder
 *
 * In the first workspace (in its own brand colour, or the default navy) it
 * generates last month's report of the demo site demo.indlela.local
 * synchronously (a real PDF on the default disk) and shares it for 90 days.
 * Each run adds a new report; nothing is deleted.
 */
class ReportDemoSeeder extends Seeder
{
    private const DOMAIN = 'demo.indlela.local';

    public function run(Reports $reports, CurrentWorkspace $current): void
    {
        $workspace = Workspace::query()->orderBy('id')->first();
        if (! $workspace) {
            $this->command?->warn('No workspace yet: run the default seeders first.');

            return;
        }

        $report = $current->using($workspace, function () use ($reports) {
            $site = Site::query()->where('domain', self::DOMAIN)->first();
            if (! $site) {
                return null;
            }

            // Generated in this process whatever QUEUE_CONNECTION says.
            config(['indlela.reports.queue_connection' => 'sync']);
            $report = $reports->create($site, [
                'period' => 'last_month',
                'compare' => 'previous',
                'sections' => Report::SECTIONS,
                'note' => "A strong month for the service pages. Next month we'll focus on the Durban location pages, which are close to page one.",
            ], null);

            return $report->isReady() ? $reports->share($report, 90) : $report;
        });

        if (! $report) {
            $this->command?->warn('No demo site ('.self::DOMAIN.'): run GscDemoSeeder first.');

            return;
        }
        if (! $report->isReady()) {
            $this->command?->error("Report {$report->id} failed: {$report->error}");

            return;
        }

        $this->command?->info("Report {$report->id} ({$report->title}) ready: {$report->pdf_path}");
        $this->command?->info('Share link: '.$report->shareUrl());
    }
}
