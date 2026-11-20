<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspace;
use Illuminate\Auth\Access\Response;

/**
 * Sites (`*_clients_sites`): the page permission, the workspace, and access
 * to the site's client, checked together.
 */
class SitePolicy
{
    use ChecksWorkspace;

    public function viewAny(User $user, ?Client $client = null): Response
    {
        return $this->allow($user, 'view_clients_sites', $client);
    }

    public function view(User $user, Site $site): Response
    {
        return $this->allowSite($user, 'view_clients_sites', $site);
    }

    /** Without a client (an unknown client_id) only the permission is checked; the rules then reject it. */
    public function create(User $user, ?Client $client = null): Response
    {
        return $this->allow($user, 'create_clients_sites', $client);
    }

    /** Also activate/deactivate in bulk. */
    public function update(User $user, Site $site): Response
    {
        return $this->allowSite($user, 'update_clients_sites', $site);
    }

    public function delete(User $user, Site $site): Response
    {
        return $this->allowSite($user, 'delete_clients_sites', $site);
    }

    /** Search Console reports of the site. */
    public function viewSearchConsole(User $user, Site $site): Response
    {
        return $this->allowSite($user, 'view_search_console', $site);
    }

    /** Link / unlink a property, "sync now". */
    public function manageSearchConsole(User $user, Site $site): Response
    {
        return $this->allowSite($user, 'update_search_console', $site);
    }

    /** Site audit: the site's audits and their results. */
    public function viewSiteAudit(User $user, Site $site): Response
    {
        return $this->allowSite($user, 'view_site_audit', $site);
    }

    /** Site audit: run or cancel an audit, change the audit settings. */
    public function manageSiteAudit(User $user, Site $site): Response
    {
        return $this->allowSite($user, 'update_site_audit', $site);
    }

    /** Rank tracking: the site's keywords and reports. */
    public function viewRankTracking(User $user, Site $site): Response
    {
        return $this->allowSite($user, 'view_rank_tracking', $site);
    }

    /** Rank tracking: add, change or remove keywords, "check now". */
    public function manageRankTracking(User $user, Site $site): Response
    {
        return $this->allowSite($user, 'update_rank_tracking', $site);
    }

    /** Local grid: the site's grids and their scans. */
    public function viewLocalGrid(User $user, Site $site): Response
    {
        return $this->allowSite($user, 'view_local_grid', $site);
    }

    /** Local grid: add a grid, search for its business. */
    public function createLocalGrid(User $user, Site $site): Response
    {
        return $this->allowSite($user, 'create_local_grid', $site);
    }

    /** Local grid: change the site's grids, "scan now". */
    public function manageLocalGrid(User $user, Site $site): Response
    {
        return $this->allowSite($user, 'update_local_grid', $site);
    }

    /** Reports: the site's reports and its schedule. */
    public function viewReports(User $user, Site $site): Response
    {
        return $this->allowSite($user, 'view_reports', $site);
    }

    /** Reports: generate a report, change the monthly schedule. */
    public function createReports(User $user, Site $site): Response
    {
        return $this->allowSite($user, 'create_reports', $site);
    }

    public function allowSite(User $user, string $permission, Site $site): Response
    {
        if (! $this->inCurrentWorkspace($site)) {
            return $user->can($permission)
                ? Response::deny('You do not have access to this client.')
                : Response::deny('You do not have permission to do this.');
        }

        return $this->allow($user, $permission, $site->client);
    }

    private function allow(User $user, string $permission, ?Client $client): Response
    {
        if (! $user->can($permission)) {
            return Response::deny('You do not have permission to do this.');
        }

        if ($client && (! $this->inCurrentWorkspace($client) || ! $user->canAccessClient($client))) {
            return Response::deny('You do not have access to this client.');
        }

        return Response::allow();
    }
}
