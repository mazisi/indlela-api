<?php

namespace App\Services\ChangeHistory;

use App\Exceptions\UndoException;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\RankKeyword;
use App\Models\ReportSchedule;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\RankTracking\RankLimits;
use App\Support\AuditContext;
use App\Support\ChangeHistory;
use App\Support\CurrentWorkspace;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * Settings › Change history's Undo. An edit is put back field by field, but
 * only while every field still holds the value the edit gave it (a later
 * change must be undone first); a deletion is undone by restoring the
 * record. Adding a record is undone by deleting it on its page, not here.
 * The undo is written to the history like any change (`undo_of` points at
 * the entry it reverted), and the entry is marked undone. Each undo is
 * authorised as the same change made on the record's own page.
 */
class UndoChange
{
    /** Fields that name or file the record, never changed back on their own. */
    private const NOT_FIELDS = ['_label', 'id', 'workspace_id', 'created_at', 'updated_at', 'deleted_at'];

    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly RankLimits $limits,
    ) {}

    /** Why this entry can't be undone, or null if it can be tried (a later change may still stop it). */
    public static function blocked(AuditLog $log): ?string
    {
        if ($log->undone_at !== null) {
            return 'Already undone.';
        }
        $rules = ChangeHistory::undo($log->table_name);
        if (is_string($rules)) {
            return $rules;
        }

        return match ($log->action_type) {
            'UPDATE' => self::lockedField($log, $rules['locked']),
            'DELETE' => $rules['restore'] ? null : 'A deleted '.mb_strtolower((string) ChangeHistory::recordType($log->table_name)).' can\'t be brought back.',
            'CREATE' => 'To undo adding it, delete it on its page.',
            default => 'This change can\'t be undone.',
        };
    }

    /** @param  list<string>  $locked */
    private static function lockedField(AuditLog $log, array $locked): ?string
    {
        $field = collect(array_keys($log->field_changes ?? []))->first(fn (string $f) => in_array($f, $locked, true));
        if ($field === null) {
            return null;
        }
        $label = ChangeHistory::fieldLabels($log->table_name)[$field] ?? $field;

        return "{$label} can't be changed back from here: change it on its page.";
    }

    public function undo(AuditLog $log, User $user): AuditLog
    {
        Gate::authorize('update_settings_change_history');
        if ($reason = self::blocked($log)) {
            throw UndoException::notUndoable($reason);
        }

        $model = $this->record($log);
        $this->authorise($log, $model, $user);

        DB::transaction(function () use ($log, $model, $user) {
            // Lock the entry so two people can't undo it at once.
            $locked = AuditLog::query()->whereKey($log->id)->lockForUpdate()->first();
            if ($locked?->undone_at !== null) {
                throw UndoException::notUndoable('Already undone.');
            }

            AuditContext::undoing($log->id, fn () => $log->action_type === 'DELETE' ? $this->restore($log, $model) : $this->revert($log, $model));

            $log->forceFill(['undone_at' => now(), 'undone_by' => $user->id])->save();
        });

        return $log->fresh(['undoneBy']);
    }

    /** The record the entry is about, deleted ones included; 409 when it's gone for good. */
    private function record(AuditLog $log): Model
    {
        /** @var class-string<Model> $class */
        $class = ChangeHistory::model($log->table_name);
        $query = $class::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $query->withTrashed();
        }
        $model = $query->find($log->record_id);
        if (! $model) {
            throw UndoException::gone(ChangeHistory::recordType($log->table_name));
        }

        return $model;
    }

    /** The same check as making this change on the record's page. */
    private function authorise(AuditLog $log, Model $model, User $user): void
    {
        $restoring = $log->action_type === 'DELETE';
        match (true) {
            $model instanceof Client, $model instanceof Site, $model instanceof RankKeyword => Gate::authorize($restoring ? 'delete' : 'update', $model),
            $model instanceof ReportSchedule => Gate::authorize('createReports', $model->site),
            $model instanceof Role => $this->authoriseRole($model),
            default => Gate::authorize('update_'.ChangeHistory::resource($log->table_name)),
        };
        if ($log->table_name === 'workspaces' && (int) $model->getKey() !== $this->current->id()) {
            throw UndoException::gone('Workspace');
        }
    }

    private function authoriseRole(Role $role): void
    {
        Gate::authorize('update_settings_roles');
        if ($role->isSystem()) {
            throw UndoException::notUndoable('The Owner role always has full access: it cannot be changed.');
        }
    }

    /** An edit put back, if every field still holds what the edit gave it. */
    private function revert(AuditLog $log, Model $model): void
    {
        $changes = collect($log->field_changes ?? [])->except(self::NOT_FIELDS);
        $permissions = $model instanceof Role ? $changes->only(array_keys(Permissions::resources())) : collect();
        $columns = $changes->except($permissions->keys()->all());
        $labels = ChangeHistory::fieldLabels($log->table_name);
        $known = Schema::getColumnListing($model->getTable());

        $current = $model->getAttributes();
        $back = [];
        foreach ($columns as $field => $change) {
            if (! in_array($field, $known, true)) {
                throw UndoException::notUndoable(($labels[$field] ?? $field).' can\'t be changed back from here.');
            }
            if (self::same($current[$field] ?? null, $change['new'] ?? null) === false) {
                throw UndoException::changedSince($labels[$field] ?? $field);
            }
            $back[$field] = $change['old'] ?? null;
        }

        if ($back) {
            // Raw values, as they were logged (dates, JSON and flags as stored).
            $model->setRawAttributes(array_merge($current, $back));
            try {
                $model->save();
            } catch (QueryException $e) {
                if ($e->getCode() === '23505') {
                    throw UndoException::conflict(ChangeHistory::recordType($log->table_name));
                }
                throw $e;
            }
        }

        if ($permissions->isNotEmpty()) {
            /** @var Role $model */
            $this->revertPermissions($model, $permissions->all(), $labels);
        }
    }

    /**
     * A role's permissions put back page by page: each page must still have
     * the actions the change gave it ("View, Update", "No access").
     *
     * @param  array<string, array{old: ?string, new: ?string}>  $changes
     * @param  array<string, string>  $labels
     */
    private function revertPermissions(Role $role, array $changes, array $labels): void
    {
        $names = $role->permissions()->pluck('name')->all();
        $byLabel = array_flip(Permissions::ACTION_LABELS);
        $actions = fn (?string $text) => $text === null || $text === 'No access' ? [] : array_map(fn ($l) => $byLabel[trim($l)], explode(',', $text));
        $describe = function (array $names, string $resource): string {
            $have = array_filter(Permissions::ACTIONS, fn (string $a) => in_array("{$a}_{$resource}", $names, true));

            return $have ? implode(', ', array_map(fn ($a) => Permissions::ACTION_LABELS[$a], $have)) : 'No access';
        };

        $after = $names;
        $logged = [];
        foreach ($changes as $resource => $change) {
            if ($describe($names, $resource) !== ($change['new'] ?? 'No access')) {
                throw UndoException::changedSince($labels[$resource] ?? $resource);
            }
            $after = array_values(array_diff($after, array_map(fn ($a) => "{$a}_{$resource}", Permissions::ACTIONS)));
            $after = [...$after, ...array_map(fn ($a) => "{$a}_{$resource}", $actions($change['old'] ?? null))];
            $logged[$resource] = ['old' => $change['new'] ?? 'No access', 'new' => $change['old'] ?? 'No access'];
        }

        Permissions::ensureExist($after);
        $role->syncPermissions($after);
        $role->logAuditChange('UPDATE', $logged, ['name' => $role->name]);
    }

    /** A deleted record brought back (its parent must still exist; a keyword needs room in the plan). */
    private function restore(AuditLog $log, Model $model): void
    {
        if (! method_exists($model, 'trashed') || ! $model->trashed()) {
            throw UndoException::notUndoable('It is already back.');
        }
        if ($model instanceof Site && $model->client()->withTrashed()->first()?->trashed()) {
            throw UndoException::notUndoable('Its client was deleted: undo that first.');
        }
        if ($model instanceof RankKeyword) {
            if ($model->site()->withTrashed()->first()?->trashed()) {
                throw UndoException::notUndoable('Its site was deleted: undo that first.');
            }
            if ($model->is_active) {
                $this->limits->ensureRoomFor((int) $model->workspace_id, 1, 'undo');
            }
        }

        try {
            $model->restore();
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw UndoException::conflict(ChangeHistory::recordType($log->table_name));
            }
            throw $e;
        }
    }

    /** Whether a stored value matches a logged one (flags, numbers and JSON as text). */
    public static function same(mixed $a, mixed $b): bool
    {
        return self::normal($a) === self::normal($b);
    }

    private static function normal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return json_encode($value);
        }
        $text = (string) $value;
        if ($text !== '' && ($text[0] === '[' || $text[0] === '{')) {
            $decoded = json_decode($text, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded);
            }
        }

        return match ($text) {
            't', 'true' => '1',
            'f', 'false' => '0',
            default => $text,
        };
    }
}
