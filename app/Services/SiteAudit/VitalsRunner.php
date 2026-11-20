<?php

namespace App\Services\SiteAudit;

use App\Models\SiteAudit;
use App\Models\SiteAuditIssue;
use App\Support\AuditIssues;
use Illuminate\Support\Facades\DB;

/**
 * Core Web Vitals for a finished audit: PageSpeed Insights on the home page
 * and the `audit.vitals.pages` most-linked indexable pages, mobile and
 * desktop. Results go to site_audit_vitals; poor / needs-improvement pages
 * become issues and the audit is re-scored. Never fails the audit: when no
 * run succeeds, vitals_status is `unavailable` with a plain message.
 */
class VitalsRunner
{
    public function __construct(private readonly PageSpeedInsights $psi, private readonly AuditAnalyser $analyser) {}

    public function run(SiteAudit $audit): void
    {
        $urls = $this->urls($audit);
        if ($urls === []) {
            $this->finish($audit, SiteAudit::VITALS_UNAVAILABLE, 'No page answered 200, so there was nothing to test.');

            return;
        }

        $runs = [];
        foreach ($urls as $url) {
            foreach (config('indlela.audit.vitals.strategies') as $strategy) {
                $runs[] = [$url, $strategy];
            }
        }

        $results = $this->psi->run($runs);
        $this->store($audit, $runs, $results);

        $ok = array_filter($results, fn (array $r) => $r['status'] === 'done');
        if ($ok === []) {
            $quota = array_filter($results, fn (array $r) => $r['quota']);
            $message = $quota ? reset($quota)['error'] : 'PageSpeed Insights could not test the pages this time: '.rtrim(lcfirst(str_replace('PageSpeed Insights could not test this page: ', '', (string) $results[0]['error'])), '.').'.';
            $this->finish($audit, SiteAudit::VITALS_UNAVAILABLE, $message);

            return;
        }

        $failed = count($results) - count($ok);
        $this->finish($audit, SiteAudit::VITALS_DONE, $failed > 0 ? "{$failed} of ".count($results).' PageSpeed tests failed; the others are shown.' : null);
    }

    /**
     * Stores the results as vitals rows and issues (replacing earlier ones).
     *
     * @param  list<array{0: string, 1: string}>  $runs
     * @param  list<array<string, mixed>>  $results
     */
    public function store(SiteAudit $audit, array $runs, array $results): void
    {
        $poor = (int) config('indlela.audit.vitals.poor_score');
        $good = (int) config('indlela.audit.vitals.good_score');
        $pages = $audit->pages()->whereIn('url_hash', array_map(fn (array $r) => CrawlUrl::hash($r[0]), $runs))->pluck('id', 'url_hash');

        DB::transaction(function () use ($audit, $runs, $results, $poor, $good, $pages) {
            $audit->vitals()->delete();
            $audit->issues()->whereIn('type', AuditIssues::VITALS_TYPES)->delete();

            foreach ($runs as $i => [$url, $strategy]) {
                $r = $results[$i];
                $audit->vitals()->create([
                    'url' => $url, 'strategy' => $strategy, 'performance_score' => $r['performance_score'],
                    'lab' => $r['lab'], 'field' => $r['field'], 'status' => $r['status'], 'error' => $r['error'],
                ]);
                if ($r['status'] !== 'done') {
                    continue;
                }

                $score = $r['performance_score'];
                $fieldCategories = array_filter([$r['field']['lcp_category'] ?? null, $r['field']['inp_category'] ?? null, $r['field']['cls_category'] ?? null]);
                $type = match (true) {
                    ($score !== null && $score < $poor) || in_array('poor', $fieldCategories, true) => 'poor_core_web_vitals',
                    ($score !== null && $score < $good) || in_array('needs_improvement', $fieldCategories, true) => 'needs_improvement_core_web_vitals',
                    default => null,
                };
                if ($type === null) {
                    continue;
                }

                SiteAuditIssue::query()->create([
                    'audit_id' => $audit->id, 'workspace_id' => $audit->workspace_id, 'site_id' => $audit->site_id,
                    'type' => $type, 'severity' => AuditIssues::severity($type), 'page_id' => $pages[CrawlUrl::hash($url)] ?? null, 'url' => $url,
                    'details' => ['strategy' => $strategy, 'performance_score' => $score, 'lab' => $r['lab'], 'field' => $r['field']],
                    'fingerprint' => SiteAuditIssue::fingerprint($type, $url, $strategy),
                ]);
            }
        });
    }

    /** The home page, then the most-linked indexable pages. @return list<string> */
    public function urls(SiteAudit $audit): array
    {
        $home = $audit->crawl_meta['home_url'] ?? null;
        $homePage = $home ? $audit->pages()->where('url_hash', CrawlUrl::hash($home))->where('status_code', 200)->value('url') : null;

        $others = $audit->pages()->where('is_indexable', true)
            ->when($homePage, fn ($q) => $q->where('url_hash', '<>', CrawlUrl::hash($homePage)))
            ->orderByDesc('inlinks_count')->orderBy('id')
            ->limit((int) config('indlela.audit.vitals.pages'))->pluck('url')->all();

        return array_values(array_filter([$homePage, ...$others]));
    }

    private function finish(SiteAudit $audit, string $status, ?string $message): void
    {
        $audit->forceFill(['vitals_status' => $status, 'vitals_message' => $message])->save();
        $this->analyser->summarise($audit);
    }
}
