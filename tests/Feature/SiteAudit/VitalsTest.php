<?php

namespace Tests\Feature\SiteAudit;

use App\Models\SiteAudit;
use App\Services\SiteAudit\SiteAudits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\AuthTestHelpers;
use Tests\Support\SiteAuditHelpers;
use Tests\TestCase;

class VitalsTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase, SiteAuditHelpers;

    private const S = 'https://www.karoo.co.za';

    /** @var list<array<string, mixed>> query of every PageSpeed call */
    private array $psiCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->fakeWeb();
        $s = self::S;
        // Home links to /popular (3 inlinks), /b (2) and /c (1).
        $this->web = [
            "{$s}/sitemap.xml" => $this->sitemap(["{$s}/"]),
            "{$s}/" => $this->html(links: ['/popular', '/b', '/c'], options: ['canonical' => "{$s}/"]),
            "{$s}/popular" => $this->html(links: ['/b'], options: ['canonical' => "{$s}/popular"]),
            "{$s}/b" => $this->html(links: ['/popular', '/c'], options: ['canonical' => "{$s}/b"]),
            "{$s}/c" => $this->html(links: ['/popular'], options: ['canonical' => "{$s}/c"]),
        ];
    }

    private function audit(): SiteAudit
    {
        return app(SiteAudits::class)->start($this->auditSite(), null);
    }

    public function test_pagespeed_results_are_parsed_and_poor_pages_become_issues(): void
    {
        config(['services.pagespeed.key' => 'psi-key', 'indlela.audit.vitals.pages' => 1]);
        $field = ['overall_category' => 'SLOW', 'metrics' => [
            'LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => 4100, 'category' => 'SLOW'],
            'INTERACTION_TO_NEXT_PAINT' => ['percentile' => 180, 'category' => 'FAST'],
            'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 12, 'category' => 'AVERAGE'],
        ]];
        $this->psi = function (Request $request) use ($field) {
            $this->psiCalls[] = $request->data();

            return match ([$request['url'], $request['strategy']]) {
                [self::S.'/', 'mobile'] => Http::response($this->psiBody(92, $field)), // field data poor
                [self::S.'/popular', 'mobile'] => Http::response($this->psiBody(70)), // needs improvement
                default => Http::response($this->psiBody(97)),
            };
        };

        $audit = $this->audit();

        $this->assertSame(SiteAudit::VITALS_DONE, $audit->vitals_status);
        $this->assertNull($audit->vitals_message);
        $this->assertCount(4, $this->psiCalls, 'home + 1 most-linked page, mobile and desktop');
        $this->assertSame(['url' => self::S.'/', 'strategy' => 'mobile', 'category' => 'performance', 'key' => 'psi-key'], $this->psiCalls[0]);
        $this->assertSame(self::S.'/popular', $this->psiCalls[2]['url']);

        $home = $audit->vitals()->where('url', self::S.'/')->where('strategy', 'mobile')->first();
        $this->assertSame(92, $home->performance_score);
        $this->assertSame(['lcp_ms' => 2400, 'cls' => 0.051, 'tbt_ms' => 150, 'fcp_ms' => 1200, 'si_ms' => 3100], $home->lab);
        $this->assertSame(['lcp_ms' => 4100, 'lcp_category' => 'poor', 'inp_ms' => 180, 'inp_category' => 'good', 'cls' => 0.12,
            'cls_category' => 'needs_improvement', 'category' => 'poor', 'origin_fallback' => false], $home->field);
        $this->assertNull($audit->vitals()->where('url', self::S.'/')->where('strategy', 'desktop')->first()->field);

        $this->assertSame([self::S.'/'], $audit->issues()->where('type', 'poor_core_web_vitals')->pluck('url')->all());
        $this->assertSame([self::S.'/popular'], $audit->issues()->where('type', 'needs_improvement_core_web_vitals')->pluck('url')->all());
        $this->assertSame('mobile', $audit->issues()->where('type', 'poor_core_web_vitals')->first()->details['strategy']);
        $this->assertSame($audit->issues()->count(), $audit->fresh()->counts['total'], 're-counted after vitals');

        $this->actingAs($this->ownerOf($this->workspace()), 'sanctum')->getJson("/api/audits/{$audit->id}/vitals")
            ->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.url', self::S.'/')->assertJsonPath('data.0.strategy', 'mobile')
            ->assertJsonPath('data.0.performance_score', 92)->assertJsonPath('data.0.status', 'done')->assertJsonPath('data.0.field.lcp_category', 'poor');
    }

    public function test_quota_errors_leave_the_audit_done_with_vitals_unavailable(): void
    {
        $this->psi = fn () => Http::response(['error' => ['code' => 429, 'message' => 'Quota exceeded for quota metric']], 429);

        $audit = $this->audit();

        $this->assertSame(SiteAudit::DONE, $audit->status);
        $this->assertSame(SiteAudit::VITALS_UNAVAILABLE, $audit->vitals_status);
        $this->assertStringContainsString('quota', $audit->vitals_message);
        $this->assertStringContainsString('PAGESPEED_API_KEY', $audit->vitals_message);
        $this->assertSame('failed', $audit->vitals()->first()->status);
        $this->assertNotNull($audit->health_score);
        $this->assertFalse($audit->issues()->whereIn('type', ['poor_core_web_vitals', 'needs_improvement_core_web_vitals'])->exists());
    }

    public function test_some_failed_runs_still_count_as_done(): void
    {
        $this->psi = fn (Request $request) => $request['strategy'] === 'desktop'
            ? Http::response(['error' => ['message' => 'Lighthouse returned error: NO_FCP']], 500)
            : Http::response($this->psiBody(96));

        $audit = $this->audit();

        $this->assertSame(SiteAudit::VITALS_DONE, $audit->vitals_status);
        $this->assertSame('4 of 8 PageSpeed tests failed; the others are shown.', $audit->vitals_message);
        $this->assertStringContainsString('NO_FCP', $audit->vitals()->where('strategy', 'desktop')->value('error'));
    }

    public function test_vitals_issues_are_left_out_of_the_comparison_when_one_audit_has_none(): void
    {
        $site = $this->auditSite();
        $this->psi = fn () => Http::response($this->psiBody(30));
        $first = app(SiteAudits::class)->start($site, null);
        $this->assertTrue($first->issues()->where('type', 'poor_core_web_vitals')->exists());

        $this->psi = fn () => Http::response([], 429);
        $second = app(SiteAudits::class)->start($site, null);

        $this->assertSame(SiteAudit::VITALS_UNAVAILABLE, $second->vitals_status);
        $this->assertSame(0, $second->comparison['fixed'], 'a PageSpeed outage is not "fixed"');
    }
}
