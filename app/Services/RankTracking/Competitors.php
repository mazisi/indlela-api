<?php

namespace App\Services\RankTracking;

use App\Jobs\BackfillCompetitorPositions;
use App\Models\Site;
use App\Models\SiteCompetitor;
use App\Models\SiteCompetitorSetting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adding, changing and removing a site's competitors, and its alert
 * recipients. Each change goes through the model, so the audit trail
 * records it. At most rank_tracking.max_competitors_per_site active
 * competitors per site, checked under a per-site advisory lock.
 */
class Competitors
{
    /** pg_advisory_xact_lock key (with the site id). */
    private const LOCK = 7_340_301;

    public function limit(): int
    {
        return (int) config('indlela.rank_tracking.max_competitors_per_site');
    }

    /** "https://www.Rival.co.za/shop" → "rival.co.za"; null when it isn't a domain or URL. */
    public static function normalise(string $input): ?string
    {
        return Site::normalise($input)['domain'] ?? null;
    }

    /** @param  array{domain: string, name?: ?string}  $data  domain already normalised */
    public function create(Site $site, array $data, ?User $user): SiteCompetitor
    {
        $domain = $data['domain'];
        if (SerpParser::matches($domain, SerpParser::bareHost((string) $site->domain))) {
            throw ValidationException::withMessages(['domain' => 'That is this site\'s own domain. Enter a competitor\'s website.']);
        }

        $competitor = $this->serialised($site->id, function () use ($site, $data, $user, $domain) {
            if (SiteCompetitor::query()->where('site_id', $site->id)->where('domain', $domain)->exists()) {
                throw self::duplicate();
            }
            $this->ensureRoom($site->id, 'domain');

            return $this->save(new SiteCompetitor([
                'site_id' => $site->id,
                'domain' => $domain,
                'name' => self::name($data['name'] ?? null, $domain),
                'is_active' => true,
                'created_by' => $user?->id,
            ]));
        });
        $this->backfill($competitor);

        return $competitor;
    }

    /** @param  array{name?: ?string, is_active?: bool}  $data */
    public function update(SiteCompetitor $competitor, array $data): SiteCompetitor
    {
        if (array_key_exists('name', $data)) {
            $data['name'] = self::name($data['name'], $competitor->domain);
        }

        $resumed = $this->serialised($competitor->site_id, function () use ($competitor, $data) {
            $competitor->refresh();
            $resuming = ($data['is_active'] ?? false) && ! $competitor->is_active;
            if ($resuming) {
                $this->ensureRoom($competitor->site_id, 'is_active');
            }
            $competitor->fill($data)->save();

            return $resuming;
        });
        if ($resumed) {
            $this->backfill($competitor);
        }

        return $competitor;
    }

    /** 422 on $field when the site already has its maximum of active competitors (call inside serialised()). */
    public function ensureRoom(int $siteId, string $field): void
    {
        $limit = $this->limit();
        if (SiteCompetitor::query()->withoutGlobalScope('workspace')->where('site_id', $siteId)->active()->count() >= $limit) {
            throw ValidationException::withMessages([$field => "A site can track up to {$limit} competitors. Pause or remove one to add another."]);
        }
    }

    /** Takes the site's competitor lock until the surrounding transaction ends. */
    public function lock(int $siteId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::LOCK, $siteId]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function serialised(int $siteId, callable $work): mixed
    {
        return DB::transaction(function () use ($siteId, $work) {
            $this->lock($siteId);

            return $work();
        });
    }

    /** Queue filling in its positions from the site's recent checks, once committed. */
    public function backfill(SiteCompetitor $competitor): void
    {
        BackfillCompetitorPositions::dispatch($competitor->id, (int) $competitor->workspace_id)->afterCommit();
    }

    /** The site's settings (unsaved defaults when it has none). */
    public function settings(Site $site): SiteCompetitorSetting
    {
        return SiteCompetitorSetting::query()->firstOrNew(['site_id' => $site->id], ['alert_recipients' => []]);
    }

    /** @param  list<string>  $recipients */
    public function saveSettings(Site $site, array $recipients): SiteCompetitorSetting
    {
        $settings = $this->settings($site);
        $settings->alert_recipients = collect($recipients)->map(fn (string $e) => trim($e))->unique(fn (string $e) => mb_strtolower($e))->values()->all();
        $settings->save();

        return $settings;
    }

    private function save(SiteCompetitor $competitor): SiteCompetitor
    {
        try {
            $competitor->save();
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw self::duplicate();
            }
            throw $e;
        }

        return $competitor;
    }

    private static function name(?string $name, string $domain): string
    {
        $name = trim((string) $name);

        return $name === '' ? $domain : $name;
    }

    private static function duplicate(): ValidationException
    {
        return ValidationException::withMessages(['domain' => 'This site already tracks that competitor.']);
    }
}
