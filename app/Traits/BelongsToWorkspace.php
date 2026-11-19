<?php

namespace App\Traits;

use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenancy for workspace-owned models (clients, sites, …): every query is
 * limited to the current workspace, and new rows are stamped with it. Route
 * model binding therefore 404s on another workspace's id.
 *
 * This is the first wall; Policies check `workspace_id` again (see
 * App\Policies\Concerns\ChecksWorkspace), so a query run outside a request
 * or with the scope removed still can't be acted on across workspaces.
 */
trait BelongsToWorkspace
{
    protected static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope('workspace', function (Builder $query) {
            $id = app(CurrentWorkspace::class)->id();
            if ($id !== null) {
                $query->where($query->getModel()->qualifyColumn('workspace_id'), $id);
            }
        });

        static::creating(function ($model) {
            $model->workspace_id ??= app(CurrentWorkspace::class)->id();
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function scopeInWorkspace(Builder $query, Workspace|int $workspace): Builder
    {
        return $query->withoutGlobalScope('workspace')
            ->where($this->qualifyColumn('workspace_id'), $workspace instanceof Workspace ? $workspace->getKey() : $workspace);
    }
}
