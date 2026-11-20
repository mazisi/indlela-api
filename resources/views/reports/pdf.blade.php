@php
    use App\Services\Reports\ReportSummary;

    $n = fn ($v) => $v === null ? '–' : ReportSummary::number($v);
    $date = fn (?string $d) => $d ? \Carbon\CarbonImmutable::parse($d)->format('d/m/Y') : '–';
    // A change, green when it's good (every change passed here is "positive = better").
    $change = function ($v, string $suffix = '%', int $decimals = 0) {
        if ($v === null) {
            return '';
        }
        $v = round($v, $decimals);
        $cls = $v == 0 ? 'flat' : ($v > 0 ? 'up' : 'down');
        $sign = $v > 0 ? '+' : ($v < 0 ? '−' : '');

        return '<span class="chg '.$cls.'">'.$sign.number_format(abs($v), $decimals).$suffix.'</span>';
    };
    $position = fn ($p) => $p === null ? 'Not in top 100' : (string) $p;
    $brand = $data['branding']['name'] ?? '';
    $gsc = $data['search_console'] ?? null;
    $rank = $data['rank_tracking'] ?? null;
    $audit = $data['site_audit'] ?? null;
    $bands = ['top3' => '1–3', 'top10' => '4–10', 'top20' => '11–20', 'top100' => '21–100', 'not_ranking' => 'Not in top 100'];
    $features = ['featured_snippet' => 'Featured snippets', 'local_pack' => 'Map pack', 'ai_overview' => 'AI overviews'];
    $path = function (string $url) {
        $p = parse_url($url, PHP_URL_PATH) ?: '/';
        $q = parse_url($url, PHP_URL_QUERY);

        return $q ? "{$p}?{$q}" : $p;
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $report->title }}</title>
<style>
    @page { margin: 26mm 16mm 20mm 16mm; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.6pt; color: #1f2937; line-height: 1.45; margin: 0; }
    header { position: fixed; top: -19mm; left: 0; right: 0; height: 13mm; border-bottom: 2px solid {{ $color }}; }
    header table { width: 100%; border-collapse: collapse; }
    header td { vertical-align: middle; padding: 0 0 2mm 0; }
    header img { max-height: 10mm; max-width: 55mm; }
    header .brand { font-size: 11pt; font-weight: bold; color: {{ $color }}; }
    header .right { text-align: right; color: #6b7280; font-size: 7.5pt; }
    footer { position: fixed; bottom: -13mm; left: 0; right: 0; height: 8mm; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 7pt; padding-top: 2mm; }
    footer table { width: 100%; border-collapse: collapse; }
    h1 { font-size: 16pt; margin: 0 0 1mm 0; color: #111827; }
    h2 { font-size: 11.5pt; margin: 0 0 3mm 0; color: {{ $color }}; border-bottom: 1px solid #e5e7eb; padding-bottom: 1.5mm; }
    h3 { font-size: 9pt; margin: 4mm 0 1.5mm 0; color: #111827; }
    .meta { color: #4b5563; margin-bottom: 5mm; }
    .meta strong { color: #111827; }
    .summary { background: {{ $tint }}; border-left: 3px solid {{ $color }}; padding: 3mm 4mm; margin-bottom: 4mm; }
    .summary .label { font-size: 7pt; text-transform: uppercase; letter-spacing: 0.6pt; color: {{ $color }}; font-weight: bold; margin-bottom: 1mm; }
    .summary ul { margin: 0; padding-left: 4mm; }
    .summary li { font-size: 10pt; margin: 0.8mm 0; color: #111827; }
    .note { border: 1px solid #e5e7eb; padding: 3mm 4mm; margin-bottom: 5mm; color: #374151; }
    .note .label { font-size: 7pt; text-transform: uppercase; letter-spacing: 0.6pt; color: #6b7280; font-weight: bold; margin-bottom: 1mm; }
    section { margin-top: 6mm; }
    section h2 { page-break-after: avoid; }
    .kpis { width: 100%; table-layout: fixed; border-collapse: separate; border-spacing: 1.5mm 0; margin-bottom: 3mm; page-break-inside: avoid; }
    .kpis td { border: 1px solid #e5e7eb; padding: 2.5mm 3mm; width: 25%; vertical-align: top; }
    .kpis .k { font-size: 7pt; color: #6b7280; text-transform: uppercase; letter-spacing: 0.4pt; }
    .kpis .v { font-size: 14pt; font-weight: bold; color: #111827; margin-top: 0.5mm; }
    .chg { font-size: 7.5pt; font-weight: bold; }
    .chg.up { color: #15803d; }
    .chg.down { color: #b91c1c; }
    .chg.flat { color: #6b7280; }
    .muted { color: #6b7280; }
    .small { font-size: 7.2pt; }
    table.data { width: 100%; border-collapse: collapse; margin-bottom: 2mm; }
    table.data tr { page-break-inside: avoid; }
    .where { display: block; font-size: 6.8pt; color: #6b7280; }
    table.data th { text-align: left; font-size: 7pt; text-transform: uppercase; letter-spacing: 0.3pt; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 1.2mm 1.5mm; }
    table.data td { border-bottom: 1px solid #f1f5f9; padding: 1.2mm 1.5mm; vertical-align: top; }
    table.data .num { text-align: right; white-space: nowrap; }
    .url { word-wrap: break-word; }
    .chart { margin: 1mm 0 1mm 0; }
    .legend span { display: inline-block; width: 3mm; height: 3mm; margin: 0 1mm 0 3mm; vertical-align: middle; }
    .bands { width: 100%; border-collapse: collapse; }
    .bands td { text-align: center; font-size: 7.2pt; color: #4b5563; width: 20%; }
    .unavailable { border: 1px dashed #d1d5db; padding: 4mm; color: #6b7280; }
    .two { width: 100%; border-collapse: collapse; }
    .two > tbody > tr > td { width: 50%; vertical-align: top; }
    .two > tbody > tr > td:first-child { padding-right: 3mm; }
    .two > tbody > tr > td:last-child { padding-left: 3mm; }
    .sev { font-size: 6.8pt; font-weight: bold; text-transform: uppercase; padding: 0.3mm 1.2mm; border-radius: 2px; }
    .sev.critical { background: #fee2e2; color: #991b1b; }
    .sev.warning { background: #fef3c7; color: #92400e; }
    .sev.notice { background: #e0f2fe; color: #075985; }
    .score { font-size: 24pt; font-weight: bold; }
</style>
</head>
<body>
<header>
    <table><tr>
        <td>
            @if ($logo)
                <img src="{{ $logo }}" alt="{{ $brand }}">
            @else
                <span class="brand">{{ $brand }}</span>
            @endif
        </td>
        <td class="right">{{ $data['client']['name'] ?? '' }} · {{ $data['period']['label'] ?? '' }}</td>
    </tr></table>
</header>
<footer>
    <table><tr>
        <td>Prepared by {{ $brand }}</td>
        {{-- Page numbers are drawn by ReportPdf (dompdf's page_text). --}}
    </tr></table>
</footer>

<main>
    <h1>{{ $report->title }}</h1>
    <div class="meta">
        <strong>{{ $data['client']['name'] ?? '' }}</strong> · {{ $data['site']['url'] ?? '' }}<br>
        {{ $data['period']['label'] ?? '' }} ({{ $date($data['period']['start'] ?? null) }} – {{ $date($data['period']['end'] ?? null) }})
        @if ($data['compare_period'] ?? null)
            <span class="muted">· compared with {{ $data['compare_period']['label'] }}</span>
        @endif
    </div>

    <div class="summary">
        <div class="label">At a glance</div>
        <ul>
            @foreach ($summary as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
    </div>

    @if ($report->note)
        <div class="note">
            <div class="label">A note from {{ $brand }}</div>
            {!! nl2br(e($report->note)) !!}
        </div>
    @endif

    {{-- Search traffic --}}
    @if ($gsc !== null)
        <section>
            <h2>Search traffic from Google</h2>
            @if (! $gsc['available'])
                <div class="unavailable">{{ $gsc['reason'] }}</div>
            @else
                @php($t = $gsc['totals'])
                @php($c = $gsc['changes'])
                <table class="kpis"><tr>
                    <td><div class="k">Clicks</div><div class="v">{{ $n($t['clicks']) }}</div>{!! $change($c['clicks_pct']) !!}</td>
                    <td><div class="k">Times shown</div><div class="v">{{ $n($t['impressions']) }}</div>{!! $change($c['impressions_pct']) !!}</td>
                    <td><div class="k">Click rate</div><div class="v">{{ number_format($t['ctr'] * 100, 1) }}%</div>{!! $change($c['ctr'], ' pts', 2) !!}</td>
                    <td><div class="k">Average position</div><div class="v">{{ $t['position'] === null ? '–' : number_format($t['position'], 1) }}</div>{!! $change($c['position'], '', 1) !!}</td>
                </tr></table>
                <div style="page-break-inside: avoid;">
                <div class="small muted">Clicks per day</div>
                <div class="chart"><img src="{{ $charts['clicks'] }}" style="width: 100%; height: 34mm;" alt=""></div>
                <div class="small muted">
                    {{ $date($gsc['series'][0]['date'] ?? null) }} – {{ $date(end($gsc['series'])['date'] ?? null) }}
                    @if ($gsc['data_through'] && $gsc['data_through'] < ($data['period']['end'] ?? ''))
                        · Google's data runs to {{ $date($gsc['data_through']) }}
                    @endif
                </div>
                </div>

                <table class="two"><tr>
                    <td>
                        <h3>Top searches</h3>
                        <table class="data">
                            <thead><tr><th>Search</th><th class="num">Clicks</th><th class="num">Position</th></tr></thead>
                            <tbody>
                            @forelse ($gsc['top_queries'] as $row)
                                <tr><td>{{ $row['query'] }}</td><td class="num">{{ $n($row['clicks']) }}</td><td class="num">{{ number_format($row['position'], 1) }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="muted">No searches recorded.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </td>
                    <td>
                        <h3>Top pages</h3>
                        <table class="data">
                            <thead><tr><th>Page</th><th class="num">Clicks</th><th class="num">Position</th></tr></thead>
                            <tbody>
                            @forelse ($gsc['top_pages'] as $row)
                                <tr><td class="url">{{ \Illuminate\Support\Str::limit($path($row['page']), 48) }}</td><td class="num">{{ $n($row['clicks']) }}</td><td class="num">{{ number_format($row['position'], 1) }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="muted">No pages recorded.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </td>
                </tr></table>

                @if ($gsc['quick_wins'] !== [])
                    <h3>Opportunities: searches close to the top of page one</h3>
                    <table class="data">
                        <thead><tr><th>Search</th><th>Page</th><th class="num">Position</th><th class="num">Extra clicks a month</th></tr></thead>
                        <tbody>
                        @foreach ($gsc['quick_wins'] as $row)
                            <tr>
                                <td>{{ $row['query'] }}</td>
                                <td class="url">{{ \Illuminate\Support\Str::limit($path($row['page']), 40) }}</td>
                                <td class="num">{{ number_format($row['position'], 1) }}</td>
                                <td class="num">+{{ $n($row['potential_clicks']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            @endif
        </section>
    @endif

    {{-- Keyword rankings --}}
    @if ($rank !== null)
        <section>
            <h2>Keyword rankings</h2>
            @if (! $rank['available'])
                <div class="unavailable">{{ $rank['reason'] }}</div>
            @else
                <table class="kpis"><tr>
                    <td><div class="k">Keywords tracked</div><div class="v">{{ $n($rank['keywords_tracked']) }}</div></td>
                    <td><div class="k">Average position</div><div class="v">{{ $rank['avg_position'] === null ? '–' : number_format($rank['avg_position'], 1) }}</div>{!! $change($rank['avg_position_change'], '', 1) !!}</td>
                    <td><div class="k">In the top 10</div><div class="v">{{ $n($rank['distribution']['top3'] + $rank['distribution']['top10']) }}</div><span class="small muted">{{ $rank['entered_top10']['count'] }} in · {{ $rank['left_top10']['count'] }} out</span></td>
                    <td><div class="k">In the top 3</div><div class="v">{{ $n($rank['distribution']['top3']) }}</div><span class="small muted">{{ $rank['entered_top3']['count'] }} in · {{ $rank['left_top3']['count'] }} out</span></td>
                </tr></table>

                <div style="page-break-inside: avoid;">
                <div class="small muted legend">Keywords by position<span style="background: #cbd5e1;"></span>start of the period<span style="background: {{ $color }};"></span>end of the period</div>
                <div class="chart"><img src="{{ $charts['distribution'] }}" style="width: 100%; height: 30mm;" alt=""></div>
                <table class="bands"><tr>
                    @foreach ($bands as $key => $label)
                        <td>{{ $label }}<br><strong>{{ $rank['distribution_start'][$key] }} → {{ $rank['distribution'][$key] }}</strong></td>
                    @endforeach
                </tr></table>
                </div>

                <table class="two"><tr>
                    <td>
                        <h3>Biggest gains</h3>
                        <table class="data">
                            <thead><tr><th>Keyword</th><th class="num">From</th><th class="num">To</th></tr></thead>
                            <tbody>
                            @forelse ($rank['gains'] as $row)
                                <tr><td>{{ $row['keyword'] }}<span class="where">{{ $row['location_name'] }} · {{ $row['device'] }}</span></td><td class="num">{{ $position($row['from']) }}</td><td class="num"><span class="chg up">{{ $position($row['to']) }}</span></td></tr>
                            @empty
                                <tr><td colspan="3" class="muted">No keyword moved up.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </td>
                    <td>
                        <h3>Biggest drops</h3>
                        <table class="data">
                            <thead><tr><th>Keyword</th><th class="num">From</th><th class="num">To</th></tr></thead>
                            <tbody>
                            @forelse ($rank['drops'] as $row)
                                <tr><td>{{ $row['keyword'] }}<span class="where">{{ $row['location_name'] }} · {{ $row['device'] }}</span></td><td class="num">{{ $position($row['from']) }}</td><td class="num"><span class="chg down">{{ $position($row['to']) }}</span></td></tr>
                            @empty
                                <tr><td colspan="3" class="muted">No keyword moved down.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </td>
                </tr></table>

                @if ($rank['entered_top10']['keywords'] !== [] || $rank['left_top10']['keywords'] !== [])
                    <table class="two"><tr>
                        <td>
                            <h3>New in the top 10</h3>
                            <div>{{ $rank['entered_top10']['keywords'] === [] ? 'None' : implode(', ', $rank['entered_top10']['keywords']) }}</div>
                        </td>
                        <td>
                            <h3>Left the top 10</h3>
                            <div>{{ $rank['left_top10']['keywords'] === [] ? 'None' : implode(', ', $rank['left_top10']['keywords']) }}</div>
                        </td>
                    </tr></table>
                @endif

                <h3>Special search results won</h3>
                <div>
                    @foreach ($features as $key => $label)
                        {{ $label }}: <strong>{{ $rank['serp_features'][$key]['ours'] ?? 0 }}</strong> of {{ $rank['serp_features'][$key]['present'] ?? 0 }}@if (! $loop->last) &nbsp;·&nbsp; @endif
                    @endforeach
                </div>
            @endif
        </section>
    @endif

    {{-- Website health --}}
    @if ($audit !== null)
        <section>
            <h2>Website health</h2>
            @if (! $audit['available'])
                <div class="unavailable">{{ $audit['reason'] }}</div>
            @else
                <table class="two"><tr>
                    <td style="width: 38%;">
                        @if ($charts['health'])
                            <div style="text-align: center;">
                                <img src="{{ $charts['health'] }}" style="width: 44mm;" alt="">
                                <div class="score" style="margin-top: -10mm; color: {{ \App\Services\Reports\ReportCharts::scoreColor((int) $audit['health_score'], $color) }};">{{ $audit['health_score'] }}<span class="small muted">/100</span></div>
                                <div>{!! $change($audit['health_change'], ' points') !!}</div>
                            </div>
                        @endif
                    </td>
                    <td style="width: 62%;">
                        <table class="data">
                            <tbody>
                                <tr><td>Checked on</td><td class="num">{{ $date($audit['audited_on']) }}</td></tr>
                                <tr><td>Pages checked</td><td class="num">{{ $n($audit['pages_crawled']) }}</td></tr>
                                <tr><td><span class="sev critical">Critical</span></td><td class="num">{{ $n($audit['counts']['critical']) }}</td></tr>
                                <tr><td><span class="sev warning">Warnings</span></td><td class="num">{{ $n($audit['counts']['warning']) }}</td></tr>
                                <tr><td><span class="sev notice">Notices</span></td><td class="num">{{ $n($audit['counts']['notice']) }}</td></tr>
                                @if ($audit['fixed'] !== null)
                                    <tr><td>Fixed since the previous check</td><td class="num"><span class="chg up">{{ $n($audit['fixed']) }}</span></td></tr>
                                    <tr><td>New since the previous check</td><td class="num">{{ $n($audit['new']) }}</td></tr>
                                @endif
                            </tbody>
                        </table>
                    </td>
                </tr></table>

                @if ($audit['top_issues'] !== [])
                    <h3>What to fix first</h3>
                    <table class="data">
                        <thead><tr><th style="width: 30%;">Issue</th><th>Severity</th><th class="num">Pages</th><th style="width: 48%;">How to fix</th></tr></thead>
                        <tbody>
                        @foreach ($audit['top_issues'] as $issue)
                            <tr>
                                <td>{{ $issue['title'] }}</td>
                                <td><span class="sev {{ $issue['severity'] }}">{{ $issue['severity'] }}</span></td>
                                <td class="num">{{ $n($issue['count']) }}</td>
                                <td class="small">{{ $issue['how_to_fix'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            @endif
        </section>
    @endif
</main>
</body>
</html>
