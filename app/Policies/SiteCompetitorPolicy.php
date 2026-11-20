<?php

namespace App\Policies;

use App\Models\SiteCompetitor;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspace;
use Illuminate\Auth\Access\Response;

/**
 * A site's competitor (`*_rank_tracking`, like the site's keywords): the
 * permission, the workspace (the competitor's and its site's) and access to
 * the site's client. Changing and removing need update_rank_tracking.
 */
class SiteCompetitorPolicy
{
    use ChecksWorkspace;

    public function view(User $user, SiteCompetitor $competitor): Response
    {
        return $this->check($user, 'view_rank_tracking', $competitor);
    }

    public function update(User $user, SiteCompetitor $competitor): Response
    {
        return $this->check($user, 'update_rank_tracking', $competitor);
    }

    public function delete(User $user, SiteCompetitor $competitor): Response
    {
        return $this->check($user, 'update_rank_tracking', $competitor);
    }

    private function check(User $user, string $permission, SiteCompetitor $competitor): Response
    {
        if (! $this->inCurrentWorkspace($competitor) || ! $competitor->site) {
            return Response::deny('You do not have access to this client.');
        }

        return app(SitePolicy::class)->allowSite($user, $permission, $competitor->site);
    }
}
