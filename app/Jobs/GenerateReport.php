<?php

namespace App\Jobs;

use App\Models\Report;
use App\Models\Workspace;
use App\Services\Reports\Reports;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Generate one report (snapshot, summary, PDF), then email it to $sendTo if
 * a send was asked for when it was created (or by its schedule).
 *
 * Carries its workspace and runs inside it. Unique per report (never per
 * site or schedule: a broad key would silently drop other reports' jobs).
 * Not retried: Reports::generate marks the report failed with a plain reason
 * and the user generates it again. Runs on the redis-long connection
 * (Horizon's supervisor-long): dompdf takes a few seconds per report.
 */
class GenerateReport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Below the redis-long connection's retry_after (1900 s).
    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    /** @param  list<string>  $sendTo */
    public function __construct(public readonly int $reportId, public readonly int $workspaceId, public readonly array $sendTo = [])
    {
        $this->onConnection(config('indlela.reports.queue_connection'));
        $this->onQueue('long');
    }

    public function uniqueId(): string
    {
        return 'report-'.$this->reportId;
    }

    public function handle(Reports $reports, CurrentWorkspace $current): void
    {
        $workspace = Workspace::find($this->workspaceId);
        if (! $workspace) {
            return;
        }

        $current->using($workspace, function () use ($reports) {
            $report = Report::query()->with(['site', 'client'])->find($this->reportId);
            if (! $report || $report->status !== Report::GENERATING || ! $report->site) {
                return; // deleted, or already generated
            }

            $reports->generate($report);

            if ($this->sendTo !== [] && $report->isReady()) {
                try {
                    $reports->send($report, $this->sendTo);
                } catch (Throwable $e) {
                    report($e);
                    $report->forceFill(['error' => 'The report is ready, but the email could not be sent. Send it again from the report.'])->save();
                }
            }
        });
    }

    public function failed(?Throwable $e): void
    {
        Report::query()->withoutGlobalScopes()->whereKey($this->reportId)->where('status', Report::GENERATING)->update([
            'status' => Report::FAILED,
            'error' => 'The report could not be generated. Generate it again in a few minutes.',
            'updated_at' => now(),
        ]);
    }
}
