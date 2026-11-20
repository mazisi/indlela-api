<?php

namespace Tests\Feature\Reports;

use App\Jobs\GenerateReport;
use App\Mail\ClientReportMail;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\Site;
use App\Models\Workspace;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AuthTestHelpers;
use Tests\Support\ReportHelpers;
use Tests\TestCase;

/** reports:run-scheduled: the right day, the previous month, once a month. */
class ScheduledReportsTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase, ReportHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        Storage::fake('s3');
    }

    private function schedule(Site $site, array $state = []): ReportSchedule
    {
        return ReportSchedule::query()->withoutGlobalScopes()->create([
            'workspace_id' => $site->workspace_id, 'site_id' => $site->id, 'is_active' => true, 'day_of_month' => 3, 'send_hour' => 6,
            'recipients' => ['owner@karoo.co.za'], 'sections' => ['search_console', 'site_audit'], 'compare' => 'previous', 'note' => 'Monthly',
            ...$state,
        ]);
    }

    public function test_due_schedules_report_on_last_month_and_email_it(): void
    {
        $this->travelTo('2026-11-03 06:00:00');
        $site = $this->fullSite();
        $schedule = $this->schedule($site);

        $this->artisan('reports:run-scheduled')->expectsOutput('Queued 1 scheduled report(s).')->assertSuccessful();

        $report = Report::query()->withoutGlobalScopes()->sole();
        $this->assertSame([Report::READY, Report::SCHEDULED, null], [$report->status, $report->trigger, $report->generated_by]);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$report->period_start->toDateString(), $report->period_end->toDateString()]);
        $this->assertSame(['search_console', 'site_audit'], $report->sections);
        $this->assertSame('Monthly', $report->note);
        $this->assertSame(['owner@karoo.co.za'], $report->sent_to);
        Mail::assertSent(ClientReportMail::class, fn (ClientReportMail $m) => $m->hasTo('owner@karoo.co.za'));
        $this->assertSame([$report->id, '2026-11-03'], [$schedule->fresh()->last_report_id, $schedule->fresh()->last_run_at->toDateString()]);
        $this->assertSame('2026-12-03', $schedule->fresh()->nextRunOn()->toDateString());
    }

    public function test_it_runs_once_a_month(): void
    {
        $this->travelTo('2026-11-03 06:00:00');
        $this->schedule($this->reportSite());

        $this->artisan('reports:run-scheduled')->expectsOutput('Queued 1 scheduled report(s).');
        $this->artisan('reports:run-scheduled')->expectsOutput('Queued 0 scheduled report(s).');
        $this->travelTo('2026-12-03 06:00:00');
        $this->artisan('reports:run-scheduled')->expectsOutput('Queued 1 scheduled report(s).');

        $this->assertSame(['2026-10-01', '2026-11-01'], Report::query()->withoutGlobalScopes()->orderBy('id')->get()->map(fn ($r) => $r->period_start->toDateString())->all());
    }

    public function test_other_days_inactive_schedules_and_inactive_sites_are_skipped(): void
    {
        $this->travelTo('2026-11-03 06:00:00');
        $this->schedule($this->reportSite(), ['day_of_month' => 4]);
        $this->schedule($this->reportSite(), ['is_active' => false]);
        $inactive = $this->reportSite();
        $inactive->forceFill(['is_active' => false])->save();
        $this->schedule($inactive);
        $deleted = $this->reportSite();
        $this->schedule($deleted);
        $deleted->delete();

        $this->artisan('reports:run-scheduled')->expectsOutput('Queued 0 scheduled report(s).');
        $this->assertSame(0, Report::query()->withoutGlobalScopes()->count());
    }

    public function test_every_due_schedule_across_workspaces_gets_its_own_job(): void
    {
        $this->travelTo('2026-11-03 06:00:00');
        Queue::fake();
        $other = $this->makeWorkspace('Rival Agency');
        $sites = [$this->reportSite(), $this->reportSite(), $this->reportSite($this->makeClient($other))];
        foreach ($sites as $site) {
            $this->schedule($site);
        }

        $this->artisan('reports:run-scheduled')->expectsOutput('Queued 3 scheduled report(s).');

        Queue::assertPushed(GenerateReport::class, 3);
        $reports = Report::query()->withoutGlobalScopes()->orderBy('id')->get();
        $this->assertSame(array_map(fn (Site $s) => $s->workspace_id, $sites), $reports->pluck('workspace_id')->all());
        $jobs = Queue::pushed(GenerateReport::class);
        $this->assertSame($reports->pluck('id')->all(), $jobs->pluck('reportId')->sort()->values()->all());
        $this->assertSame(3, $jobs->map(fn (GenerateReport $j) => $j->uniqueId())->unique()->count(), 'unique per report, never shared');
        $this->assertTrue($jobs->every(fn (GenerateReport $j) => $j->sendTo === ['owner@karoo.co.za'] && Workspace::find($j->workspaceId) !== null));
    }

    public function test_it_is_scheduled_hourly_in_sast(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'reports:run-scheduled'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertSame('Africa/Johannesburg', $event->timezone);
    }

    public function test_each_schedule_goes_out_at_its_own_hour_and_catches_up_later_that_day(): void
    {
        Queue::fake();
        $early = $this->schedule($this->reportSite(), ['send_hour' => 7]);
        $late = $this->schedule($this->reportSite(), ['send_hour' => 14]);

        $this->travelTo('2026-11-03 06:00:00');
        $this->artisan('reports:run-scheduled')->expectsOutput('Queued 0 scheduled report(s).');

        // The 07:00 run was missed (scheduler down): 09:00 still sends it, not the 14:00 one.
        $this->travelTo('2026-11-03 09:00:00');
        $this->artisan('reports:run-scheduled')->expectsOutput('Queued 1 scheduled report(s).');
        $this->assertNotNull($early->fresh()->last_run_at);
        $this->assertNull($late->fresh()->last_run_at);
        $this->assertSame('2026-11-03T14:00:00+02:00', $late->fresh()->nextRunAt()->toIso8601String());

        $this->travelTo('2026-11-03 14:00:00');
        $this->artisan('reports:run-scheduled')->expectsOutput('Queued 1 scheduled report(s).');
        $this->artisan('reports:run-scheduled')->expectsOutput('Queued 0 scheduled report(s).');
        $this->assertSame('2026-12-03T14:00:00+02:00', $late->fresh()->nextRunAt()->toIso8601String());
    }
}
