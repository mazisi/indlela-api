<?php

namespace Tests\Feature\Reports;

use App\Mail\ClientReportMail;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Reports\ReportPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AuthTestHelpers;
use Tests\Support\ReportHelpers;
use Tests\TestCase;

/** Generate, the PDF, show, share links, the public page and delete. */
class ReportApiTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase, ReportHelpers;

    private Site $site;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        Storage::fake('s3');
        $this->travelTo('2026-11-20 10:00:00');
        $this->site = $this->fullSite();
        $this->manager = $this->makeUser(Role::MANAGER);
    }

    private function generate(array $body = []): array
    {
        return $this->actingAs($this->manager, 'sanctum')->postJson("/api/sites/{$this->site->id}/reports", [
            'period' => 'last_month', 'sections' => ['search_console', 'rank_tracking', 'site_audit'], ...$body,
        ])->assertStatus(202)->json('data');
    }

    public function test_send_now_emails_last_months_report_to_the_schedule_and_keeps_the_monthly_run(): void
    {
        Mail::fake();
        $as = $this->actingAs($this->manager, 'sanctum');
        $url = "/api/sites/{$this->site->id}/report-schedule/send-now";

        $as->postJson($url)->assertStatus(409)->assertJsonPath('error_type', 'no_schedule');

        $as->putJson("/api/sites/{$this->site->id}/report-schedule", [
            'is_active' => true, 'day_of_month' => 1, 'send_hour' => 8, 'recipients' => ['thandi@karoo.co.za', 'ops@karoo.co.za'],
            'sections' => ['search_console'], 'compare' => 'previous', 'note' => 'Monthly update',
        ])->assertOk();

        $row = $as->postJson($url)->assertStatus(202)->json('data');
        $this->assertSame(['ready', 'manual', ['search_console'], 'Monthly update'], [$row['status'], $row['trigger'], $row['sections'], $row['note']]);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$row['period_start'], $row['period_end']]);
        $this->assertSame(['thandi@karoo.co.za', 'ops@karoo.co.za'], $row['sent_to']);
        Mail::assertSent(ClientReportMail::class, 2);

        // The monthly run still goes out on its day.
        $this->assertNull(ReportSchedule::query()->withoutGlobalScopes()->where('site_id', $this->site->id)->value('last_run_at'));

        // A Client login can't send.
        $client = $this->makeUser(Role::CLIENT, clients: [$this->site->client]);
        $this->actingAs($client, 'sanctum')->postJson($url)->assertForbidden();
    }

    public function test_generating_stores_a_real_pdf_and_returns_the_row(): void
    {
        $row = $this->generate(['note' => 'Thanks for a great month.']);

        $this->assertSame('ready', $row['status']);
        $this->assertSame('Karoo Coffee · SEO report · October 2026', $row['title']);
        $this->assertSame(['2026-10-01', '2026-10-31', 'October 2026'], [$row['period_start'], $row['period_end'], $row['period_label']]);
        $this->assertSame('manual', $row['trigger']);
        $this->assertSame(['id' => $this->manager->id, 'name' => $this->manager->displayName()], $row['generated_by']);
        $this->assertSame('Search traffic up 24% (3,720 clicks)', $row['summary'][0]);
        $this->assertNull($row['share']);
        $this->assertArrayNotHasKey('share_token', $row);

        $report = Report::query()->withoutGlobalScopes()->findOrFail($row['id']);
        $this->assertSame("reports/{$this->site->workspace_id}/{$report->id}.pdf", $report->pdf_path);
        Storage::disk('s3')->assertExists($report->pdf_path);
        $this->assertStringStartsWith('%PDF', Storage::disk('s3')->get($report->pdf_path));
    }

    public function test_periods_and_titles(): void
    {
        $this->assertSame(['2026-11-01', '2026-11-19'], array_values(array_intersect_key($this->generate(['period' => 'this_month']), array_flip(['period_start', 'period_end']))));
        $this->assertSame('August – October 2026', $this->generate(['period' => 'last_3_months'])['period_label']);
        $custom = $this->generate(['period' => 'custom', 'start' => '2026-10-05', 'end' => '2026-10-18', 'title' => 'Mid-month check']);
        $this->assertSame(['Mid-month check', '5 – 18 October 2026'], [$custom['title'], $custom['period_label']]);

        $as = $this->actingAs($this->manager, 'sanctum');
        $as->postJson("/api/sites/{$this->site->id}/reports", ['period' => 'custom', 'sections' => ['site_audit']])->assertJsonValidationErrors(['start', 'end']);
        $as->postJson("/api/sites/{$this->site->id}/reports", ['period' => 'custom', 'start' => '2026-10-10', 'end' => '2026-10-01', 'sections' => ['site_audit']])->assertJsonValidationErrors('start');
        $as->postJson("/api/sites/{$this->site->id}/reports", ['period' => 'custom', 'start' => '2026-11-01', 'end' => '2026-12-01', 'sections' => ['site_audit']])->assertJsonValidationErrors('end');
        $as->postJson("/api/sites/{$this->site->id}/reports", ['period' => 'last_month', 'sections' => []])->assertJsonValidationErrors('sections');
        $as->postJson("/api/sites/{$this->site->id}/reports", ['period' => 'last_month', 'sections' => ['backlinks']])->assertJsonValidationErrors('sections.0');
        $as->postJson("/api/sites/{$this->site->id}/reports", ['period' => 'yesterday', 'sections' => ['site_audit'], 'compare' => 'never'])->assertJsonValidationErrors(['period', 'compare']);
        $as->postJson("/api/sites/{$this->site->id}/reports", ['period' => 'last_month', 'sections' => ['site_audit'], 'send_to' => ['not-an-email']])->assertJsonValidationErrors('send_to.0');
    }

    public function test_a_failed_pdf_write_fails_the_report_with_no_phantom_file(): void
    {
        Storage::shouldReceive('put')->once()->andReturnFalse();

        $row = $this->generate();

        $this->assertSame('failed', $row['status']);
        $this->assertSame('The PDF could not be saved. Generate the report again in a few minutes.', $row['error']);
        $report = Report::query()->withoutGlobalScopes()->findOrFail($row['id']);
        $this->assertNull($report->pdf_path);
        $this->assertNull($report->data);
        $this->assertSame([], $row['summary']);
    }

    public function test_the_pdf_view_renders_every_section_and_the_branding(): void
    {
        Storage::disk('s3')->put('workspaces/logo.png', UploadedFile::fake()->image('logo.png', 120, 40)->getContent());
        $this->workspace()->forceFill(['logo_path' => 'workspaces/logo.png', 'brand_color' => '#AA3300'])->save();
        $report = $this->makeReport($this->site, ['note' => 'Line one']);

        $html = view('reports.pdf', app(ReportPdf::class)->viewData($report))->render();

        foreach (['Search traffic from Google', 'Keyword rankings', 'Website health', 'Line one', 'Prepared by '.$this->workspace()->name, 'data:image/png;base64,', 'data:image/svg+xml;base64,', '#aa3300', 'What to fix first'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringNotContainsStringIgnoringCase('indlela', $html);
    }

    public function test_show_lists_and_downloads(): void
    {
        $id = $this->generate()['id'];
        $as = $this->actingAs($this->manager, 'sanctum');

        $show = $as->getJson("/api/reports/{$id}")->assertOk()->json('data');
        $this->assertSame('October 2026', $show['data']['period']['label']);
        $this->assertArrayNotHasKey('branding', $show['data'], 'the logo path stays server-side');
        $this->assertSame(['name' => $this->workspace()->name, 'logo_url' => null, 'brand_color' => '#1f4e79'], $show['branding']);

        $as->getJson("/api/sites/{$this->site->id}/reports")->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.per_page', 15);

        $sites = $as->getJson('/api/reports/sites')->assertOk()->json('data');
        $this->assertSame($id, collect($sites)->firstWhere('id', $this->site->id)['last_report']['id']);

        $pdf = $as->get("/api/reports/{$id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('attachment; filename="karoo-coffee-seo-report-october-2026.pdf"', $pdf->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_the_logo_is_served_behind_a_signed_url(): void
    {
        Storage::disk('s3')->put('workspaces/1/logo.png', 'PNGBYTES');
        $this->workspace()->forceFill(['logo_path' => 'workspaces/1/logo.png'])->save();
        $id = $this->generate()['id'];

        $url = $this->actingAs($this->manager, 'sanctum')->getJson("/api/reports/{$id}")->json('data.branding.logo_url');
        $this->assertStringContainsString('signature=', $url);
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(preg_replace('/signature=\w+/', 'signature=forged', $url))->assertForbidden();
    }

    public function test_share_links_create_rotate_expire_and_revoke(): void
    {
        $id = $this->generate()['id'];
        $as = $this->actingAs($this->manager, 'sanctum');

        $first = $as->postJson("/api/reports/{$id}/share", ['expires_in_days' => 7])->assertOk()->json('data.share');
        $this->assertMatchesRegularExpression('#^http://localhost:5173/r/[A-Za-z0-9]{40}$#', $first['url']);
        $this->assertSame(now()->addDays(7)->toIso8601String(), $first['expires_at']);
        $token = basename($first['url']);
        $this->getJson("/api/public/reports/{$token}")->assertOk();

        $second = $as->postJson("/api/reports/{$id}/share", ['expires_in_days' => null])->assertOk()->json('data.share');
        $this->assertNull($second['expires_at']);
        $this->assertNotSame($first['url'], $second['url']);
        $this->getJson("/api/public/reports/{$token}")->assertNotFound(); // rotated

        $as->postJson("/api/reports/{$id}/share", ['expires_in_days' => 14])->assertJsonValidationErrors('expires_in_days');

        $as->postJson("/api/reports/{$id}/share", ['expires_in_days' => 30])->assertOk();
        $token = basename(Report::query()->withoutGlobalScopes()->find($id)->shareUrl());
        $this->travel(31)->days();
        $this->getJson("/api/public/reports/{$token}")->assertNotFound();
        $this->assertNull($this->actingAs($this->manager, 'sanctum')->getJson("/api/reports/{$id}")->json('data.share'), 'expired = not shared');

        $as->postJson("/api/reports/{$id}/share")->assertOk();
        $token = basename(Report::query()->withoutGlobalScopes()->find($id)->shareUrl());
        $as->deleteJson("/api/reports/{$id}/share")->assertOk()->assertJsonPath('data.share', null);
        $this->getJson("/api/public/reports/{$token}")->assertNotFound();
    }

    public function test_the_public_page_returns_the_snapshot_and_nothing_else(): void
    {
        Storage::disk('s3')->put('workspaces/9/logo.png', 'PNGBYTES');
        $this->workspace()->forceFill(['logo_path' => 'workspaces/9/logo.png'])->save();
        $id = $this->generate(['note' => 'Well done'])['id'];
        $token = basename($this->actingAs($this->manager, 'sanctum')->postJson("/api/reports/{$id}/share")->json('data.share.url'));
        $this->app['auth']->forgetGuards();

        $response = $this->getJson("/api/public/reports/{$token}")->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $data = $response->json('data');
        $this->assertSame(['title', 'period_label', 'summary', 'note', 'data', 'branding', 'generated_at'], array_keys($data));
        $this->assertSame('Well done', $data['note']);
        $this->assertSame("http://localhost:8000/api/public/reports/{$token}/logo", $data['branding']['logo_url']);
        $body = $response->getContent();
        $this->assertStringNotContainsString('workspaces/9', $body);
        $this->assertStringNotContainsString('"workspace_id"', $body);
        $this->assertStringNotContainsString('"id"', $body);
        $this->assertStringNotContainsString($this->manager->email, $body);
        $this->assertStringNotContainsString('@', $body);

        $this->get("/api/public/reports/{$token}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get("/api/public/reports/{$token}/logo")->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_bad_tokens_all_answer_the_same_404(): void
    {
        $id = $this->generate()['id'];
        $as = $this->actingAs($this->manager, 'sanctum');
        $as->postJson("/api/reports/{$id}/share", ['expires_in_days' => 7]);
        $expired = basename(Report::query()->withoutGlobalScopes()->find($id)->shareUrl());
        $failed = Report::query()->withoutGlobalScopes()->create([
            'workspace_id' => $this->site->workspace_id, 'site_id' => $this->site->id, 'client_id' => $this->site->client_id, 'title' => 'x',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'sections' => ['site_audit'], 'status' => Report::FAILED,
            'share_token' => str_repeat('a', 40),
        ]);
        $this->travel(8)->days();
        $this->app['auth']->forgetGuards();

        $bodies = [];
        foreach ([$expired, str_repeat('b', 40), $failed->share_token, 'short'] as $token) {
            foreach (['', '/pdf', '/logo'] as $suffix) {
                $r = $this->getJson("/api/public/reports/{$token}{$suffix}")->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
                $bodies[] = $r->getContent();
            }
        }
        $this->assertCount(1, array_unique($bodies));
        $this->assertSame('{"message":"This report link is not valid or has expired."}', $bodies[0]);
    }

    public function test_delete_removes_the_pdf_and_the_link(): void
    {
        $id = $this->generate()['id'];
        $as = $this->actingAs($this->manager, 'sanctum');
        $token = basename($as->postJson("/api/reports/{$id}/share")->json('data.share.url'));
        $path = Report::query()->withoutGlobalScopes()->find($id)->pdf_path;

        $as->deleteJson("/api/reports/{$id}")->assertNoContent();

        Storage::disk('s3')->assertMissing($path);
        $this->assertSoftDeleted('reports', ['id' => $id, 'share_token' => null]);
        $as->getJson("/api/reports/{$id}")->assertNotFound();
        $this->getJson("/api/public/reports/{$token}")->assertNotFound();
    }

    public function test_bulk_revokes_links_and_deletes_reports(): void
    {
        $as = $this->actingAs($this->manager, 'sanctum');
        $ids = [$this->generate()['id'], $this->generate()['id'], $this->generate()['id']];
        $token = basename($as->postJson("/api/reports/{$ids[0]}/share")->json('data.share.url'));
        $as->postJson("/api/reports/{$ids[1]}/share")->assertOk();

        $as->postJson("/api/sites/{$this->site->id}/reports/bulk", ['action' => 'unshare', 'ids' => $ids])
            ->assertOk()->assertExactJson(['affected' => 2]);
        $this->assertSame(0, Report::query()->withoutGlobalScopes()->whereNotNull('share_token')->count());
        $this->getJson("/api/public/reports/{$token}")->assertNotFound();

        $paths = Report::query()->withoutGlobalScopes()->whereKey($ids)->pluck('pdf_path', 'id');
        $as->postJson("/api/sites/{$this->site->id}/reports/bulk", ['action' => 'delete', 'ids' => [$ids[0], $ids[1]]])
            ->assertOk()->assertExactJson(['affected' => 2]);
        $this->assertSoftDeleted('reports', ['id' => $ids[0]]);
        $this->assertSoftDeleted('reports', ['id' => $ids[1]]);
        $this->assertNotSoftDeleted('reports', ['id' => $ids[2]]);
        Storage::disk('s3')->assertMissing($paths[$ids[0]]);
        Storage::disk('s3')->assertExists($paths[$ids[2]]);
    }

    public function test_bulk_is_all_or_nothing_and_checks_each_report(): void
    {
        $as = $this->actingAs($this->manager, 'sanctum');
        $id = $this->generate()['id'];
        $other = $this->makeReport($this->fullSite());
        $url = "/api/sites/{$this->site->id}/reports/bulk";

        // Another site's report, or one already gone: nothing changes.
        $as->postJson($url, ['action' => 'delete', 'ids' => [$id, $other->id]])->assertUnprocessable()->assertJsonValidationErrors('ids');
        $as->postJson($url, ['action' => 'delete', 'ids' => [$id, 999999]])->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->assertNotSoftDeleted('reports', ['id' => $id]);
        $as->postJson($url, ['action' => 'archive', 'ids' => [$id]])->assertUnprocessable()->assertJsonValidationErrors('action');
        $as->postJson($url, ['action' => 'delete', 'ids' => []])->assertUnprocessable()->assertJsonValidationErrors('ids');

        // A client user may view reports but not delete or revoke them.
        $client = $this->makeUser(Role::CLIENT, clients: [$this->site->client]);
        $this->actingAs($client, 'sanctum')->postJson($url, ['action' => 'delete', 'ids' => [$id]])->assertForbidden();
        $this->actingAs($client, 'sanctum')->postJson($url, ['action' => 'unshare', 'ids' => [$id]])->assertForbidden();
        $this->assertNotSoftDeleted('reports', ['id' => $id]);
    }

    public function test_sending_or_downloading_before_ready_is_409(): void
    {
        $report = Report::query()->withoutGlobalScopes()->create([
            'workspace_id' => $this->site->workspace_id, 'site_id' => $this->site->id, 'client_id' => $this->site->client_id, 'title' => 'x',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'sections' => ['site_audit'],
        ]);
        $as = $this->actingAs($this->manager, 'sanctum');

        $as->postJson("/api/reports/{$report->id}/send", ['recipients' => ['client@karoo.co.za']])->assertStatus(409)->assertJsonPath('error_type', 'report_not_ready');
        $as->getJson("/api/reports/{$report->id}/pdf")->assertStatus(409)->assertJsonPath('error_type', 'report_not_ready');
        $show = $as->getJson("/api/reports/{$report->id}")->assertOk()->json('data');
        $this->assertSame(['generating', null], [$show['status'], $show['data']]);
    }

    public function test_the_brand_colour_is_validated_and_returned(): void
    {
        $owner = $this->ownerOf($this->workspace());
        $as = $this->actingAs($owner, 'sanctum');

        $as->patchJson('/api/workspace', ['brand_color' => '#1A2B3C'])->assertOk()->assertJsonPath('data.brand_color', '#1a2b3c');
        foreach (['1a2b3c', '#123', '#12345G', 'red', '#1a2b3c4d'] as $bad) {
            $as->patchJson('/api/workspace', ['brand_color' => $bad])->assertJsonValidationErrors('brand_color');
        }
        $as->getJson('/api/me')->assertJsonPath('workspace.brand_color', '#1a2b3c');
        $as->patchJson('/api/workspace', ['brand_color' => null])->assertOk()->assertJsonPath('data.brand_color', null);
        $this->assertSame('#1f4e79', $this->workspace()->fresh()->brandColor());
    }
}
