<?php

namespace Tests\Support;

use App\Models\Client;
use App\Models\GoogleConnection;
use App\Models\GscDailyTotal;
use App\Models\GscQueryPageDaily;
use App\Models\Site;
use App\Models\Workspace;

/** Google connections, linked sites and hand-made GSC rows for the Search Console tests. */
trait SearchConsoleHelpers
{
    protected function connectGoogle(?Workspace $workspace = null, array $state = []): GoogleConnection
    {
        $workspace ??= $this->workspace();

        return GoogleConnection::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'connected_by' => $this->ownerOf($workspace)->id,
            'google_email' => 'agency@example.com',
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->addHour(),
            'scopes' => 'openid email https://www.googleapis.com/auth/webmasters.readonly',
            'status' => GoogleConnection::ACTIVE,
            ...$state,
        ]);
    }

    protected function linkedSite(?Client $client = null, string $property = 'sc-domain:example.co.za', array $state = []): Site
    {
        $site = Site::factory()->create(['client_id' => ($client ?? $this->makeClient())->id]);
        $site->forceFill(['gsc_property' => $property, 'gsc_sync_status' => Site::GSC_DONE, ...$state])->save();

        return $site->fresh();
    }

    /** gsc_query_page_daily rows: [date, query, page, clicks, impressions, position]. */
    protected function addRows(Site $site, array $rows): void
    {
        GscQueryPageDaily::withoutGlobalScopes()->insert(array_map(fn (array $r) => [
            'workspace_id' => $site->workspace_id, 'site_id' => $site->id, 'date' => $r[0],
            'query' => $r[1], 'page' => $r[2], 'query_hash' => md5($r[1]), 'page_hash' => md5($r[2]),
            'clicks' => $r[3], 'impressions' => $r[4], 'ctr' => $r[4] ? $r[3] / $r[4] : 0, 'position' => $r[5],
        ], $rows));
    }

    /** gsc_daily_totals rows: [date, clicks, impressions, position]. */
    protected function addTotals(Site $site, array $rows): void
    {
        GscDailyTotal::withoutGlobalScopes()->insert(array_map(fn (array $r) => [
            'workspace_id' => $site->workspace_id, 'site_id' => $site->id, 'date' => $r[0],
            'clicks' => $r[1], 'impressions' => $r[2], 'ctr' => $r[2] ? $r[1] / $r[2] : 0, 'position' => $r[3],
        ], $rows));
    }

    /** A fake Google id_token carrying $email. */
    protected function idToken(string $email): string
    {
        return 'header.'.rtrim(strtr(base64_encode(json_encode(['email' => $email])), '+/', '-_'), '=').'.signature';
    }
}
