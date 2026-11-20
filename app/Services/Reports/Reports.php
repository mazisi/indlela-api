<?php

namespace App\Services\Reports;

use App\Exceptions\ReportException;
use App\Jobs\GenerateReport;
use App\Mail\ClientReportMail;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * White-label reports: create (queues GenerateReport), generate (snapshot,
 * summary, PDF on the default disk), email, share links, delete, and the
 * monthly schedules.
 */
class Reports
{
    public function __construct(
        private readonly ReportSnapshot $snapshot,
        private readonly ReportPdf $pdf,
        private readonly CurrentWorkspace $current,
    ) {}

    /**
     * A new report of $site (status generating), queued for generation; it's
     * emailed to $sendTo once ready.
     *
     * @param  array{period: string, start?: ?string, end?: ?string, compare?: ?string, sections: list<string>, note?: ?string, title?: ?string}  $input
     * @param  list<string>  $sendTo
     */
    public function create(Site $site, array $input, ?User $user, string $trigger = Report::MANUAL, array $sendTo = [], ?CarbonImmutable $today = null): Report
    {
        [$start, $end] = ReportPeriods::resolve($input['period'], $input['start'] ?? null, $input['end'] ?? null, $today);
        $client = $site->client;
        $title = trim((string) ($input['title'] ?? '')) ?: "{$client?->name} · SEO report · ".ReportPeriods::label($start, $end);

        $report = Report::query()->create([
            'workspace_id' => $site->workspace_id,
            'site_id' => $site->id,
            'client_id' => $site->client_id,
            'title' => Str::limit($title, 200, ''),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'compare' => $input['compare'] ?? 'previous',
            'sections' => array_values(array_intersect(Report::SECTIONS, $input['sections'])),
            'note' => ($note = trim((string) ($input['note'] ?? ''))) === '' ? null : $note,
            'generated_by' => $user?->id,
            'trigger' => $trigger,
        ]);

        GenerateReport::dispatch($report->id, $report->workspace_id, array_values(array_unique($sendTo)));

        // Fresh: on the sync queue driver the job has already run.
        return $report->refresh();
    }

    /**
     * Snapshot, summary and PDF. The PDF is written inside the transaction
     * that marks the report ready, so a report is never ready without its
     * file (the s3 disk returns false instead of throwing). Any failure marks
     * it failed with a plain reason and is reported.
     */
    public function generate(Report $report): Report
    {
        if ($report->status !== Report::GENERATING) {
            return $report;
        }

        $path = "reports/{$report->workspace_id}/{$report->id}.pdf";
        $stored = false;
        $saving = false;

        try {
            $report->data = $this->snapshot->build($report);
            $report->summary = ReportSummary::for($report->data);
            $bytes = $this->pdf->render($report);
            $saving = true;

            DB::transaction(function () use ($report, $path, $bytes, &$stored) {
                if (Storage::put($path, $bytes) === false) {
                    throw new RuntimeException("The report PDF could not be written to {$path}.");
                }
                $stored = true;
                $report->forceFill(['status' => Report::READY, 'pdf_path' => $path, 'error' => null])->save();
            });
        } catch (Throwable $e) {
            report($e);
            if ($stored) {
                Storage::delete($path);
            }
            $report->forceFill([
                'status' => Report::FAILED,
                'error' => $saving
                    ? 'The PDF could not be saved. Generate the report again in a few minutes.'
                    : 'The report could not be generated. Generate it again in a few minutes.',
                'pdf_path' => null,
                'data' => null,
                'summary' => null,
            ])->save();
        }

        return $report;
    }

