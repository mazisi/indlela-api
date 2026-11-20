<?php

namespace App\Services\SiteAudit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Raw HTML over Laravel's Http client: `audit.concurrency` requests at a time
 * (Http::pool), IndlelaBot user agent, per-request timeout, redirects not
 * followed (the crawler records each hop). Bodies declared larger than
 * `audit.max_body_bytes` are not downloaded.
 */
class HttpPageFetcher implements PageFetcher
{
    public function fetch(array $urls, string $method = 'GET'): array
    {
        if ($urls === []) {
            return [];
        }

        $keys = array_keys($urls);
        $max = (int) config('indlela.audit.max_body_bytes');
        $responses = Http::pool(function (Pool $pool) use ($urls, $method, $max) {
            foreach (array_values($urls) as $i => $url) {
                $this->configure($pool->as((string) $i))
                    ->withoutRedirecting()
                    ->withOptions(['on_headers' => function (ResponseInterface $response) use ($max) {
                        if ((int) $response->getHeaderLine('Content-Length') > $max) {
                            throw new RuntimeException('Response too large to audit.');
                        }
                    }])
                    ->send($method, $url);
            }
        }, $this->concurrency());

        $out = [];
        foreach ($keys as $i => $key) {
            $out[$key] = $this->toResult($responses[(string) $i] ?? $responses[$i] ?? null, $max);
        }

        return $out;
    }

    public function statuses(array $urls): array
    {
        $out = [];
        foreach ($this->check($urls, 'HEAD') as $key => $status) {
            $out[$key] = $status;
        }

        // Plenty of servers refuse or mishandle HEAD: ask again with GET (body not read).
        $retry = array_filter($urls, fn ($url, $key) => $out[$key] === 0 || $out[$key] >= 400, ARRAY_FILTER_USE_BOTH);
        foreach ($this->check($retry, 'GET') as $key => $status) {
            $out[$key] = $status;
        }

        return $out;
    }

    /**
     * @param  array<int|string, string>  $urls
     * @return array<int|string, int>
     */
    private function check(array $urls, string $method): array
    {
        if ($urls === []) {
            return [];
        }

        $keys = array_keys($urls);
        $responses = Http::pool(function (Pool $pool) use ($urls, $method) {
            foreach (array_values($urls) as $i => $url) {
                $this->configure($pool->as((string) $i))
                    ->withOptions(['allow_redirects' => ['max' => 5], 'stream' => true])
                    ->send($method, $url);
            }
        }, $this->concurrency() * 2);

        $out = [];
        foreach ($keys as $i => $key) {
            $response = $responses[(string) $i] ?? $responses[$i] ?? null;
            $out[$key] = $response instanceof Response ? $response->status() : 0;
            if ($response instanceof Response) {
                $response->close();
            }
        }

        return $out;
    }

    private function configure(PendingRequest $request): PendingRequest
    {
        return $request
            ->withUserAgent(self::userAgent())
            ->withHeaders(['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'])
            ->timeout((int) config('indlela.audit.timeout'))
            ->connectTimeout(min(5, (int) config('indlela.audit.timeout')));
    }

    public static function userAgent(): string
    {
        return config('indlela.audit.user_agent').' (+'.rtrim((string) config('app.url'), '/').')';
    }

    private function concurrency(): int
    {
        return max(1, (int) config('indlela.audit.concurrency'));
    }

    private function toResult(mixed $response, int $max): FetchResult
    {
        if (! $response instanceof Response) {
            $message = $response instanceof Throwable ? $response->getMessage() : 'No response';
            if ($response instanceof ConnectionException && preg_match('/timed out|timeout/i', $message)) {
                $message = 'Timed out';
            } elseif (str_contains($message, 'too large')) {
                $message = 'Response too large to audit.';
            }

            return FetchResult::failed(mb_substr($message, 0, 500));
        }

        $headers = [];
        foreach ($response->headers() as $name => $values) {
            $headers[strtolower($name)] = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
        }

        $body = $response->body();
        $time = $response->handlerStats()['total_time'] ?? null;

        return new FetchResult(
            $response->status(),
            $headers,
            strlen($body) > $max ? null : $body,
            $time !== null ? (int) round($time * 1000) : null,
        );
    }
}
