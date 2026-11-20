<?php

namespace App\Services\RankTracking;

use App\Mail\CompetitorAlertMail;
use App\Models\CompetitorAlert;
use App\Models\Site;
use App\Models\SiteCompetitorSetting;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The daily competitor alert email: per site with recipients, one digest of
 * its alerts not emailed yet and at most rank_tracking.competitor_alert_days
 * old, then those alerts are marked emailed. Sites without recipients (or
 * deleted or inactive ones) send nothing; their alerts stay listed in the
 * app. Spans workspaces; each site's email is built in its own workspace.
 */
class CompetitorAlertDigest
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** @return array{sites: int, alerts: int} emails' sites and the alerts they carried */
    public function send(): array
    {
        $since = now()->subDays((int) config('indlela.rank_tracking.competitor_alert_days'));
        $siteIds = CompetitorAlert::query()->withoutGlobalScope('workspace')
            ->whereNull('emailed_at')->where('created_at', '>=', $since)
            ->distinct()->pluck('site_id');

        $sent = ['sites' => 0, 'alerts' => 0];
        foreach ($siteIds as $siteId) {
            $site = Site::query()->withoutGlobalScope('workspace')->where('is_active', true)->find($siteId);
            $workspace = $site ? Workspace::find($site->workspace_id) : null;
            if (! $site || ! $workspace) {
                continue;
            }

            try {
                $count = $this->current->using($workspace, fn () => $this->sendFor($site, $since));
            } catch (Throwable $e) {
                // One site's mail failing doesn't stop the others; its alerts go tomorrow.
                report($e);
                Log::warning('Competitor alerts: the digest could not be sent.', ['site_id' => $siteId, 'error' => $e->getMessage()]);

                continue;
            }
            if ($count > 0) {
                $sent['sites']++;
                $sent['alerts'] += $count;
            }
        }

        return $sent;
    }

    private function sendFor(Site $site, CarbonInterface $since): int
    {
        $recipients = SiteCompetitorSetting::query()->where('site_id', $site->id)->value('alert_recipients');
        $recipients = is_string($recipients) ? json_decode($recipients, true) : $recipients;
        if (! $recipients) {
            return 0;
        }

        $alerts = CompetitorAlert::query()->where('site_id', $site->id)
            ->whereNull('emailed_at')->where('created_at', '>=', $since)
            // Not for a competitor or keyword removed since.
            ->whereHas('competitor', fn ($q) => $q->whereNull('site_competitors.deleted_at'))
            ->whereHas('keyword', fn ($q) => $q->whereNull('rank_keywords.deleted_at'))
            ->with(['keyword', 'competitor'])
            ->orderByDesc('checked_on')->orderByDesc('id')
            ->get();
        if ($alerts->isEmpty()) {
            return 0;
        }

        $site->loadMissing(['client', 'workspace']);
        foreach ($recipients as $email) {
            Mail::to($email)->send(new CompetitorAlertMail($site, $alerts, $site->workspace_id));
        }
        CompetitorAlert::query()->whereKey($alerts->pluck('id'))->update(['emailed_at' => now()]);

        return $alerts->count();
    }
}
