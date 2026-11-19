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

    private function allowSite(User $user, string $permission, Site $site): Response
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
