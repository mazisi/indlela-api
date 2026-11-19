<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspace;
use Illuminate\Auth\Access\Response;

/**
 * Clients (`*_clients_clients`): the page permission, the workspace, and the
 * user's access to the client (`canAccessClient`), checked together.
 */
class ClientPolicy
{
    use ChecksWorkspace;

    public function viewAny(User $user): Response
    {
        return $this->permitted($user->can('view_clients_clients'));
    }

    public function view(User $user, Client $client): Response
    {
        return $this->allow($user, 'view_clients_clients', $client);
    }

    /** Only users who see every client add clients (they couldn't see one they added otherwise). */
    public function create(User $user): Response
    {
        if (! $user->can('create_clients_clients')) {
            return Response::deny('You do not have permission to do this.');
        }

        return $this->permitted($user->seesAllClients(), 'Only users who see every client can add clients.');
    }

    /** Also activate/deactivate in bulk. */
    public function update(User $user, Client $client): Response
    {
        return $this->allow($user, 'update_clients_clients', $client);
    }

    public function delete(User $user, Client $client): Response
    {
        return $this->allow($user, 'delete_clients_clients', $client);
    }

    private function allow(User $user, string $permission, Client $client): Response
    {
        if (! $user->can($permission)) {
            return Response::deny('You do not have permission to do this.');
        }

        if (! $this->inCurrentWorkspace($client) || ! $user->canAccessClient($client)) {
            return Response::deny('You do not have access to this client.');
        }

        return Response::allow();
    }
}
