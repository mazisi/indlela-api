<?php

namespace Database\Seeders;

use App\Models\CompetitorAlert;
use App\Models\RankCheck;
use App\Models\RankKeyword;
use App\Models\Site;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use App\Services\RankTracking\CompetitorTracker;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo competitor tracking on top of RankDemoSeeder's data (run that
 * first). Not part of DatabaseSeeder: run it on purpose,
 *
 *     php artisan db:seed --class=CompetitorDemoSeeder
 *
 * For the site demo.indlela.local in the first workspace it gives every done
 * check that has no organic hosts yet a made-up top 30 (the site at its
 * position, four rival agencies moving over the 90 days, directories and
 * big sites filling the rest), adds three of the rivals as competitors
 * (the fourth stays a suggestion), marks three phrases as key keywords,
 * fills in the positions and replays the alerts (those before yesterday
 * marked as emailed). Deterministic. Additive and idempotent: hosts
 * already stored, existing or removed competitors and sent alerts are left
 * as they are; no alert recipients are set, so nothing is emailed.
 */
class CompetitorDemoSeeder extends Seeder
{
    private const DOMAIN = 'demo.indlela.local';

    /** domain => [name, start position, end position, tracked as a competitor]. */
    private const RIVALS = [
        'jozi-seo.co.za' => ['Jozi SEO', 6, 3, true],
        'rankright.co.za' => ['Rank Right', 4, 9, true],
        'sandtondigital.co.za' => ['Sandton Digital', 22, 7, true],
        'clickwise.co.za' => ['Clickwise', 12, 5, false],
    ];

    private const FILLERS = [
        'en.wikipedia.org', 'hubspot.com', 'yellowpages.co.za', 'moz.com', 'semrush.com', 'clutch.co', 'gumtree.co.za',
        'reddit.com', 'linkedin.com', 'youtube.com', 'searchengineland.com', 'agencyspotter.com', 'brabys.com',
    ];

    private const KEY_PHRASES = ['seo agency johannesburg', 'seo company near me', 'web design sandton'];

    public function run(CurrentWorkspace $current, CompetitorTracker $tracker): void
    {
        $workspace = Workspace::query()->orderBy('id')->first();
        $site = $workspace ? Site::query()->inWorkspace($workspace)->where('domain', self::DOMAIN)->first() : null;
        if (! $site || ! RankCheck::query()->inWorkspace($workspace)->where('site_id', $site->id)->exists()) {
            $this->command?->warn('No demo rank tracking yet: run RankDemoSeeder first.');

            return;
        }

        [$filled, $competitors, $alerts] = $current->using($workspace, function () use ($site, $tracker) {
            $filled = $this->fillHosts($site);

            RankKeyword::query()->where('site_id', $site->id)->whereIn('keyword', self::KEY_PHRASES)->update(['is_key' => true]);

            $added = 0;
            foreach (self::RIVALS as $domain => [$name, , , $tracked]) {
                if (! $tracked || SiteCompetitor::query()->withTrashed()->where('site_id', $site->id)->where('domain', $domain)->exists()) {
                    continue;
                }
                SiteCompetitor::query()->create(['site_id' => $site->id, 'domain' => $domain, 'name' => $name, 'is_active' => true]);
                $added++;
            }
            SiteCompetitor::query()->where('site_id', $site->id)->active()->get()->each(fn (SiteCompetitor $c) => $tracker->backfill($c));

            // Alerts as the checks would have made them, dated by their check.
            RankCheck::query()->where('site_id', $site->id)->where('status', RankCheck::DONE)->whereNotNull('organic_domains')
                ->whereIn('keyword_id', RankKeyword::query()->where('site_id', $site->id)->where('is_key', true)->select('id'))
                ->orderBy('checked_on')->get()
                ->each(fn (RankCheck $check) => $tracker->record($check));
            CompetitorAlert::query()->where('site_id', $site->id)->whereNull('emailed_at')->update([
                'created_at' => DB::raw("checked_on + TIME '01:40'"),
                'emailed_at' => DB::raw("CASE WHEN checked_on < CURRENT_DATE - 1 THEN checked_on + TIME '07:30' END"),
            ]);

            return [$filled, $added, CompetitorAlert::query()->where('site_id', $site->id)->count()];
        });

        $this->command?->info(sprintf('Competitor demo for site #%d (%s): hosts stored on %d check(s), %d competitor(s) added, %d alert(s).',
            $site->id, self::DOMAIN, $filled, $competitors, $alerts));
    }

    /** Made-up organic hosts for the site's done checks that have none. */
    private function fillHosts(Site $site): int
    {
        $count = 0;
        $days = (int) config('indlela.rank_tracking.competitor_backfill_days');
        RankCheck::query()->where('site_id', $site->id)->where('status', RankCheck::DONE)->whereNull('organic_domains')
            ->with('keyword:id,keyword,device')
            ->chunkById(500, function ($checks) use (&$count, $days) {
                foreach ($checks as $check) {
                    $t = max(0.0, min(1.0, 1 - today()->diffInDays($check->checked_on, true) / max(1, $days - 1)));
                    $check->forceFill(['organic_domains' => $this->hosts($check, $t)])->saveQuietly();
                    $count++;
                }
            });

        return $count;
    }

    /** @return list<?string> the site at its position, rivals along their line, fillers on the rest of the top 30 */
    private function hosts(RankCheck $check, float $t): array
    {
        $seed = crc32($check->keyword?->keyword.'|'.$check->keyword?->device);
        $day = crc32($check->checked_on->toDateString());
        $slots = [];
        if ($check->position !== null && $check->position <= 30) {
            $slots[$check->position] = self::DOMAIN;
        }

        $i = 0;
        foreach (self::RIVALS as $domain => [, $start, $end]) {
            $i++;
            // Each phrase shifts each rival a little; a few phrases it doesn't rank for at all.
            if (($seed >> $i) % 5 === 0) {
                continue;
            }
            $position = (int) round($start + ($end - $start) * $t + (($seed >> ($i + 3)) % 9) - 3 + (($day >> $i) % 3) - 1);
            for ($p = max(1, $position); $p <= 30; $p++) {
                if (! isset($slots[$p])) {
                    $slots[$p] = $domain;
                    break;
                }
            }
        }

        $filler = ($seed + $day) % count(self::FILLERS);
        $hosts = [];
        for ($p = 1; $p <= 30; $p++) {
            $hosts[] = $slots[$p] ?? self::FILLERS[($filler + $p) % count(self::FILLERS)];
        }

        return $hosts;
    }
}
