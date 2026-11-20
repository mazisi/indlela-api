<?php

namespace App\Http\Resources;

use App\Models\RankKeyword;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row of the keyword list: the keyword, its latest result and changes
 * (from RankKeywordQuery's columns) and `history`, its last 30 results.
 * change_* positive = moved up.
 *
 * @mixin RankKeyword
 */
class RankKeywordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $matching = self::json($this->matching_urls) ?? [];

        return [
            'id' => $this->id,
            'keyword' => $this->keyword,
            'location' => $this->locationArray(),
            'language_code' => $this->language_code,
            'device' => $this->device,
            'frequency' => $this->frequency,
            'weekly_day' => $this->weekly_day,
            'tags' => $this->tags ?? [],
            'is_active' => $this->is_active,
            'position' => self::int($this->position),
            'previous_position' => self::int($this->previous_position),
            'change_1d' => self::int($this->change_1d),
            'change_7d' => self::int($this->change_7d),
            'change_30d' => self::int($this->change_30d),
            'best_position' => self::int($this->best_position),
            'url' => $this->url,
            // Only when both results rank: dropping out or coming in isn't a URL switch.
            'url_changed' => $this->url !== null && $this->previous_url !== null && $this->url !== $this->previous_url,
            'cannibalised' => count($matching) >= 2,
            'serp_features' => self::json($this->serp_features),
            'last_checked_on' => $this->last_checked_on ? substr((string) $this->last_checked_on, 0, 10) : null,
            'last_check_status' => $this->last_check_status,
            'history' => $this->history ?? [],
        ];
    }

    private static function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function json(mixed $value): ?array
    {
        return is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : null);
    }
}
