<?php

namespace App\Policies;

use App\Models\RankKeyword;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspace;
use Illuminate\Auth\Access\Response;

/**
 * A tracked keyword (`*_rank_tracking`): the permission, the workspace (the
 * keyword's and its site's) and access to the site's client. Changing,
 * deleting and "check now" all need update_rank_tracking.
 */
class RankKeywordPolicy
{
    use ChecksWorkspace;

    public function view(User $user, RankKeyword $keyword): Response
    {
        return $this->check($user, 'view_rank_tracking', $keyword);
    }

    public function update(User $user, RankKeyword $keyword): Response
    {
        return $this->check($user, 'update_rank_tracking', $keyword);
    }

    public function delete(User $user, RankKeyword $keyword): Response
    {
        return $this->check($user, 'update_rank_tracking', $keyword);
    }

    private function check(User $user, string $permission, RankKeyword $keyword): Response
    {
        if (! $this->inCurrentWorkspace($keyword) || ! $keyword->site) {
            return Response::deny('You do not have access to this client.');
        }

        return app(SitePolicy::class)->allowSite($user, $permission, $keyword->site);
    }
}