    /**
     * Email the report (one message per recipient) with the PDF attached and
     * a share link (made, or extended to the email's 90 days) for the button.
     *
     * @param  list<string>  $recipients
     */
    public function send(Report $report, array $recipients, ?User $sender = null, ?string $message = null): Report
    {
        if (! $report->isReady() || ! $report->pdf_path) {
            throw ReportException::notReady();
        }
        $pdf = Storage::get($report->pdf_path);
        if (! is_string($pdf) || $pdf === '') {
            Log::error('Report PDF missing from storage.', ['report_id' => $report->id, 'path' => $report->pdf_path]);
            throw ReportException::notReady();
        }

        $this->ensureEmailShare($report);
        $replyTo = $this->replyTo($report, $sender);
        $logo = ReportPdf::logoImage($report->data['branding']['logo'] ?? null);
        $recipients = array_values(array_unique(array_map(fn (string $e) => strtolower(trim($e)), $recipients)));

        foreach ($recipients as $email) {
            Mail::to($email)->send(new ClientReportMail($report, $pdf, ReportPdf::fileName($report), (string) $report->shareUrl(), $replyTo, $message, $logo));
        }

        $report->forceFill(['sent_at' => now(), 'sent_to' => $recipients])->save();

        return $report;
    }

    /** A new share token (any old link stops working); null days = no expiry. */
    public function share(Report $report, ?int $days): Report
    {
        $report->forceFill(['share_token' => Str::random(40), 'share_expires_at' => $days ? now()->addDays($days) : null])->save();

        return $report;
    }

    public function revokeShare(Report $report): Report
    {
        $report->forceFill(['share_token' => null, 'share_expires_at' => null])->save();

        return $report;
    }

    /** Soft-delete the report (its link stops working) and delete its PDF. */
    public function delete(Report $report): void
    {
        $path = $report->pdf_path;
        DB::transaction(function () use ($report) {
            $report->forceFill(['share_token' => null, 'share_expires_at' => null])->save();
            $report->delete();
        });

        if ($path && ! Storage::delete($path)) {
            Log::warning('Report PDF could not be deleted.', ['report_id' => $report->id, 'path' => $path]);
        }
    }

    /**
     * The list's bulk bar: `delete` or `unshare` (revoke share links) on
     * reports of the site. All or nothing: a report that's gone or not the
     * site's is a 422 on `ids`, one the user may not change a 403.
     *
     * @param  list<int>  $ids
     * @return int reports changed (for unshare, those that had a link)
     */
    public function bulk(Site $site, string $action, array $ids): int
    {
        $rows = Report::query()->where('site_id', $site->id)->whereKey($ids)->get();
        if ($rows->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['ids' => 'Some of the selected reports no longer exist. Refresh and try again.']);
        }
        $rows->each(fn (Report $r) => Gate::authorize($action === 'delete' ? 'delete' : 'create', $r));

        if ($action === 'delete') {
            $rows->each(fn (Report $r) => $this->delete($r));

            return $rows->count();
        }

        $shared = $rows->filter(fn (Report $r) => $r->share_token !== null);
        $shared->each(fn (Report $r) => $this->revokeShare($r));

