<?php

namespace App\Services\Workspaces;

use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;

/**
 * Puts a signed-in user into their current workspace for this request: the
 * one in users.current_workspace_id while they still belong to it, else the
 * first they joined (saved as current). Sets spatie's team id, so roles and
 * permissions are the ones they hold there.
 */
class WorkspaceContext
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** Null when the user belongs to no workspace. */
    public function enter(User $user): ?Workspace
    {
        $workspace = $user->current_workspace_id
            ? $user->workspaces()->whereKey($user->current_workspace_id)->first()
            : null;

        $workspace ??= $user->workspaces()
            ->orderBy('workspace_user.created_at')
            ->orderBy('workspaces.id')
            ->first();

        if ($workspace && $user->current_workspace_id !== $workspace->id) {
            $user->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();
        }

        $this->current->set($workspace);
        // Roles and permissions loaded for another workspace (or none) are stale now.
        $user->unsetRelation('roles')->unsetRelation('permissions')->unsetRelation('currentWorkspace');

        return $workspace;
    }

    /** Make $workspace the user's current one; they must belong to it. */
    public function switchTo(User $user, Workspace $workspace): Workspace
    {
        $user->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();

        return $this->enter($user);
    }
}
