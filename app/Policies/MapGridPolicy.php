<?php

namespace App\Policies;

use App\Models\MapGrid;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspace;
use Illuminate\Auth\Access\Response;

/**
 * A local grid and its scans (`*_local_grid`): the permission, the
 * workspace (the grid's and its site's) and access to the site's client.
 * Update covers changing, pausing and "scan now".
 */
class MapGridPolicy
{
    use ChecksWorkspace;

    public function view(User $user, MapGrid $grid): Response
    {
        return $this->check($user, 'view_local_grid', $grid);
    }

    public function update(User $user, MapGrid $grid): Response
    {
        return $this->check($user, 'update_local_grid', $grid);
    }

    public function delete(User $user, MapGrid $grid): Response
    {
        return $this->check($user, 'delete_local_grid', $grid);
    }

    private function check(User $user, string $permission, MapGrid $grid): Response
    {
        if (! $this->inCurrentWorkspace($grid) || ! $grid->site) {
            return Response::deny('You do not have access to this client.');
        }

        return app(SitePolicy::class)->allowSite($user, $permission, $grid->site);
    }
}
