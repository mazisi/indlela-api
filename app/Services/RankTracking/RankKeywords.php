<?php

namespace App\Services\RankTracking;

use App\Models\RankKeyword;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Adding, changing and removing a site's tracked keywords. Each change goes
 * through the model, so the audit trail records it.
 */
class RankKeywords
{
    public function __construct(private readonly RankLimits $limits) {}

    /**
     * Every keyword x location x device combination that isn't tracked yet
     * (frequency weekly unless given). 422 on `keywords` when the new ones
     * would take the workspace over its keyword limit; nothing is created then.
     *
     * @param  array{keywords: list<string>, locations: list<array{type: string, code?: int, coordinate?: string, name: string}>, devices: list<string>, frequency?: ?string, language_code?: ?string, tags?: ?list<string>}  $data
     * @return array{created: int, skipped: int}
     */
    public function create(Site $site, array $data, ?User $user): array
    {
        $keywords = self::cleanKeywords($data['keywords']);
        $locations = array_map(fn (array $l) => self::location($l), $data['locations']);
        $devices = array_values(array_unique($data['devices']));
        $tags = self::cleanTags($data['tags'] ?? []);

        return $this->limits->keywordsSerialised($site->workspace_id, function () use ($site, $data, $user, $keywords, $locations, $devices, $tags) {
            $existing = RankKeyword::query()->where('site_id', $site->id)
                ->get(['keyword', 'location_key', 'device'])
                ->map(fn (RankKeyword $k) => self::key($k->keyword, $k->location_key, $k->device))
                ->flip();

            $new = [];
            $skipped = 0;
            foreach ($keywords as $keyword) {
                foreach ($locations as $location) {
                    foreach ($devices as $device) {
                        $key = self::key($keyword, $location['location_key'], $device);
                        if ($existing->has($key) || isset($new[$key])) {
                            $skipped++;

                            continue;
                        }
                        $new[$key] = ['keyword' => $keyword, 'device' => $device, ...$location];
                    }
                }
            }

            $this->limits->ensureRoomFor($site->workspace_id, count($new), 'keywords');

            foreach ($new as $row) {
                RankKeyword::query()->create([
                    ...$row,
                    'site_id' => $site->id,
                    'language_code' => $data['language_code'] ?? 'en',
                    'frequency' => $data['frequency'] ?? RankKeyword::WEEKLY,
                    'weekly_day' => today()->dayOfWeek,
                    'tags' => $tags,
                    'is_active' => true,
                    'created_by' => $user?->id,
                ]);
            }

            return ['created' => count($new), 'skipped' => $skipped];
        });
    }

    /**
     * Turning a keyword back on takes a place: 422 on `is_active` when the
     * workspace is at its limit.
     *
     * @param  array{frequency?: string, weekly_day?: int, tags?: ?list<string>, is_active?: bool, is_key?: bool}  $data
     */
    public function update(RankKeyword $keyword, array $data): RankKeyword
    {
        if (array_key_exists('tags', $data)) {
            $data['tags'] = self::cleanTags($data['tags'] ?? []);
        }

        return $this->limits->keywordsSerialised($keyword->workspace_id, function () use ($keyword, $data) {
            if (($data['is_active'] ?? false) && ! $keyword->is_active) {
                $this->limits->ensureRoomFor($keyword->workspace_id, 1, 'is_active');
            }
            $keyword->fill($data)->save();

            return $keyword;
        });
    }

    /**
     * activate / deactivate / delete / set_frequency / mark_key / unmark_key on some of a site's
     * keywords; all or nothing (unknown ids: 422 on `ids`; activating more
     * than the workspace has room for: 422 on `ids`, nothing changed).
     *
     * @param  list<int>  $ids
     * @return int keywords changed
     */
    public function bulk(Site $site, string $action, array $ids, ?string $frequency): int
    {
        return $this->limits->keywordsSerialised($site->workspace_id, function () use ($site, $action, $ids, $frequency) {
            $rows = RankKeyword::query()->where('site_id', $site->id)->whereKey($ids)->get();
            if ($rows->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['ids' => 'Some of the selected keywords no longer exist. Refresh and try again.']);
            }
            $rows->each(fn (RankKeyword $k) => Gate::authorize($action === 'delete' ? 'delete' : 'update', $k));

            if ($action === 'activate') {
                $this->limits->ensureRoomFor($site->workspace_id, $rows->where('is_active', false)->count(), 'ids');
            }

            $rows->each(fn (RankKeyword $k) => match ($action) {
                'delete' => $k->delete(),
                'set_frequency' => $k->update(['frequency' => $frequency]),
                'mark_key', 'unmark_key' => $k->update(['is_key' => $action === 'mark_key']),
                default => $k->update(['is_active' => $action === 'activate']),
            });

            return $rows->count();
        });
    }

    /**
     * Trimmed, inner whitespace collapsed, empty ones dropped, duplicates
     * (ignoring case) dropped; the first spelling wins.
     *
     * @param  list<string>  $keywords
     * @return list<string>
     */
    public static function cleanKeywords(array $keywords): array
    {
        return collect($keywords)
            ->map(fn ($k) => preg_replace('/\s+/u', ' ', trim((string) $k)))
            ->filter(fn (string $k) => $k !== '')
            ->unique(fn (string $k) => mb_strtolower($k))
            ->values()->all();
    }

    /** @return list<string> */
    public static function cleanTags(?array $tags): array
    {
        return collect($tags ?? [])->map(fn ($t) => trim((string) $t))->filter()->unique(fn ($t) => mb_strtolower($t))->values()->all();
    }

    /**
     * A location as stored: a DataForSEO location code, or a coordinate
     * "lat,lng" given the configured radius ("lat,lng,radius", mm).
     *
     * @return array{location_type: string, location_code: ?int, location_coordinate: ?string, location_name: string, location_key: string}
     */
    public static function location(array $location): array
    {
        if ($location['type'] === RankKeyword::CODE) {
            $code = (int) $location['code'];
            $coordinate = null;
        } else {
            [$lat, $lng] = array_map('trim', explode(',', (string) $location['coordinate']));
            $coordinate = self::decimal($lat).','.self::decimal($lng).','.(int) config('indlela.rank_tracking.coordinate_radius');
            $code = null;
        }

        return [
            'location_type' => $location['type'],
            'location_code' => $code,
            'location_coordinate' => $coordinate,
            'location_name' => trim((string) $location['name']),
            'location_key' => RankKeyword::locationKey($location['type'], $code, $coordinate),
        ];
    }

    /** At most 7 decimals (DataForSEO's limit), no trailing zeros. */
    private static function decimal(string $value): string
    {
        $out = rtrim(rtrim(number_format((float) $value, 7, '.', ''), '0'), '.');

        return $out === '-0' ? '0' : $out;
    }

    private static function key(string $keyword, string $locationKey, string $device): string
    {
        return mb_strtolower($keyword)."\x1f{$locationKey}\x1f{$device}";
    }
}
