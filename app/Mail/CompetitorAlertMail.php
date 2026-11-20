<?php

namespace App\Mail;

use App\Models\CompetitorAlert;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * A site's daily competitor alert digest, to the agency's own people (the
 * normal TemplatedMail From and layout). The wording is the workspace's
 * `competitor_alert` template; {{alerts}} is an HTML table built here (each
 * value escaped), filled in after the plain variables. The button opens the
 * site's Competitors tab.
 */
class CompetitorAlertMail extends TemplatedMail
{
    /** @param  Collection<int, CompetitorAlert>  $alerts  with keyword and competitor loaded, newest first */
    public function __construct(public Site $site, public Collection $alerts, ?int $workspaceId)
    {
        $count = $alerts->count();
        parent::__construct(EmailTemplateType::CompetitorAlert, [
            'workspace' => (string) $site->workspace?->name,
            'client' => (string) $site->client?->name,
            'site' => (string) $site->domain,
            'count' => $count === 1 ? '1 alert' : "{$count} alerts",
        ], self::link($site), $workspaceId);
    }

    public static function link(Site $site): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/dashboard/rank-tracking/{$site->id}?tab=competitors";
    }

    /** @return array{subject: string, body: string} */
    public function rendered(): array
    {
        if ($this->renderedCache !== null) {
            return $this->renderedCache;
        }
        $rendered = parent::rendered();
        $this->renderedCache = null;

        $shown = (int) config('indlela.rank_tracking.competitor_alert_email_rows');
        $text = $this->alerts->take($shown)->map(fn (CompetitorAlert $a) => self::line($a))->implode('; ');

        return $this->renderedCache = [
            'subject' => trim(preg_replace('/\s+/', ' ', str_replace('{{alerts}}', $text, $rendered['subject']))),
            'body' => str_replace('{{alerts}}', $this->table($shown), $rendered['body']),
        ];
    }

    private function table(int $shown): string
    {
        $cell = 'style="padding:4px 8px;border-bottom:1px solid #e5e7eb;text-align:left;"';
        $rows = $this->alerts->take($shown)->map(fn (CompetitorAlert $a) => '<tr>'
            .'<td '.$cell.'>'.e((string) $a->keyword?->keyword).'<br><span style="color:#6b7280;">'.e(trim($a->keyword?->location_name.' · '.$a->keyword?->device, ' ·')).'</span></td>'
            .'<td '.$cell.'>'.e((string) ($a->competitor?->name ?? $a->competitor?->domain)).'</td>'
            .'<td '.$cell.'>'.e(self::move($a->previous_their_position, $a->their_position)).'</td>'
            .'<td '.$cell.'>'.e(self::move($a->previous_our_position, $a->our_position)).'</td>'
            .'</tr>')->implode('');
        $more = $this->alerts->count() - $shown;

        // One line: an indented block would turn into code in the Markdown layout.
        return '<table style="width:100%;border-collapse:collapse;font-size:14px;"><thead><tr>'
            .'<th '.$cell.'>Keyword</th><th '.$cell.'>Competitor</th><th '.$cell.'>Them</th><th '.$cell.'>You</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table>'
            .($more > 0 ? '<p>And '.$more.' more.</p>' : '');
    }

    /** "8 → 3", "Not in top 100 → 9". */
    private static function move(?int $from, ?int $to): string
    {
        $show = fn (?int $p) => $p === null ? 'Not in top 100' : (string) $p;

        return $show($from).' → '.$show($to);
    }

    private static function line(CompetitorAlert $a): string
    {
        return $a->keyword?->keyword.': '.($a->competitor?->domain).' '.self::move($a->previous_their_position, $a->their_position).', you '.self::move($a->previous_our_position, $a->our_position);
    }
}
