<?php

namespace App\Services\SiteAudit;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google PageSpeed Insights v5 (Lighthouse lab data + CrUX field data). The
 * API key (services.pagespeed.key) is optional; without it Google's shared
 * quota applies.
 */
class PageSpeedInsights
{
    public const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /**
     * Runs every [url, strategy] pair, a few at a time.
     *
     * @param  list<array{0: string, 1: string}>  $runs
     * @return list<array{status: string, performance_score: int|null, lab: array<string, mixed>|null, field: array<string, mixed>|null, error: string|null, quota: bool}>
     */
    public function run(array $runs): array
    {
        if ($runs === []) {
            return [];
        }

        $key = config('services.pagespeed.key');
        $responses = Http::pool(function (Pool $pool) use ($runs, $key) {
            foreach ($runs as $i => [$url, $strategy]) {
                $pool->as((string) $i)->acceptJson()->timeout((int) config('indlela.audit.vitals.timeout'))
                    ->get(self::ENDPOINT, array_filter(['url' => $url, 'strategy' => $strategy, 'category' => 'performance', 'key' => $key ?: null]));
            }
        }, max(1, (int) config('indlela.audit.vitals.concurrency')));

        $out = [];
        foreach (array_keys($runs) as $i) {
            $response = $responses[(string) $i] ?? $responses[$i] ?? null;
            $out[] = $response instanceof Response ? $this->parse($response) : self::failure('PageSpeed Insights did not answer in time.');
        }

        return $out;
    }

    /** @return array{status: string, performance_score: int|null, lab: array<string, mixed>|null, field: array<string, mixed>|null, error: string|null, quota: bool} */
    public function parse(Response $response): array
    {
        if ($response->status() === 429 || ($response->status() === 403 && str_contains(strtolower((string) $response->json('error.message')), 'quota'))) {
            return self::failure('Google\'s PageSpeed Insights quota is used up for now. Add a PAGESPEED_API_KEY (or wait a day) and run the audit again.', true);
        }
        if (! $response->successful()) {
            $reason = $response->json('error.message') ?? $response->reason();

            return self::failure(mb_substr('PageSpeed Insights could not test this page: '.(is_string($reason) ? $reason : 'unknown error'), 0, 500));
        }

        $score = $response->json('lighthouseResult.categories.performance.score');
        $audit = fn (string $id) => $response->json("lighthouseResult.audits.{$id}.numericValue");
        $ms = fn ($v) => $v === null ? null : (int) round((float) $v);

        return [
            'status' => 'done',
            'performance_score' => $score === null ? null : (int) round($score * 100),
            'lab' => [
                'lcp_ms' => $ms($audit('largest-contentful-paint')),
                'cls' => $audit('cumulative-layout-shift') === null ? null : round((float) $audit('cumulative-layout-shift'), 3),
                'tbt_ms' => $ms($audit('total-blocking-time')),
                'fcp_ms' => $ms($audit('first-contentful-paint')),
                'si_ms' => $ms($audit('speed-index')),
            ],
            'field' => $this->field($response->json('loadingExperience')),
            'error' => null,
            'quota' => false,
        ];
    }

    /**
     * CrUX (real visitors, 75th percentile) from loadingExperience, or null when
     * Google has too little traffic data for the URL and its origin.
     *
     * @return array<string, mixed>|null
     */
    private function field(mixed $experience): ?array
    {
        $metrics = is_array($experience) ? ($experience['metrics'] ?? []) : [];
        if ($metrics === []) {
            return null;
        }

        $metric = fn (string $key) => $metrics[$key] ?? null;
        $lcp = $metric('LARGEST_CONTENTFUL_PAINT_MS');
        $inp = $metric('INTERACTION_TO_NEXT_PAINT');
        $cls = $metric('CUMULATIVE_LAYOUT_SHIFT_SCORE');

        return [
            'lcp_ms' => $lcp['percentile'] ?? null,
            'lcp_category' => self::category($lcp['category'] ?? null),
            'inp_ms' => $inp['percentile'] ?? null,
            'inp_category' => self::category($inp['category'] ?? null),
            // CrUX reports CLS × 100.
            'cls' => isset($cls['percentile']) ? round($cls['percentile'] / 100, 2) : null,
            'cls_category' => self::category($cls['category'] ?? null),
            'category' => self::category($experience['overall_category'] ?? null),
            'origin_fallback' => (bool) ($experience['origin_fallback'] ?? false),
        ];
    }

    /** FAST / AVERAGE / SLOW → good / needs_improvement / poor. */
    private static function category(?string $value): ?string
    {
        return match ($value) {
            'FAST' => 'good',
            'AVERAGE' => 'needs_improvement',
            'SLOW' => 'poor',
            default => null,
        };
    }

    /** @return array{status: string, performance_score: null, lab: null, field: null, error: string, quota: bool} */
    private static function failure(string $message, bool $quota = false): array
    {
        return ['status' => 'failed', 'performance_score' => null, 'lab' => null, 'field' => null, 'error' => $message, 'quota' => $quota];
    }
}
