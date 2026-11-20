<?php

namespace App\Services\SiteAudit;

use App\Models\SiteAudit;
use App\Models\SiteAuditIssue;
use App\Models\SiteAuditPage;
use App\Support\AuditIssues;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * What the Site audit page reads from one audit: the issue summary, an issue
 * type's URLs, pages, a page's detail, vitals and the comparison lists.
 * Every query goes through the audit (already workspace-scoped and
 * authorised by the caller).
 */
class AuditReports
{
    /**
     * Every issue type found, with its catalogue text: critical first, then by count.
     *
     * @return list<array<string, mixed>>
     */
    public function issues(SiteAudit $audit): array
    {
        $rows = $audit->issues()->toBase()
            ->selectRaw('type, COUNT(*) AS count, SUM(CASE WHEN is_new THEN 1 ELSE 0 END) AS new_count')
            ->groupBy('type')->get();

        return $rows->filter(fn (object $row) => AuditIssues::exists($row->type))
            ->map(fn (object $row) => [...AuditIssues::describe($row->type), 'count' => (int) $row->count, 'new_count' => (int) $row->new_count])
            ->sortBy([fn (array $a, array $b) => AuditIssues::rank($a['severity']) <=> AuditIssues::rank($b['severity']), ['count', 'desc'], ['type', 'asc']])
            ->values()->all();
    }

    public function issueUrls(SiteAudit $audit, string $type, ?string $like, int $perPage): LengthAwarePaginator
    {
        return $audit->issues()->where('type', $type)
            ->when($like, fn (Builder $q, string $like) => $q->whereRaw('LOWER(url) LIKE ?', [$like]))
            ->orderBy('url')->orderBy('id')
            ->paginate($perPage)
            ->through(fn (SiteAuditIssue $issue) => ['url' => $issue->url, 'page_id' => $issue->page_id, 'details' => $issue->details, 'is_new' => $issue->is_new]);
    }

    /** @param  array{search?: string|null, status?: string|null, indexable?: string|null, sort?: string|null}  $filters */
    public function pages(SiteAudit $audit, array $filters, ?string $like, int $perPage): LengthAwarePaginator
    {
        $query = $audit->pages()->withCount('issues')
            ->when($like, fn (Builder $q, string $like) => $q->where(fn (Builder $q) => $q
                ->whereRaw('LOWER(url) LIKE ?', [$like])->orWhereRaw('LOWER(title) LIKE ?', [$like])))
            ->when($filters['status'] ?? null, function (Builder $q, string $status) {
                $from = (int) $status[0] * 100;
                $q->whereBetween('status_code', [$from, $from + 99]);
            })
            ->when(isset($filters['indexable']), fn (Builder $q) => $q->where('is_indexable', $filters['indexable'] === '1'));

        $sort = $filters['sort'] ?? null;
        if ($sort) {
            $column = ltrim($sort, '-');
            $query->orderByRaw($column.' '.(str_starts_with($sort, '-') ? 'DESC' : 'ASC').' NULLS LAST');
        } else {
            $query->orderByRaw('depth ASC NULLS LAST');
        }

        return $query->orderBy('url')->orderBy('id')->paginate($perPage);
    }

    /**
     * A page's issues and the first 50 pages linking to it.
     *
     * @return array{issues: list<array<string, mixed>>, inlinks: list<array<string, mixed>>, inlinks_total: int}
     */
    public function pageDetail(SiteAudit $audit, SiteAuditPage $page): array
    {
        $issues = $page->issues()->get()
            ->sortBy([fn ($a, $b) => AuditIssues::rank($a->severity) <=> AuditIssues::rank($b->severity), ['type', 'asc']])
            ->map(fn (SiteAuditIssue $issue) => [
                'type' => $issue->type,
                'severity' => $issue->severity,
                'title' => AuditIssues::exists($issue->type) ? AuditIssues::describe($issue->type)['title'] : $issue->type,
                'details' => $issue->details,
                'is_new' => $issue->is_new,
            ])->values()->all();

        $inlinks = DB::table('site_audit_links as l')->join('site_audit_pages as p', 'p.id', '=', 'l.from_page_id')
            ->where('l.audit_id', $audit->id)->where('l.to_url_hash', $page->url_hash)->where('l.kind', 'link')->where('l.from_page_id', '<>', $page->id);

        return [
            'issues' => $issues,
            'inlinks' => (clone $inlinks)->orderBy('p.depth')->orderBy('p.url')->limit(50)
                ->get(['p.id as page_id', 'p.url', 'l.nofollow'])
                ->map(fn (object $l) => ['page_id' => (int) $l->page_id, 'url' => $l->url, 'nofollow' => (bool) $l->nofollow])->all(),
            'inlinks_total' => (clone $inlinks)->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function vitals(SiteAudit $audit): array
    {
        return $audit->vitals()->orderBy('id')->get()->map(fn ($v) => [
            'url' => $v->url, 'strategy' => $v->strategy, 'performance_score' => $v->performance_score,
            'lab' => $v->lab, 'field' => $v->field, 'status' => $v->status, 'error' => $v->error,
        ])->all();
    }

    /**
     * Issues fixed since the previous audit (in it, not in this one) or new in
     * this one; empty without a previous audit.
     */
    public function comparison(SiteAudit $audit, string $filter, ?string $like, int $perPage): LengthAwarePaginator
    {
        $previousId = $audit->comparison['previous_audit_id'] ?? null;
        if ($previousId === null) {
            return new LengthAwarePaginator([], 0, $perPage, LengthAwarePaginator::resolveCurrentPage(), ['path' => LengthAwarePaginator::resolveCurrentPath()]);
        }

        $previous = SiteAudit::query()->find($previousId);
        $skip = $audit->vitals_status === SiteAudit::VITALS_DONE && $previous?->vitals_status === SiteAudit::VITALS_DONE ? [] : AuditIssues::VITALS_TYPES;
        [$from, $against] = $filter === 'fixed' ? [$previousId, $audit->id] : [$audit->id, $previousId];

        return SiteAuditIssue::query()->where('audit_id', $from)->whereNotIn('type', $skip)
            ->whereNotIn('fingerprint', DB::table('site_audit_issues')->select('fingerprint')->where('audit_id', $against))
            ->when($like, fn (Builder $q, string $like) => $q->whereRaw('LOWER(url) LIKE ?', [$like]))
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")->orderBy('type')->orderBy('url')->orderBy('id')
            ->paginate($perPage)
            ->through(fn (SiteAuditIssue $issue) => [
                'type' => $issue->type,
                'severity' => $issue->severity,
                'title' => AuditIssues::exists($issue->type) ? AuditIssues::describe($issue->type)['title'] : $issue->type,
                'url' => $issue->url,
                'details' => $issue->details,
            ]);
    }
}
