<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspace;
use Illuminate\Auth\Access\Response;

/**
 * A report (`*_reports`): the permission, the workspace (the report's and its
 * site's) and access to the site's client, as for the site itself.
 */
class ReportPolicy
{
    use ChecksWorkspace;

    public function view(User $user, Report $report): Response
    {
        return $this->check($user, 'view_reports', $report);
    }

    /** Send, share, revoke the link. */
    public function create(User $user, Report $report): Response
    {
        return $this->check($user, 'create_reports', $report);
    }

    public function delete(User $user, Report $report): Response
    {
        return $this->check($user, 'delete_reports', $report);
    }

    private function check(User $user, string $permission, Report $report): Response
    {
        if (! $this->inCurrentWorkspace($report) || ! $report->site) {
            return Response::deny('You do not have access to this client.');
        }

        return app(SitePolicy::class)->allowSite($user, $permission, $report->site);
    }
}
