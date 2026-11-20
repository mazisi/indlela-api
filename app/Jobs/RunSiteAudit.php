<?php

namespace App\Jobs;

use App\Exceptions\AuditCancelled;
use App\Models\SiteAudit;
use App\Models\Workspace;
use App\Services\SiteAudit\AuditAnalyser;
use App\Services\SiteAudit\SiteCrawler;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Crawl and analyse one audit, then queue its PageSpeed run (RunAuditVitals).
 *
 * Carries its workspace and runs inside it. Unique per site (one crawl of a
 * site at a time). A retry starts the crawl again from scratch; after the
 * last attempt (or a timeout) the audit is marked failed with the reason.
 * A cancelled audit stops between batches and keeps what it crawled.
 * Runs on the redis-long connection (Horizon's supervisor-long).
 */
class RunSiteAudit implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [120];

    // Below the redis-long connection's retry_after (1900 s).
    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 7200;

    public function __construct(public readonly int $auditId, public readonly int $workspaceId)
    {
        $this->onConnection(config('indlela.audit.queue_connection'));
        $this->onQueue('long');
    }

    /**
     * Unique per audit, not per site: a per-site lock silently dropped a new
     * audit's job while an older audit's job still held it (e.g. waiting to
     * retry), leaving the new audit "queued" forever. One crawl per site is
     * enforced where audits start (409 audit_running), and a cancelled
     * audit's job returns at once.
     */
    public function uniqueId(): string
    {
        return 'audit-'.$this->auditId;
    }

    public function handle(SiteCrawler $crawler, AuditAnalyser $analyser, CurrentWorkspace $current): void
    {
        $workspace = Workspace::find($this->workspaceId);
        if (! $workspace) {
            return;
        }

        $current->using($workspace, function () use ($crawler, $analyser) {
            $audit = SiteAudit::query()->with('site')->find($this->auditId);
            if (! $audit || ! $audit->isRunning() || ! $audit->site) {
                return; // cancelled, or gone
            }

            // A retry starts over.
            $audit->issues()->delete();
            $audit->vitals()->delete();
            $audit->links()->delete();
            $audit->pages()->delete();
            $audit->forceFill(['status' => SiteAudit::CRAWLING, 'started_at' => now(), 'pages_crawled' => 0, 'pages_found' => 0, 'error' => null])->save();

            try {
                $crawler->crawl($audit);
            } catch (AuditCancelled) {
                return;
            }

            if (! $this->stillRunning($audit)) {
                return;
            }
            $audit->forceFill(['status' => SiteAudit::ANALYSING])->save();
            $analyser->analyse($audit);

            if (! $this->stillRunning($audit)) {
                return;
            }
            $audit->forceFill(['status' => SiteAudit::DONE, 'finished_at' => now(), 'vitals_status' => SiteAudit::VITALS_PENDING])->save();

            RunAuditVitals::dispatch($audit->id, $audit->workspace_id);
        });
    }

    private function stillRunning(SiteAudit $audit): bool
    {
        return SiteAudit::query()->whereKey($audit->id)->value('status') !== SiteAudit::CANCELLED;
    }

    public function failed(?Throwable $e): void
    {
        SiteAudit::query()->withoutGlobalScopes()->whereKey($this->auditId)->whereIn('status', SiteAudit::RUNNING)->update([
            'status' => SiteAudit::FAILED,
            'finished_at' => now(),
            'error' => mb_substr($e?->getMessage() ?: 'The crawl stopped unexpectedly.', 0, 1000),
            'updated_at' => now(),
        ]);
    }
}
