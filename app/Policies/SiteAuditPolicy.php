<?php

namespace App\Policies;

use App\Models\SiteAudit;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspace;
use Illuminate\Auth\Access\Response;

/**
 * An audit (`*_site_audit`): the permission, the workspace (the audit's and
 * its site's) and access to the site's client, as for the site itself.
 */
class SiteAuditPolicy
{
    use ChecksWorkspace;

    public function view(User $user, SiteAudit $audit): Response
    {
        return $this->check($user, 'view_site_audit', $audit);
    }

    /** Cancel. */
    public function update(User $user, SiteAudit $audit): Response
    {
        return $this->check($user, 'update_site_audit', $audit);
    }

    private function check(User $user, string $permission, SiteAudit $audit): Response
    {
        if (! $this->inCurrentWorkspace($audit) || ! $audit->site) {
            return Response::deny('You do not have access to this client.');
        }

        return app(SitePolicy::class)->allowSite($user, $permission, $audit->site);
    }
}
