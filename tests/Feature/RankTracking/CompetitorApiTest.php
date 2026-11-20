<?php

namespace Tests\Feature\RankTracking;

use App\Models\RankCompetitorPosition;
use App\Models\RankKeyword;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCompetitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthTestHelpers;
use Tests\Support\RankTrackingHelpers;
use Tests\TestCase;

/** Adding, changing and removing a site's competitors, their alert recipients, and key keywords. */
class CompetitorApiTest extends TestCase
{
    use AuthTestHelpers, RankTrackingHelpers, RefreshDatabase;

    private Site $site;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->travelTo('2026-11-20 10:00:00');
        $this->site = $this->rankSite();
        $this->manager = $this->makeUser(Role::MANAGER);
    }

    private function add(array $body)
    {
        return $this->actingAs($this->manager, 'sanctum')->postJson("/api/sites/{$this->site->id}/competitors", $body);
    }

    public function test_a_url_is_kept_as_its_bare_host_and_backfilled_from_recent_checks(): void
    {
        $keyword = $this->makeKeyword($this->site);
        $this->addCheck($keyword, '2026-11-19', 4, ['organic_domains' => $this->hosts([1 => 'www.rival.co.za', 2 => 'shop.rival.co.za', 4 => 'karoo.co.za'])]);
        $this->addCheck($keyword, '2026-11-18', 5, ['organic_domains' => $this->hosts([5 => 'karoo.co.za'])]);
        $this->addCheck($keyword, '2026-11-17', 6); // before competitor tracking: no hosts
        $this->addCheck($keyword, '2026-07-01', 6, ['organic_domains' => $this->hosts([1 => 'rival.co.za'])]); // older than 90 days

        $response = $this->add(['domain' => 'https://WWW.Rival.co.za/shop?x=1'])->assertCreated();

        $response->assertJsonPath('data.domain', 'rival.co.za')
            ->assertJsonPath('data.name', 'rival.co.za')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.has_history', true)
            ->assertJsonPath('data.latest', ['top10' => 1, 'top3' => 1, 'ranked' => 1, 'avg_position' => 1, 'sov' => 50]);
        $competitor = SiteCompetitor::query()->sole();
        $this->assertSame($this->manager->id, $competitor->created_by);
        $this->assertSame($this->site->workspace_id, $competitor->workspace_id);
        // www. is stripped from hosts already; a subdomain counts, the best position wins.
        $this->assertSame(['2026-11-18' => null, '2026-11-19' => 1], RankCompetitorPosition::query()->orderBy('checked_on')->get()
            ->mapWithKeys(fn ($p) => [$p->checked_on->toDateString() => $p->position])->all());

        $this->add(['domain' => 'gamma.co.za', 'name' => '  Gamma Coffee '])->assertCreated()->assertJsonPath('data.name', 'Gamma Coffee');
    }

    public function test_invalid_own_and_duplicate_domains_are_refused(): void
    {
        $this->add(['domain' => 'not a domain'])->assertUnprocessable()->assertJsonValidationErrors(['domain' => 'Enter a website address']);
        $this->add(['domain' => 'https://www.karoo.co.za/about'])->assertUnprocessable()->assertJsonValidationErrors(['domain' => 'own domain']);
        $this->add(['domain' => 'shop.karoo.co.za'])->assertUnprocessable()->assertJsonValidationErrors(['domain' => 'own domain']);
        $this->add(['domain' => 'rival.co.za'])->assertCreated();
        $this->add(['domain' => 'www.rival.co.za'])->assertUnprocessable()->assertJsonValidationErrors(['domain' => 'already tracks']);
        // A different apex that merely ends the same way isn't the site.
        $this->add(['domain' => 'notkaroo.co.za'])->assertCreated();
    }

    public function test_at_most_five_active_competitors_a_site(): void
    {
        foreach (range(1, 5) as $i) {
            $this->add(['domain' => "rival{$i}.co.za"])->assertCreated();
        }
        $this->add(['domain' => 'rival6.co.za'])->assertUnprocessable()->assertJsonValidationErrors(['domain' => 'up to 5 competitors']);

        $first = SiteCompetitor::query()->where('domain', 'rival1.co.za')->sole();
        $as = $this->actingAs($this->manager, 'sanctum');
        $as->putJson("/api/competitors/{$first->id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.latest', null);
        $this->add(['domain' => 'rival6.co.za'])->assertCreated();
        $as->putJson("/api/competitors/{$first->id}", ['is_active' => true])->assertUnprocessable()->assertJsonValidationErrors(['is_active' => 'up to 5 competitors']);

        // Another site has its own five.
        $other = $this->rankSite(url: 'https://other.co.za');
        $as->postJson("/api/sites/{$other->id}/competitors", ['domain' => 'rival1.co.za'])->assertCreated();
    }

    public function test_rename_remove_and_add_again(): void
    {
        $id = $this->add(['domain' => 'rival.co.za'])->json('data.id');
        $as = $this->actingAs($this->manager, 'sanctum');

        $as->putJson("/api/competitors/{$id}", ['name' => 'Rival Roasters'])->assertOk()->assertJsonPath('data.name', 'Rival Roasters');
        $as->putJson("/api/competitors/{$id}", ['name' => ''])->assertOk()->assertJsonPath('data.name', 'rival.co.za');
        $as->putJson("/api/competitors/{$id}", ['domain' => 'other.co.za'])->assertOk()->assertJsonPath('data.domain', 'rival.co.za');

        $as->deleteJson("/api/competitors/{$id}")->assertNoContent();
        $this->assertSoftDeleted('site_competitors', ['id' => $id]);
        $as->getJson("/api/sites/{$this->site->id}/competitors")->assertOk()->assertJsonCount(0, 'data');
        $this->add(['domain' => 'rival.co.za'])->assertCreated();
    }

    public function test_alert_recipients(): void
    {
        $as = $this->actingAs($this->manager, 'sanctum');
        $url = "/api/sites/{$this->site->id}/competitor-settings";

        $as->getJson($url)->assertOk()->assertExactJson(['data' => ['alert_recipients' => []]]);
        $as->putJson($url, ['alert_recipients' => ['a@karoo.co.za', ' A@karoo.co.za', 'b@karoo.co.za']])->assertOk()
            ->assertExactJson(['data' => ['alert_recipients' => ['a@karoo.co.za', 'b@karoo.co.za']]]);
        $as->getJson($url)->assertJsonPath('data.alert_recipients', ['a@karoo.co.za', 'b@karoo.co.za']);

        $as->putJson($url, ['alert_recipients' => ['nope']])->assertUnprocessable()->assertJsonValidationErrors('alert_recipients.0');
        $as->putJson($url, ['alert_recipients' => array_map(fn ($i) => "p{$i}@karoo.co.za", range(1, 11))])->assertUnprocessable()->assertJsonValidationErrors('alert_recipients');
        $as->putJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('alert_recipients');
        $as->putJson($url, ['alert_recipients' => []])->assertOk()->assertJsonPath('data.alert_recipients', []);
    }

    public function test_key_keywords_on_the_keyword_row_update_bulk_and_filter(): void
    {
        $a = $this->makeKeyword($this->site, 'coffee beans');
        $b = $this->makeKeyword($this->site, 'espresso');
        $c = $this->makeKeyword($this->site, 'tea');
        $as = $this->actingAs($this->manager, 'sanctum');

        $as->getJson("/api/sites/{$this->site->id}/rank-keywords")->assertOk()->assertJsonPath('data.0.is_key', false);
        $as->putJson("/api/rank-keywords/{$a->id}", ['is_key' => true])->assertOk()->assertJsonPath('data.is_key', true);
        $as->postJson("/api/sites/{$this->site->id}/rank-keywords/bulk", ['action' => 'mark_key', 'ids' => [$b->id, $c->id]])->assertOk()->assertExactJson(['affected' => 2]);
        $as->postJson("/api/sites/{$this->site->id}/rank-keywords/bulk", ['action' => 'unmark_key', 'ids' => [$c->id]])->assertOk();

        $this->assertSame([true, true, false], [$a->fresh()->is_key, $b->fresh()->is_key, $c->fresh()->is_key]);
        $this->assertSame(['coffee beans', 'espresso'], array_column($as->getJson("/api/sites/{$this->site->id}/rank-keywords?is_key=1")->assertOk()->json('data'), 'keyword'));
        $this->assertSame(['tea'], array_column($as->getJson("/api/sites/{$this->site->id}/rank-keywords?is_key=0")->assertOk()->json('data'), 'keyword'));
        $this->assertTrue(RankKeyword::query()->whereKey($a->id)->value('is_key'));
    }
}
