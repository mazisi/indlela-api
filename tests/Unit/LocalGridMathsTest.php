<?php

namespace Tests\Unit;

use App\Services\LocalGrid\GridGeometry;
use App\Services\LocalGrid\MapsParser;
use PHPUnit\Framework\TestCase;

/** The grid's points and reading a Maps SERP (pure). */
class LocalGridMathsTest extends TestCase
{
    public function test_points_run_north_to_south_and_west_to_east_around_the_centre(): void
    {
        $points = GridGeometry::points(-26.1076, 28.0567, 3, 1);

        $this->assertCount(9, $points);
        $this->assertSame(['row' => 1, 'col' => 1, 'lat' => -26.1076, 'lng' => 28.0567], $points[4]);
        // Row 0 is north (a higher latitude), col 0 is west (a lower longitude).
        $this->assertSame([0, 0], [$points[0]['row'], $points[0]['col']]);
        $this->assertEqualsWithDelta(-26.1076 + 1 / 110.574, $points[0]['lat'], 1e-7);
        $this->assertEqualsWithDelta(28.0567 - 1 / (111.320 * cos(deg2rad(-26.1076))), $points[0]['lng'], 1e-7);
        $this->assertSame([2, 2], [$points[8]['row'], $points[8]['col']]);
        $this->assertLessThan(-26.1076, $points[8]['lat']);
        $this->assertGreaterThan(28.0567, $points[8]['lng']);

        $seven = GridGeometry::points(0, 0, 7, 0.5);
        $this->assertCount(49, $seven);
        $this->assertEqualsWithDelta(1.5 / 110.574, $seven[0]['lat'], 1e-7);
        $this->assertEqualsWithDelta(-1.5 / 111.320, $seven[0]['lng'], 1e-7);
    }

    public function test_coordinates_have_at_most_seven_decimals_and_a_zoom(): void
    {
        $this->assertSame('-26.1076,28.0567,15z', GridGeometry::coordinate(-26.1076, 28.0567, 15));
        $this->assertSame('-26.1234568,28,13z', GridGeometry::coordinate(-26.123456789, 28.0, 13));
    }

    private function item(int $rank, ?string $place, ?string $cid, ?string $domain, string $type = 'maps_search'): array
    {
        return ['type' => $type, 'rank_group' => $rank, 'title' => "Biz {$rank}", 'place_id' => $place, 'cid' => $cid, 'domain' => $domain,
            'rating' => ['value' => 4.2, 'votes_count' => 12], 'address' => 'Somewhere', 'latitude' => -26.1, 'longitude' => 28.0, 'category' => 'Cafe'];
    }

    public function test_the_business_matches_by_place_id_then_cid_then_domain(): void
    {
        $result = ['items' => [
            $this->item(2, 'p2', 'c2', 'www.karoo.co.za'),
            $this->item(1, 'p1', 'c1', 'other.co.za'),
            $this->item(3, 'p3', 'c3', 'karoo.co.za'),
            $this->item(4, 'p4', 'c4', null, 'paid_item'),
        ]];

        $byPlace = MapsParser::parse($result, ['place_id' => 'p3', 'cid' => 'c2', 'domain' => 'karoo.co.za'], 20);
        $this->assertSame(3, $byPlace['rank'], 'the place id wins over the cid and domain');
        $this->assertSame([1, 2, 3], array_column($byPlace['top'], 'rank'));
        $this->assertCount(3, $byPlace['results'], 'only maps_search items');
        $this->assertSame('karoo.co.za', $byPlace['results'][1]['domain'], 'www. dropped');

        $this->assertSame(2, MapsParser::parse($result, ['place_id' => null, 'cid' => 'c2', 'domain' => null], 20)['rank']);
        $this->assertSame(2, MapsParser::parse($result, ['place_id' => null, 'cid' => null, 'domain' => 'https://www.karoo.co.za/'], 20)['rank']);
        $this->assertNull(MapsParser::parse($result, ['place_id' => 'nope', 'cid' => null, 'domain' => 'karoo.co.za'], 20)['rank'], 'an id never falls back to the domain');
        $this->assertNull(MapsParser::parse($result, ['place_id' => 'p3', 'cid' => null, 'domain' => null], 2)['rank'], 'beyond the depth');
        $this->assertSame(['rank' => null, 'top' => [], 'results' => []], MapsParser::parse(null, ['place_id' => 'p1', 'cid' => null, 'domain' => null], 20));
    }

    public function test_candidates_are_the_listings_in_order(): void
    {
        $candidates = MapsParser::candidates(['items' => [$this->item(1, 'p1', '123', 'www.a.co.za'), $this->item(2, null, null, null)]], 1);

        $this->assertSame([[
            'title' => 'Biz 1', 'address' => 'Somewhere', 'place_id' => 'p1', 'cid' => '123', 'lat' => -26.1, 'lng' => 28.0,
            'rating' => 4.2, 'votes' => 12, 'category' => 'Cafe', 'domain' => 'a.co.za',
        ]], $candidates);
    }
}
