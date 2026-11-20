<?php

namespace App\Services\RankTracking;

use App\Exceptions\DataForSeoException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * DataForSEO's v3 API over HTTP Basic auth (config/services.php dataforseo;
 * the sandbox host answers with sample data for free).
 *
 * Every answer is an envelope: `status_code` 20000 = OK, then one entry per
 * task in `tasks` with its own `status_code` (20000 done, 20100 created,
 * 40601/40602 still queued, 4xxxx/5xxxx errors) and `result`. HTTP 429/5xx
 * and connection errors are retried twice before giving up (a live check's
 * timeout is not: it may have been billed).
 */
class DataForSeoSerpApi implements SerpApi
{
    private const ORGANIC = '/v3/serp/google/organic';

    private const MAPS = '/v3/serp/google/maps';

    public function configured(): bool
    {
        return filled(config('services.dataforseo.login')) && filled(config('services.dataforseo.password'));
    }

    public function postTasks(array $tasks): array
    {
        $body = $this->call('post', self::ORGANIC.'/task_post', array_values($tasks), 'the rank checks');

        return array_map(fn (array $task) => [
            'id' => $task['id'] ?? null,
            'status_code' => (int) ($task['status_code'] ?? 0),
            'status_message' => (string) ($task['status_message'] ?? ''),
            'tag' => isset($task['data']['tag']) ? (string) $task['data']['tag'] : null,
            'cost' => (float) ($task['cost'] ?? 0),
        ], $body['tasks'] ?? []);
    }

    public function tasksReady(): array
    {
        $body = $this->call('get', self::ORGANIC.'/tasks_ready', null, 'the list of finished checks');

        $ready = [];
        foreach ($body['tasks'] ?? [] as $task) {
            foreach ($task['result'] ?? [] as $row) {
                if (! empty($row['id'])) {
                    $ready[] = ['id' => (string) $row['id'], 'tag' => isset($row['tag']) ? (string) $row['tag'] : null];
                }
            }
        }

        return $ready;
    }

    public function taskGet(string $id): array
    {
        $body = $this->call('get', self::ORGANIC.'/task_get/advanced/'.rawurlencode($id), null, 'a rank check result');

        return $this->firstTask($body);
    }

    public function live(array $task): array
    {
        // A live SERP takes seconds, up to DataForSEO's own 120 s limit.
        // A live check that timed out may still be billed: never sent twice.
        return $this->firstTask($this->call('post', self::ORGANIC.'/live/advanced', [$task], 'the live check', (int) config('indlela.rank_tracking.live_timeout'), retryTimeouts: false));
    }

    /**
     * Each task its own request, all at once (Http::pool). Not retried: a
     * live call is billed even when it times out.
     */
    public function mapsLive(array $tasks): array
    {
        if ($tasks === []) {
            return [];
        }
        $timeout = (int) config('indlela.local_grid.live_timeout');
        $responses = Http::pool(function (Pool $pool) use ($tasks, $timeout) {
            foreach ($tasks as $key => $task) {
                $this->configure($pool->as((string) $key), $timeout)->post(self::MAPS.'/live/advanced', [$task]);
            }
        });

        $out = [];
        foreach (array_keys($tasks) as $key) {
            $response = $responses[(string) $key] ?? null;
            try {
                if (! $response instanceof Response) {
                    throw new DataForSeoException('DataForSEO did not answer for a map check'.($response instanceof Throwable ? ": {$response->getMessage()}" : '.'));
                }
                $out[$key] = $this->firstTask($this->envelope($response, 'a map check'));
            } catch (DataForSeoException $e) {
                $out[$key] = ['id' => null, 'status_code' => 0, 'status_message' => $e->getMessage(), 'cost' => 0.0, 'result' => null];
            }
        }

        return $out;
    }

    /**
     * Always from the live host, even in sandbox mode: the list is free, and
     * the sandbox only returns a one-country sample (no South Africa).
     */
    public function locations(): array
    {
        $body = $this->call('get', '/v3/serp/google/locations', null, 'the location list', 120, live: true);

        return $body['tasks'][0]['result'] ?? [];
    }

    /** The real account balance (free call), also from the live host. */
    public function balance(): ?float
    {
        $body = $this->call('get', '/v3/appendix/user_data', null, 'the account balance', live: true);
        $balance = $body['tasks'][0]['result'][0]['money']['balance'] ?? null;

        return $balance === null ? null : (float) $balance;
    }

    /** @return array{id: ?string, status_code: int, status_message: string, cost: float, result: ?array<string, mixed>} */
    private function firstTask(array $body): array
    {
        $task = $body['tasks'][0] ?? [];

        return [
            'id' => $task['id'] ?? null,
            'status_code' => (int) ($task['status_code'] ?? 0),
            'status_message' => (string) ($task['status_message'] ?? 'No task in the answer.'),
            'cost' => (float) ($task['cost'] ?? 0),
            'result' => $task['result'][0] ?? null,
        ];
    }

    /** One request: the body of a successful envelope, else a DataForSeoException. */
    private function call(string $method, string $path, ?array $body, string $what, int $timeout = 30, bool $retryTimeouts = true, bool $live = false): array
    {
        try {
            $http = $this->http($timeout, $retryTimeouts, $live);
            $response = $method === 'post' ? $http->post($path, $body) : $http->get($path);
        } catch (ConnectionException $e) {
            throw new DataForSeoException("DataForSEO did not answer for {$what}: {$e->getMessage()}");
        }

        return $this->envelope($response, $what);
    }

    /** The body of a successful envelope, else a DataForSeoException. */
    private function envelope(Response $response, string $what): array
    {
        if (! $response->successful()) {
            $code = (int) ($response->json('status_code') ?? 0);
            $message = (string) ($response->json('status_message') ?? $response->reason());

            throw new DataForSeoException("DataForSEO refused {$what}: {$message} (HTTP {$response->status()}).", $code ?: null,
                in_array($response->status(), [408, 429, 500, 502, 503, 504], true));
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new DataForSeoException("DataForSEO sent an unreadable answer for {$what}.");
        }
        $code = (int) ($body['status_code'] ?? 0);
        if ($code !== 20000) {
            throw DataForSeoException::fromStatus($code, (string) ($body['status_message'] ?? 'unknown error'), $what);
        }

        return $body;
    }

    private function http(int $timeout, bool $retryTimeouts, bool $live = false): PendingRequest
    {
        return $this->configure(Http::createPendingRequest(), $timeout, $live)
            ->retry(3, 2000, fn (Throwable $e) => ($retryTimeouts && $e instanceof ConnectionException)
                || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 504], true)), throw: false);
    }

    /** Host, Basic auth, JSON and timeout on a request. */
    private function configure(PendingRequest $request, int $timeout, bool $live = false): PendingRequest
    {
        $base = $live ? config('services.dataforseo.live_url') : config('services.dataforseo.base_url');

        return $request->baseUrl(rtrim((string) $base, '/'))
            ->withBasicAuth((string) config('services.dataforseo.login'), (string) config('services.dataforseo.password'))
            ->acceptJson()->asJson()->timeout($timeout);
    }
}
