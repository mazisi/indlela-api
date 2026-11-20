<?php

namespace App\Services\RankTracking;

use App\Exceptions\RankTrackingException;
use App\Jobs\RefreshRankLocations;
use App\Models\RankLocation;
use Illuminate\Support\Facades\DB;

/**
 * DataForSEO's Google locations (countries, regions, cities…), downloaded
 * into rank_locations on first use and again after
 * rank_tracking.locations_refresh_days, then searched locally. Suburbs
 * DataForSEO doesn't list are tracked as coordinates instead.
 */
class RankLocations
{
    private const TYPE_ORDER = ['Country' => 0, 'State' => 1, 'Region' => 1, 'Province' => 1, 'City' => 2, 'Municipality' => 3, 'Neighborhood' => 4];

    public function __construct(private readonly SerpApi $api, private readonly RankChecker $checker) {}

    /**
     * The 20 best matches: names starting with the search first, then the
     * home country's (ZA), then countries, regions, cities, then shorter names.
     *
     * @return list<array{code: int, name: string, type: ?string, country_iso: ?string}>
     */
    public function search(?string $search): array
    {
        $this->checker->ensureConfigured();
        $this->queueRefreshIfStale();

        $term = mb_strtolower(trim((string) $search));
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
        $home = (string) config('indlela.rank_tracking.home_country');
        $types = 'CASE type';
        foreach (self::TYPE_ORDER as $type => $rank) {
            $types .= " WHEN '{$type}' THEN {$rank}";
        }
        $types .= ' ELSE 9 END';

        return RankLocation::query()
            ->when($term !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ["%{$escaped}%"])
                ->orderByRaw('CASE WHEN LOWER(name) LIKE ? THEN 0 ELSE 1 END', ["{$escaped}%"]),
                fn ($q) => $q->whereIn('type', ['Country', 'Region', 'State', 'Province'])->where('country_iso', $home))
            ->orderByRaw('CASE WHEN country_iso = ? THEN 0 ELSE 1 END', [$home])
            ->orderByRaw($types)
            ->orderByRaw('LENGTH(name)')
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (RankLocation $l) => ['code' => $l->code, 'name' => self::displayName($l->name), 'type' => $l->type, 'country_iso' => $l->country_iso])
            ->all();
    }

    /** "Sandton,Gauteng,South Africa" → "Sandton, Gauteng, South Africa". */
    public static function displayName(string $name): string
    {
        return preg_replace('/\s*,\s*/', ', ', trim($name));
    }

    /**
     * Never downloads during a request (the list is ~270k places: minutes and
     * ~400 MB). Empty → queue the download and answer 409 locations_loading;
     * stale → queue a refresh and search the current list meanwhile.
     */
    public function queueRefreshIfStale(): void
    {
        $fetched = RankLocation::query()->max('fetched_at');
        if ($fetched === null) {
            RefreshRankLocations::dispatch();

            throw RankTrackingException::locationsLoading();
        }

        if ($this->isStale($fetched)) {
            RefreshRankLocations::dispatch();
        }
    }

    /** For the monthly schedule: refresh only when the list is missing or stale. */
    public function refreshIfStale(): ?int
    {
        $fetched = RankLocation::query()->max('fetched_at');

        return ($fetched === null || $this->isStale($fetched)) ? $this->refresh() : null;
    }

    private function isStale(mixed $fetched): bool
    {
        return now()->subDays((int) config('indlela.rank_tracking.locations_refresh_days'))->gte($fetched);
    }

    /** @return int locations stored */
    public function refresh(): int
    {
        // The whole list is decoded at once: well over PHP's usual 128 MB.
        $limit = (string) config('indlela.rank_tracking.locations_memory_limit');
        if ($limit !== '' && self::bytes($limit) > self::bytes((string) ini_get('memory_limit'))) {
            ini_set('memory_limit', $limit);
        }

        $now = now();
        $rows = collect($this->api->locations())
            ->filter(fn ($l) => isset($l['location_code'], $l['location_name']))
            ->map(fn (array $l) => [
                'code' => (int) $l['location_code'],
                'name' => mb_substr((string) $l['location_name'], 0, 255),
                'type' => isset($l['location_type']) ? mb_substr((string) $l['location_type'], 0, 60) : null,
                'country_iso' => isset($l['country_iso_code']) ? mb_substr(strtoupper((string) $l['country_iso_code']), 0, 2) : null,
                'parent_code' => isset($l['location_code_parent']) ? (int) $l['location_code_parent'] : null,
                'fetched_at' => $now,
            ])
            ->unique('code');

        if ($rows->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($rows, $now) {
            foreach ($rows->chunk(1000) as $chunk) {
                RankLocation::query()->upsert($chunk->values()->all(), ['code'], ['name', 'type', 'country_iso', 'parent_code', 'fetched_at']);
            }
            RankLocation::query()->where('fetched_at', '<', $now)->delete();
        });

        return $rows->count();
    }

    /** "1024M" → bytes; -1 (no limit) → PHP_INT_MAX. */
    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return PHP_INT_MAX;
        }
        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
