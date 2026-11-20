<?php

namespace App\Services\LocalGrid;

use App\Exceptions\LocalGridException;
use App\Models\MapGridSearch;
use App\Models\Site;
use App\Services\RankTracking\RankSpend;
use App\Services\RankTracking\SerpApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Finding the Google Maps listing a grid tracks: one live Maps SERP for the
 * query, across South Africa or near a point, its first results as
 * candidates. Counts toward the spend cap and the workspace's daily
 * allowance (recorded before the call: a failed search is billed too).
 */
class BusinessSearch
{
    public function __construct(
        private readonly SerpApi $api,
        private readonly RankSpend $spend,
        private readonly GridLimits $limits,
    ) {}

    /** @return list<array<string, mixed>> candidates (MapsParser::candidates) */
    public function search(Site $site, string $query, ?float $lat, ?float $lng, ?int $userId): array
    {
        if (! $this->api->configured()) {
            throw LocalGridException::paused();
        }

        $search = $this->spend->serialised(fn () => DB::transaction(function () use ($site, $query, $userId) {
            if ($this->spend->checksThatFit((float) config('indlela.local_grid.price_live')) === 0) {
                throw LocalGridException::paused();
            }
            $this->limits->lockSearch($site->workspace_id);
            $limit = $this->limits->searchLimit();
            if ($this->limits->searchesToday($site->workspace_id) >= $limit) {
                throw LocalGridException::searchLimit($limit);
            }

            return MapGridSearch::query()->create([
                'workspace_id' => $site->workspace_id, 'site_id' => $site->id, 'user_id' => $userId,
                'query' => $query, 'searched_on' => today()->toDateString(),
            ]);
        }));
        $this->spend->forget();

        $config = config('indlela.local_grid');
        $task = ['keyword' => $query, 'language_code' => $config['language_code'], 'device' => 'desktop', 'os' => 'windows', 'depth' => (int) $config['depth']];
        if ($lat !== null && $lng !== null) {
            $task['location_coordinate'] = GridGeometry::coordinate($lat, $lng, 13);
        } else {
            $task['location_code'] = (int) $config['search_location_code'];
        }

        $answer = $this->api->mapsLive(['search' => $task])['search'];
        $ok = in_array($answer['status_code'], [20000, 40102], true);
        $search->forceFill(['status' => $ok ? MapGridSearch::DONE : MapGridSearch::FAILED, 'cost' => round((float) $answer['cost'], 6)])->save();
        $this->spend->forget();

        if (! $ok) {
            Log::warning('Local grid: a business search failed.', ['search_id' => $search->id, 'error' => $answer['status_message'], 'code' => $answer['status_code']]);
            throw LocalGridException::searchFailed();
        }

        return MapsParser::candidates($answer['result'], (int) $config['search_results']);
    }
}