        return $shared->count();
    }

    /** @param  array{is_active: bool, day_of_month: int, send_hour?: int, recipients: list<string>, sections: list<string>, compare: string, note?: ?string}  $data */
    public function saveSchedule(Site $site, array $data): ReportSchedule
    {
        $schedule = ReportSchedule::query()->firstOrNew(['site_id' => $site->id]);
        $schedule->fill([
            'workspace_id' => $site->workspace_id,
            'is_active' => (bool) $data['is_active'],
            'day_of_month' => (int) $data['day_of_month'],
            'send_hour' => (int) ($data['send_hour'] ?? $schedule->send_hour ?? ReportSchedule::DEFAULT_SEND_HOUR),
            'recipients' => array_values(array_unique(array_map(fn (string $e) => strtolower(trim($e)), $data['recipients']))),
            'sections' => array_values(array_intersect(Report::SECTIONS, $data['sections'])),
            'compare' => $data['compare'],
            'note' => ($note = trim((string) ($data['note'] ?? ''))) === '' ? null : $note,
        ])->save();

        return $schedule;
    }

    /**
     * Runs the schedules due now (active, today's day of the month, their
     * hour reached — so a missed hourly run catches up later the same day —
     * not run this month, their site active): the previous calendar month's report,
     * emailed to the schedule's recipients. Each schedule is claimed with one
     * conditional UPDATE first, so two runs (or servers) can't both create a
     * month's report. Spans workspaces on purpose; each report is created
     * inside its site's workspace and its job carries it.
     *
     * @return int reports queued
     */
    public function runScheduled(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $monthStart = $now->startOfMonth();

        $schedules = ReportSchedule::query()->withoutGlobalScope('workspace')
            ->where('is_active', true)->where('day_of_month', $now->day)->where('send_hour', '<=', $now->hour)
            ->where(fn ($q) => $q->whereNull('last_run_at')->orWhere('last_run_at', '<', $monthStart))
            ->whereHas('site', fn ($q) => $q->withoutGlobalScope('workspace')->where('is_active', true)->whereNull('deleted_at'))
            ->orderBy('id')->get();

        $queued = 0;
        foreach ($schedules as $schedule) {
            $claimed = ReportSchedule::query()->withoutGlobalScope('workspace')->whereKey($schedule->id)
                ->where(fn ($q) => $q->whereNull('last_run_at')->orWhere('last_run_at', '<', $monthStart))
                ->update(['last_run_at' => $now, 'updated_at' => $now]);
            if ($claimed === 0) {
                continue;
            }

            $workspace = Workspace::query()->find($schedule->workspace_id);
            if (! $workspace) {
                continue;
            }

            try {
                $report = $this->current->using($workspace, function () use ($schedule, $now) {
                    $site = Site::query()->findOrFail($schedule->site_id);

                    return $this->create($site, [
                        'period' => 'last_month',
                        'compare' => $schedule->compare,
                        'sections' => $schedule->sections,
                        'note' => $schedule->note,
                    ], null, Report::SCHEDULED, $schedule->recipients ?? [], $now->startOfDay());
                });
                ReportSchedule::query()->withoutGlobalScope('workspace')->whereKey($schedule->id)->update(['last_report_id' => $report->id]);
                $queued++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $queued;
    }

    /**
     * "Send now": the schedule's report (last calendar month, its sections,
     * compare and note) generated at once and emailed to its recipients, as
     * $user. The monthly run is untouched (last_run_at isn't set), so the
     * scheduled report still goes out on its day.
     */
    public function sendScheduleNow(Site $site, User $user): Report
    {
        $schedule = $site->reportSchedule;
        if (! $schedule) {
            throw ReportException::noSchedule();
        }

        return $this->create($site, [
            'period' => 'last_month',
            'compare' => $schedule->compare,
            'sections' => $schedule->sections ?? Report::SECTIONS,
            'note' => $schedule->note,
        ], $user, Report::MANUAL, $schedule->recipients ?? []);
    }

    /** The email's button needs a link that lasts: keep the current one, at least email_share_days long. */
    private function ensureEmailShare(Report $report): void
    {
        $until = now()->addDays((int) config('indlela.reports.email_share_days', 90));

        if (! $report->isShared()) {
            $report->forceFill(['share_token' => Str::random(40), 'share_expires_at' => $until])->save();
        } elseif ($report->share_expires_at !== null && $report->share_expires_at->lt($until)) {
            $report->forceFill(['share_expires_at' => $until])->save();
        }
    }

    /**
     * Replies go to a person at the agency: whoever is sending, else whoever
     * generated the report, else the workspace's first active Owner.
     *
     * @return array{email: string, name: string}|null
     */
    private function replyTo(Report $report, ?User $sender): ?array
    {
        $user = $sender ?? $report->generatedBy()->first();
        if (! $user || $user->trashed() || ! $user->isActive()) {
            $user = User::query()->active()
                ->whereIn('id', DB::table('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->where('roles.workspace_id', $report->workspace_id)->where('roles.name', Role::OWNER)
                    ->where('model_has_roles.model_type', (new User)->getMorphClass())
                    ->select('model_has_roles.model_id'))
                ->orderBy('id')->first();
        }

        return $user ? ['email' => $user->email, 'name' => $user->displayName()] : null;
    }
}
