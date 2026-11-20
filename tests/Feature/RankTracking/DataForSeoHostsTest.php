<?php

namespace Tests\Feature\RankTracking;

use App\Services\RankTracking\SerpApi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * In sandbox mode the billed SERP calls go to the sandbox, but the free
 * reference calls (location list, balance) go to the live host: the
 * sandbox's location list is a one-country sample with no South Africa.
 */
class DataForSeoHostsTest extends TestCase
{
    public function test_reference_calls_use_the_live_host_and_checks_follow_sandbox_mode(): void
    {
        config([
            'services.dataforseo.login' => 'agency@example.com',
            'services.dataforseo.password' => 'secret',
            'services.dataforseo.base_url' => 'https://sandbox.dataforseo.com',
            'services.dataforseo.live_url' => 'https://api.dataforseo.com',
        ]);

        $calls = [];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$calls) {
            $calls[parse_url($request->url(), PHP_URL_PATH)] = parse_url($request->url(), PHP_URL_HOST);

            return Http::response(['status_code' => 20000, 'status_message' => 'Ok.', 'tasks' => [[
                'id' => 't', 'status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0,
                'result' => [['money' => ['balance' => 12.5]]],
            ]]]);
        });

        $api = app(SerpApi::class);
        $api->locations();
        $this->assertSame(12.5, $api->balance());
        $api->postTasks([['keyword' => 'web design', 'location_code' => 2710, 'tag' => '1']]);

        $this->assertSame('api.dataforseo.com', $calls['/v3/serp/google/locations']);
        $this->assertSame('api.dataforseo.com', $calls['/v3/appendix/user_data']);
        $this->assertSame('sandbox.dataforseo.com', $calls['/v3/serp/google/organic/task_post']);
    }
}
