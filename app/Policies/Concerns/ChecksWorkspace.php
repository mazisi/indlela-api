<?php

namespace App\Policies\Concerns;

use App\Support\CurrentWorkspace;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * The second tenancy wall (the BelongsToWorkspace scope is the first): a
 * record of another workspace is never allowed, whatever the permissions.
 */
trait ChecksWorkspace
{
    protected function inCurrentWorkspace(Model $model): bool
    {
        $current = app(CurrentWorkspace::class)->id();

        return $current !== null && (int) $model->getAttribute('workspace_id') === $current;
    }

    protected function permitted(bool $can, string $message = 'You do not have permission to do this.'): Response
    {
        return $can ? Response::allow() : Response::deny($message);
    }
}
