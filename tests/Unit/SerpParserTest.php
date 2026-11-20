<?php

namespace Tests\Unit;

use App\Services\RankTracking\SerpParser;
use PHPUnit\Framework\TestCase;

class SerpParserTest extends TestCase
{
    private function organic(int $rank, string $url, ?string $domain = null): array
    {
        return ['type' => 'organic', 'rank_group' => $rank, 'rank_absolute' => $rank + 3, 'url' => $url, 'domain' => $domain ?? parse_url($url, PHP_URL_HOST)];
    }

    public function test_best_organic_position_url_and_every_matching_url(): void
    {
        $result = ['se_results_count' => 4200000, 'items' => [
            ['type' => 'paid', 'rank_group' => 1, 'url' => 'https://karoo.co.za/ad', 'domain' => 'karoo.co.za'],
            $this->organic(1, 'https://rival.co.za/'),
            $this->organic(2, 'https://karoo.co.za/coffee'),          // apex = www
            $this->organic(3, 'https://www.karoo.co.za/beans'),
            $this->organic(7, 'https://shop.karoo.co.za/beans'),      // subdomain counts
            $this->organic(9, 'https://www.karoo.co.za/beans'),       // same URL again: best kept
            $this->organic(10, 'https://notkaroo.co.za/'),            // suffix without a dot: not ours
            $this->organic(101, 'https://www.karoo.co.za/deep'),      // beyond the depth
        ]];

        $parsed = SerpParser::parse($result, 'karoo.co.za');

        $this->assertSame(2, $parsed['position']);
        $this->assertSame('https://karoo.co.za/coffee', $parsed['url']);
        $this->assertSame([
            ['url' => 'https://karoo.co.za/coffee', 'position' => 2],
            ['url' => 'https://www.karoo.co.za/beans', 'position' => 3],
            ['url' => 'https://shop.karoo.co.za/beans', 'position' => 7],
        ], $parsed['matching_urls']);
        $this->assertSame(4200000, $parsed['result_count']);
    }

    public function test_not_in_the_top_100_and_an_empty_result(): void
    {
        $parsed = SerpParser::parse(['items' => [$this->organic(1, 'https://rival.co.za/')]], 'www.karoo.co.za');
        $this->assertNull($parsed['position']);
        $this->assertNull($parsed['url']);
        $this->assertSame([], $parsed['matching_urls']);

        $empty = SerpParser::parse(null, 'karoo.co.za');
        $this->assertNull($empty['position']);
        $this->assertFalse($empty['serp_features']['featured_snippet']['present']);
        $this->assertNull($empty['result_count']);
    }

    public function test_serp_features_and_whether_the_site_is_in_them(): void
    {
        $result = ['items' => [
            ['type' => 'featured_snippet', 'rank_group' => 1, 'url' => 'https://www.karoo.co.za/guide', 'domain' => 'www.karoo.co.za'],
            ['type' => 'local_pack', 'rank_group' => 1, 'title' => 'Rival Roasters', 'domain' => null, 'url' => null],
            ['type' => 'local_pack', 'rank_group' => 2, 'title' => 'Karoo Coffee', 'domain' => 'karoo.co.za', 'url' => 'https://karoo.co.za/'],
            ['type' => 'ai_overview', 'rank_group' => 1, 'items' => [
                ['type' => 'ai_overview_element', 'text' => '…', 'references' => [
                    ['type' => 'ai_overview_reference', 'source' => 'Karoo', 'domain' => 'rival.co.za', 'url' => 'https://rival.co.za/x'],
                ], 'links' => [['url' => 'https://www.karoo.co.za/beans']]],
            ]],
            ['type' => 'people_also_ask', 'rank_group' => 1, 'items' => [['type' => 'people_also_ask_element', 'title' => 'Q?']]],
            ['type' => 'top_stories', 'rank_group' => 1, 'items' => []],
            $this->organic(4, 'https://www.karoo.co.za/'),
        ]];

        $features = SerpParser::parse($result, 'karoo.co.za')['serp_features'];

        $this->assertSame(['present' => true, 'ours' => true], $features['featured_snippet']);
        $this->assertSame(['present' => true, 'ours' => true], $features['local_pack']);
        $this->assertSame(['present' => true, 'ours' => true], $features['ai_overview']);
        $this->assertSame(['present' => true], $features['people_also_ask']);
        $this->assertSame(['present' => true], $features['top_stories']);
        $this->assertSame(['present' => false], $features['video']);
        $this->assertSame(['present' => false], $features['images']);
    }

    public function test_features_not_ours_and_an_ai_overview_inside_the_knowledge_graph(): void
    {
        $result = ['items' => [
            ['type' => 'featured_snippet', 'url' => 'https://rival.co.za/guide', 'domain' => 'rival.co.za'],
            ['type' => 'knowledge_graph', 'items' => [
                ['type' => 'knowledge_graph_ai_overview_item', 'items' => [], 'references' => [['domain' => 'rival.co.za', 'url' => 'https://rival.co.za/']]],
            ]],
            ['type' => 'video', 'items' => [['url' => 'https://youtube.com/watch?v=1']]],
            ['type' => 'images', 'items' => []],
        ]];

        $features = SerpParser::parse($result, 'karoo.co.za')['serp_features'];

        $this->assertSame(['present' => true, 'ours' => false], $features['featured_snippet']);
        $this->assertSame(['present' => false, 'ours' => false], $features['local_pack']);
        $this->assertSame(['present' => true, 'ours' => false], $features['ai_overview']);
        $this->assertTrue($features['video']['present']);
        $this->assertTrue($features['images']['present']);

        $ours = SerpParser::parse(['items' => [['type' => 'knowledge_graph', 'items' => [
            ['type' => 'knowledge_graph_ai_overview_item', 'references' => [['domain' => 'www.karoo.co.za']]],
        ]]]], 'karoo.co.za')['serp_features'];
        $this->assertTrue($ours['ai_overview']['ours']);
    }
}
