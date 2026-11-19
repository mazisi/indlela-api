<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\Settings\BulkActiveRequest;
use App\Http\Resources\ChangeHistoryResource;
use App\Models\AuditLog;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The shared plumbing of a list page (ported from workforce-api): whitelisted
 * column sorting, the bulk bar (activate / deactivate / delete) and the
 * History drawer, always within the current workspace.
 */
trait SettingsList
{
    /**
     * Sorts by a whitelisted column (`$sortable`: request key → column) with
     * empty values last, then `$tieBreaker`. Columns in `$booleans` sort
     * Yes/Active first when ascending.
     *
     * @param  array<string, string>  $sortable
     * @param  list<string>  $booleans
     */
    protected function sortList(Builder $query, array $sortable, ?string $sort, string $direction, string $tieBreaker, array $booleans = ['is_active']): void
    {
        $column = $sortable[$sort];
        if (in_array($column, $booleans, true)) {
            $direction = $direction === 'asc' ? 'desc' : 'asc';
        }

        $query->orderByRaw("{$column} {$direction} NULLS LAST")->orderBy($tieBreaker);
    }

    /**
     * Activates, deactivates or deletes the selected rows of `$query`, each
     * through its model so every change is audited. All or nothing: unknown
     * ids fail with 422, a row the user may not change with 403, and
     * `$blockDelete` may return a 409 message for rows still in use.
     *
     * @param  (callable(Collection<int, Model>): ?string)|null  $blockDelete
     */
    protected function bulkActive(BulkActiveRequest $request, Builder $query, string $plural, ?callable $blockDelete = null): JsonResponse
    {
        ['action' => $action, 'ids' => $ids] = $request->validated();
        $rows = $query->whereKey($ids)->get();

        if ($rows->count() !== count($ids)) {
            return response()->json([
                'message' => "Some of the selected {$plural} no longer exist. Refresh and try again.",
                'errors' => ['ids' => ["Some of the selected {$plural} no longer exist."]],
            ], 422);
        }

        $rows->each(fn (Model $row) => Gate::authorize($action === 'delete' ? 'delete' : 'update', $row));

        if ($action === 'delete' && $blockDelete && ($message = $blockDelete($rows))) {
            return response()->json(['message' => $message], 409);
        }

        DB::transaction(fn () => $rows->each(fn (Model $row) => match ($action) {
            'delete' => $row->delete(),
            default => $row->update(['is_active' => $action === 'activate']),
        }));

        return response()->json(['affected' => $rows->count()]);
    }

    /**
     * A page's History drawer: the current workspace's audit entries for its
     * tables, newest first; `$recordIds` limits them (e.g. to the clients the
     * user may access).
     *
     * @param  list<string>  $tables
     * @param  Builder|null  $recordIds  a query selecting the visible ids
     */
    protected function historyOf(array $tables, int $perPage = 15, ?Builder $recordIds = null): AnonymousResourceCollection
    {
        return ChangeHistoryResource::collection(
            AuditLog::forWorkspace(app(CurrentWorkspace::class)->id())
                ->whereIn('table_name', $tables)
                // audit_logs.record_id is text.
                ->when($recordIds, fn ($q) => $q->whereIn('record_id', $recordIds->selectRaw('CAST(id AS VARCHAR)')))
                ->latest('timestamp')->latest('id')->paginate($perPage)
        );
    }
}
