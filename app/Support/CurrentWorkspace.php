<?php

namespace App\Support;

use App\Models\Workspace;
use Closure;
use Spatie\Permission\PermissionRegistrar;

/**
 * The workspace this request acts in. Set by the `workspace` middleware from
 * the signed-in user's current workspace; read by the BelongsToWorkspace
 * scope, the Auditable trait and spatie's teams (roles are per workspace).
 *
 * Outside a request (console, queue, seeders) nothing is set and the
 * BelongsToWorkspace scope doesn't filter: code there passes workspaces
 * explicitly, or wraps its work in using().
 */
final class CurrentWorkspace
{
    private ?Workspace $workspace = null;

    public function set(?Workspace $workspace): void
    {
        $this->workspace = $workspace;
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace?->getKey());
    }

    public function get(): ?Workspace
    {
        return $this->workspace;
    }

    public function id(): ?int
    {
        return $this->workspace?->getKey();
    }

    /**
     * Runs $work as if $workspace were current (e.g. provisioning a new
     * workspace's roles), then restores the previous one.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function using(Workspace $workspace, Closure $work): mixed
    {
        $previous = $this->workspace;
        $this->set($workspace);

        try {
            return $work();
        } finally {
            $this->set($previous);
        }
    }
}
