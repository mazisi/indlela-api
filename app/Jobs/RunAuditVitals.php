<?php

namespace App\Jobs;

use App\Models\SiteAudit;
use App\Models\Workspace;
use App\Services\SiteAudit\VitalsRunner;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * PageSpeed Insights for a finished audit (separate from the crawl so a
 * PageSpeed outage never holds up the issues). Any failure leaves the audit
 * done with vitals_status `unavailable`.
 */
class RunAuditVitals implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $auditId, public readonly int $workspaceId)
    {
        $this->onConnection(config('indlela.audit.queue_connection'));
        $this->onQueue('long');
    }

    public function handle(VitalsRunner $runner, CurrentWorkspace $current): void
    {
        $workspace = Workspace::find($this->workspaceId);
        if (! $workspace) {
            return;
        }

        $current->using($workspace, function () use ($runner) {
            $audit = SiteAudit::query()->find($this->auditId);
            if ($audit && $audit->status === SiteAudit::DONE && $audit->vitals_status === SiteAudit::VITALS_PENDING) {
                $runner->run($audit);
            }
        });
    }

    public function failed(?Throwable $e): void
    {
        SiteAudit::query()->withoutGlobalScopes()->whereKey($this->auditId)->where('vitals_status', SiteAudit::VITALS_PENDING)->update([
            'vitals_status' => SiteAudit::VITALS_UNAVAILABLE,
            'vitals_message' => 'Core Web Vitals could not be measured this time.',
            'updated_at' => now(),
        ]);
    }
}
