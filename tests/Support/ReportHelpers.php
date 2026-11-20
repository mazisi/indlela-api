<?php

namespace Tests\Support;

use App\Models\Client;
use App\Models\Report;
use App\Models\Site;
use App\Models\SiteAudit;
use App\Models\SiteAuditIssue;
use App\Models\User;
use App\Services\Reports\Reports;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * A site with a month of everything for the report tests (run at
 * 2026-11-20, so "last month" is October 2026, compared with September):
 *  - Search Console: September 100 clicks / 1,000 impressions a day, October
 *    120 / 1,500 (clicks +24%, impressions +55%), data through 17 November.
 *  - Rank tracking: k1 15 → 5 (entered the top 10), k2 8 → 2 (entered the
 *    top 3), k3 4 → 12 (left the top 10), k4 first checked in October 30 →
 *    25, k5 never checked, k6 inactive. November checks must be ignored.
 *  - Site audit: 1 Oct (health 70), 20 Oct (78, 2 critical, 4 fixed, 1 new),
 *    5 Nov (95, after the period: ignored).
 */
trait ReportHelpers
{
    use RankTrackingHelpers, SearchConsoleHelpers, SiteAuditHelpers;

    private int $reportSites = 0;

    /** The first is karoo.co.za of "Karoo Coffee"; later ones get their own domain and client. */
    protected function reportSite(?Client $client = null): Site
    {
        $n = $this->reportSites++;
        $domain = $n === 0 ? 'karoo.co.za' : "karoo{$n}.co.za";

        return $this->linkedSite($client ?? $this->makeClient(state: ['name' => $n === 0 ? 'Karoo Coffee' : "Karoo Coffee {$n}"]), "sc-domain:{$domain}", [
            'url' => "https://www.{$domain}", 'domain' => $domain, 'name' => $domain, 'gsc_data_through' => '2026-11-17',
        ]);
    }

    protected function seedSearchConsole(Site $site): void
    {
        $totals = [];
        foreach (CarbonPeriod::create('2026-09-01', '2026-09-30') as $d) {
            $totals[] = [$d->toDateString(), 100, 1000, 10.0];
        }
        foreach (CarbonPeriod::create('2026-10-01', '2026-10-31') as $d) {
            $totals[] = [$d->toDateString(), 120, 1500, 8.0];
        }
        $this->addTotals($site, $totals);
        $this->addRows($site, [
            ['2026-10-05', 'coffee beans', 'https://www.karoo.co.za/beans', 50, 500, 6.0],
            ['2026-10-06', 'espresso machine', 'https://www.karoo.co.za/', 30, 300, 3.0],
            ['2026-10-07', 'buy coffee online', 'https://www.karoo.co.za/shop', 2, 400, 8.0],
            ['2026-09-07', 'coffee beans', 'https://www.karoo.co.za/beans', 40, 450, 7.0],
        ]);
    }

    protected function seedRankings(Site $site): void
    {
        $k1 = $this->makeKeyword($site, 'k1', ['serp_features' => null]);
        $this->addCheck($k1, '2026-09-28', 15);
        $this->addCheck($k1, '2026-10-30', 5, ['serp_features' => ['featured_snippet' => ['present' => true, 'ours' => true]]]);
        $this->addCheck($k1, '2026-11-10', 1);
        $k2 = $this->makeKeyword($site, 'k2');
        $this->addCheck($k2, '2026-09-28', 8);
        $this->addCheck($k2, '2026-10-30', 2);
        $k3 = $this->makeKeyword($site, 'k3');
        $this->addCheck($k3, '2026-09-28', 4);
        $this->addCheck($k3, '2026-10-30', 12);
        $k4 = $this->makeKeyword($site, 'k4');
        $this->addCheck($k4, '2026-10-10', 30);
        $this->addCheck($k4, '2026-10-25', 25);
        $this->makeKeyword($site, 'k5');
        $k6 = $this->makeKeyword($site, 'k6', ['is_active' => false]);
        $this->addCheck($k6, '2026-10-30', 1);
    }

    protected function seedAudits(Site $site): SiteAudit
    {
        $first = $this->makeAudit($site, ['finished_at' => '2026-10-01 03:00:00', 'health_score' => 70]);
        $latest = $this->makeAudit($site, [
            'finished_at' => '2026-10-20 03:00:00', 'health_score' => 78, 'previous_audit_id' => $first->id, 'pages_crawled' => 40,
            'counts' => ['critical' => 2, 'warning' => 1, 'notice' => 0, 'total' => 3],
            'comparison' => ['fixed' => 4, 'new' => 1, 'persisting' => 2, 'previous_audit_id' => $first->id],
        ]);
        foreach ([['page_4xx', 'critical'], ['page_4xx', 'critical'], ['missing_meta_description', 'warning']] as $i => [$type, $severity]) {
            SiteAuditIssue::query()->create([
                'audit_id' => $latest->id, 'workspace_id' => $site->workspace_id, 'site_id' => $site->id, 'type' => $type,
                'severity' => $severity, 'url' => "https://www.karoo.co.za/{$i}", 'fingerprint' => sha1("{$type}{$i}"),
            ]);
        }
        $this->makeAudit($site, ['finished_at' => '2026-11-05 03:00:00', 'health_score' => 95, 'previous_audit_id' => $latest->id]);

        return $latest;
    }

    /** All three sources. */
    protected function fullSite(?Client $client = null): Site
    {
        $site = $this->reportSite($client);
        $this->seedSearchConsole($site);
        $this->seedRankings($site);
        $this->seedAudits($site);

        return $site;
    }

    /** A report generated synchronously in the site's workspace (the queue runs sync in tests). */
    protected function makeReport(Site $site, array $input = [], ?User $user = null, array $sendTo = []): Report
    {
        return $this->inWorkspace($site->workspace, fn () => app(Reports::class)->create($site, [
            'period' => 'last_month', 'sections' => Report::SECTIONS, 'compare' => 'previous', ...$input,
        ], $user, Report::MANUAL, $sendTo, CarbonImmutable::today()));
    }
}
