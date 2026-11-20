<?php

namespace App\Services\SiteAudit;

use App\Exceptions\SiteAuditException;
use App\Jobs\RunSiteAudit;
use App\Models\Site;
use App\Models\SiteAudit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Starting, cancelling and scheduling audits, a site's audit settings, and
 * pruning old audits' detail rows.
 */
class SiteAudits
{
    /** Queue an audit of $site: 409 audit_running while one is queued or in progress. */
    public function start(Site $site, ?User $user, string $trigger = SiteAudit::MANUAL): SiteAudit
    {
        // One start per site at a time, so two clicks can't queue two audits.
        $audit = Cache::lock("site-audit-start:{$site->id}", 10)->block(5, function () use ($site, $user, $trigger) {
            if (SiteAudit::query()->withoutGlobalScopes()->where('site_id', $site->id)->running()->exists()) {
                throw SiteAuditException::running();
            }

            return SiteAudit::query()->create([
                'workspace_id' => $site->workspace_id,
                'site_id' => $site->id,
                'trigger' => $trigger,
                'triggered_by' => $user?->id,
                'page_limit' => self::pageLimit($site),
            ]);
        });

        RunSiteAudit::dispatch($audit->id, $audit->workspace_id);

        // Fresh: on the sync queue driver the job has already run.
        return $audit->refresh();
    }

    /** Stop a queued or running audit; the crawler notices between batches. */
    public function cancel(SiteAudit $audit): SiteAudit
    {
        $updated = SiteAudit::query()->withoutGlobalScopes()->whereKey($audit->id)->running()
            ->update(['status' => SiteAudit::CANCELLED, 'finished_at' => now(), 'updated_at' => now()]);
        if ($updated === 0) {
            throw SiteAuditException::notRunning();
        }

        return $audit->refresh();
    }

    /** @param  array{audit_schedule: string, audit_page_limit: int}  $settings */
    public function updateSettings(Site $site, array $settings): Site
    {
        $site->forceFill($settings)->save();

        return $site;
    }

    public static function pageLimit(Site $site): int
    {
        return max(1, min((int) ($site->audit_page_limit ?: config('indlela.audit.default_page_limit')), (int) config('indlela.audit.max_page_limit')));
    }

    /**
     * Active sites (every workspace) whose schedule is due: weekly 7 / monthly
     * 30 days after the day their last audit finished (done or failed; a
     * manual audit counts), or never audited, and none running now.
     *
     * @return Collection<int, Site>
     */
    public function dueSites(): Collection
    {
        $days = config('indlela.audit.schedule_days');

        return Site::query()->withoutGlobalScope('workspace')->active()
            ->whereIn('audit_schedule', array_keys($days))
            ->whereDoesntHave('audits', fn ($q) => $q->withoutGlobalScopes()->running())
            ->with(['audits' => fn ($q) => $q->withoutGlobalScopes()->whereIn('status', [SiteAudit::DONE, SiteAudit::FAILED])->latest('finished_at')->limit(1)])
            ->get()
            ->filter(function (Site $site) use ($days) {
                $last = $site->audits->first()?->finished_at;

                // Finished on day D, weekly: due from D + 7 whatever the time of day.
                return $last === null || $last->lt(today()->subDays($days[$site->audit_schedule] - 1));
            })
            ->values();
    }

    /**
     * Delete the pages, links, issues and vitals of every audit but the latest
     * `audit.keep_audits` of each site; their summaries stay for the history.
     *
     * @return int audits pruned
     */
    public function prune(): int
    {
        $keep = max(1, (int) config('indlela.audit.keep_audits'));
        $ids = DB::table('site_audits')->whereNull('pruned_at')->whereNotIn('status', SiteAudit::RUNNING)
            ->whereIn('id', DB::table('site_audits as a')->select('a.id')
                ->whereRaw('(SELECT COUNT(*) FROM site_audits b WHERE b.site_id = a.site_id AND b.id > a.id) >= ?', [$keep]))
            ->pluck('id');

        foreach ($ids->chunk(50) as $chunk) {
            DB::transaction(function () use ($chunk) {
                DB::table('site_audit_issues')->whereIn('audit_id', $chunk)->delete();
                DB::table('site_audit_vitals')->whereIn('audit_id', $chunk)->delete();
                DB::table('site_audit_links')->whereIn('audit_id', $chunk)->delete();
                DB::table('site_audit_pages')->whereIn('audit_id', $chunk)->delete();
                DB::table('site_audits')->whereIn('id', $chunk)->update(['pruned_at' => now()]);
            });
        }

        return $ids->count();
    }
}
