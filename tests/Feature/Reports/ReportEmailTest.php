<?php

namespace Tests\Feature\Reports;

use App\Mail\ClientReportMail;
use App\Mail\EmailTemplateType;
use App\Models\EmailTemplate;
use App\Models\Report;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Reports\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AuthTestHelpers;
use Tests\Support\ReportHelpers;
use Tests\TestCase;

/** The white-label email: sender, reply-to, attachment, link, wording. */
class ReportEmailTest extends TestCase
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
        config(['mail.from.address' => 'reports@mailer.test', 'mail.from.name' => 'Indlela']);
        $this->workspace()->forceFill(['name' => 'Acme Digital'])->save();
        $this->site = $this->fullSite();
        $this->manager = $this->makeUser(Role::MANAGER, ['email' => 'thandi@acme.test', 'full_name' => 'Thandi', 'surname' => 'Mokoena']);
    }

    private function send(int $id, array $body): array
    {
        return $this->actingAs($this->manager, 'sanctum')->postJson("/api/reports/{$id}/send", $body)->assertOk()->json('data');
    }

    public function test_the_email_is_from_the_workspace_with_the_pdf_and_a_90_day_link(): void
    {
        $id = $this->makeReport($this->site, ['note' => 'Ask us about the map pack.'])->id;

        $row = $this->send($id, ['recipients' => ['Owner@Karoo.co.za', 'ops@karoo.co.za'], 'message' => 'Hi Sam, as promised.']);

        $this->assertSame(['owner@karoo.co.za', 'ops@karoo.co.za'], $row['sent_to']);
        $this->assertSame(now()->toIso8601String(), $row['sent_at']);
        $this->assertSame(now()->addDays(90)->toIso8601String(), $row['share']['expires_at']);

        Mail::assertSent(ClientReportMail::class, 2);
        Mail::assertSent(ClientReportMail::class, function (ClientReportMail $mail) use ($row) {
            $envelope = $mail->envelope();
            $this->assertSame('Acme Digital', $envelope->from->name);
            $this->assertSame('reports@mailer.test', $envelope->from->address);
            $this->assertSame('thandi@acme.test', $envelope->replyTo[0]->address);
            $this->assertSame('Your October 2026 SEO report from Acme Digital', $envelope->subject);
            $this->assertSame($row['share']['url'], $mail->url);

            $attachment = $mail->attachments()[0];
            $this->assertSame('karoo-coffee-seo-report-october-2026.pdf', $attachment->as);
            $this->assertStringStartsWith('%PDF', $mail->pdf);

            $html = $mail->render();
            foreach (['Search traffic up 24% (3,720 clicks)', 'Ask us about the map pack.', 'Hi Sam, as promised.', 'The PDF is attached.', $row['share']['url'], 'Sent by Acme Digital for Karoo Coffee'] as $needle) {
                $this->assertStringContainsString($needle, $html);
            }
            $this->assertStringNotContainsStringIgnoringCase('indlela', $html.$envelope->subject.$envelope->from->name);

            return $mail->hasTo('owner@karoo.co.za') || $mail->hasTo('ops@karoo.co.za');
        });
    }

    public function test_an_existing_link_is_kept_and_extended(): void
    {
        $id = $this->makeReport($this->site)->id;
        $url = $this->actingAs($this->manager, 'sanctum')->postJson("/api/reports/{$id}/share", ['expires_in_days' => 7])->json('data.share.url');

        $row = $this->send($id, ['recipients' => ['owner@karoo.co.za']]);

        $this->assertSame($url, $row['share']['url']);
        $this->assertSame(now()->addDays(90)->toIso8601String(), $row['share']['expires_at']);
    }

    public function test_reply_to_falls_back_to_the_generator_then_the_owner(): void
    {
        $report = $this->makeReport($this->site, [], $this->manager);
        $this->inWorkspace($this->workspace(), fn () => app(Reports::class)->send($report, ['a@karoo.co.za']));
        Mail::assertSent(ClientReportMail::class, fn (ClientReportMail $m) => $m->envelope()->replyTo[0]->address === 'thandi@acme.test');

        $scheduled = $this->makeReport($this->site);
        $this->inWorkspace($this->workspace(), fn () => app(Reports::class)->send($scheduled, ['b@karoo.co.za']));
        $owner = $this->ownerOf($this->workspace());
        Mail::assertSent(ClientReportMail::class, fn (ClientReportMail $m) => $m->hasTo('b@karoo.co.za') && $m->envelope()->replyTo[0]->address === $owner->email);
    }

    public function test_send_to_at_creation_emails_once_ready(): void
    {
        $row = $this->actingAs($this->manager, 'sanctum')->postJson("/api/sites/{$this->site->id}/reports", [
            'period' => 'last_month', 'sections' => ['search_console'], 'send_to' => ['owner@karoo.co.za'],
        ])->assertStatus(202)->json('data');

        $this->assertSame(['ready', ['owner@karoo.co.za']], [$row['status'], $row['sent_to']]);
        Mail::assertSent(ClientReportMail::class, 1);
    }

    public function test_the_workspace_can_reword_it(): void
    {
        $this->inWorkspace($this->workspace(), fn () => EmailTemplate::query()->create([
            'type' => EmailTemplateType::ClientReport->value,
            'subject' => '{{client}}: results for {{month}}',
            'body' => '<p>Dear {{client}},</p>{{summary}}<p>Cheers, {{workspace}}</p>',
        ]));
        $id = $this->makeReport($this->site)->id;

        $this->send($id, ['recipients' => ['owner@karoo.co.za']]);

        Mail::assertSent(ClientReportMail::class, function (ClientReportMail $mail) {
            $this->assertSame('Karoo Coffee: results for October 2026', $mail->envelope()->subject);
            $this->assertStringContainsString('<li>Search traffic up 24% (3,720 clicks)</li>', $mail->rendered()['body']);
            $this->assertStringContainsString('Cheers, Acme Digital', $mail->rendered()['body']);

            return true;
        });
    }

    public function test_recipients_are_validated(): void
    {
        $id = $this->makeReport($this->site)->id;
        $as = $this->actingAs($this->manager, 'sanctum');

        $as->postJson("/api/reports/{$id}/send", ['recipients' => []])->assertJsonValidationErrors('recipients');
        $as->postJson("/api/reports/{$id}/send", ['recipients' => ['nope']])->assertJsonValidationErrors('recipients.0');
        $as->postJson("/api/reports/{$id}/send", ['recipients' => array_map(fn ($i) => "c{$i}@karoo.co.za", range(1, 11))])->assertJsonValidationErrors('recipients');
        Mail::assertNothingSent();
        $this->assertNull(Report::query()->withoutGlobalScopes()->find($id)->sent_at);
    }

    public function test_the_template_is_listed_in_settings_without_the_app_name(): void
    {
        $owner = $this->ownerOf($this->workspace());

        $template = $this->actingAs($owner, 'sanctum')->getJson('/api/email-templates/client_report')->assertOk()->json('data');

        $this->assertSame('Your {{month}} SEO report from {{workspace}}', $template['subject']);
        $this->assertNotContains('app_name', array_column($template['variables'], 'key'));
        $this->assertStringNotContainsString('app_name', $template['body']);
    }
}
