<?php

namespace App\Traits;

use App\Models\AuditLog;
use App\Support\AuditContext;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

trait Auditable
{
    protected static function bootAuditable()
    {
        // Hook into model events
        static::created(function ($model) {
            $model->auditCreate();
        });

        static::updated(function ($model) {
            $model->auditUpdate();
        });

        static::deleted(function ($model) {
            $model->auditDelete();
        });

        // A soft-deleted record brought back (Settings › Change history's undo).
        if (method_exists(static::class, 'restored')) {
            static::restored(function ($model) {
                $model->createAuditLog('RESTORE', [], $model->withoutExcludedAudit($model->transformAttributesForAudit($model->getAttributes())));
            });
        }
    }

    protected function auditCreate()
    {
        $attributes = $this->getAttributes();

        // Allow models to transform attributes before auditing
        $attributes = $this->withoutExcludedAudit($this->transformAttributesForAudit($attributes));

        $fieldChanges = [];

        // Build field changes for CREATE - old value is null, new value is the created value
        foreach ($attributes as $field => $newValue) {
            // Skip timestamps if you don't want to track them
            if (in_array($field, ['created_at', 'updated_at'])) {
                continue;
            }

            $fieldChanges[$field] = [
                'old' => null,
                'new' => $newValue,
            ];
        }

        $fieldChanges += $this->extraAuditChanges();

        $this->createAuditLog('CREATE', $fieldChanges, $attributes);
    }

    protected function auditUpdate()
    {
        $changes = $this->getChanges();
        $original = $this->getOriginal();

        unset($changes['updated_at']);
        $changes = $this->withoutExcludedAudit($changes);
        // Changes outside the logged columns (a pivot, an excluded column shown another way).
        $extra = $this->extraAuditChanges();

        if (empty($changes) && empty($extra)) {
            return;
        }

        // Transform attributes before auditing
        $newAttributes = $this->withoutExcludedAudit($this->transformAttributesForAudit($this->getAttributes()));
        $oldAttributes = $this->withoutExcludedAudit($this->transformAttributesForAudit($original));

        $fieldChanges = [];
        foreach ($changes as $field => $newValue) {
            $fieldChanges[$field] = [
                'old' => $oldAttributes[$field] ?? null,
                'new' => $newAttributes[$field] ?? null,
            ];
        }

        $fieldChanges += $extra;

        $this->createAuditLog('UPDATE', $fieldChanges, $newAttributes, $oldAttributes);
    }

    protected function auditDelete()
    {
        // For DELETE operations, all existing values are being removed
        $attributes = $this->getAttributes();

        // Transform attributes before auditing
        $attributes = $this->withoutExcludedAudit($this->transformAttributesForAudit($attributes));

        $fieldChanges = [];

        // Build field changes for DELETE - old value is the deleted value, new value is null
        foreach ($attributes as $field => $oldValue) {
            // Skip timestamps if you don't want to track them
            if (in_array($field, ['created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            $fieldChanges[$field] = [
                'old' => $oldValue,
                'new' => null,
            ];
        }

        // Capture pivot relationships before deletion
        $pivotSnapshots = $this->capturePivotRelationships();
        if (! empty($pivotSnapshots)) {
            $attributes['_pivot_relationships'] = $pivotSnapshots;
        }

        $this->createAuditLog('DELETE', $fieldChanges, [], $attributes);
    }

    /**
     * Log a change the model events can't see (e.g. a role's permissions,
     * which live in a pivot table), in the same shape as the automatic entries.
     */
    public function logAuditChange(string $action, array $fieldChanges, array $newValues = [], array $oldValues = []): void
    {
        $this->createAuditLog($action, $fieldChanges, $newValues, $oldValues);
    }

    protected function createAuditLog(string $action, array $fieldChanges = [], array $newValues = [], array $oldValues = [])
    {
        try {
            $user = Auth::user();
            $request = Request::instance();

            AuditLog::create([
                'event_id' => Str::uuid()->toString(),
                'timestamp' => now(),
                'user_id' => $user?->id,
                'user_name' => $user ? trim($user->full_name.' '.$user->surname) : 'System',
                'action_type' => $action,
                'table_name' => $this->getTable(),
                'record_id' => $this->getKey(),
                'field_changes' => $fieldChanges,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'device_browser' => $this->parseUserAgent($request->userAgent()),
                'workspace_id' => $this->auditWorkspaceId(),
                'undo_of' => AuditContext::$undoOf,
            ]);
        } catch (\Exception $e) {
            Log::error('Audit logging failed: '.$e->getMessage());
        }
    }

    protected function parseUserAgent(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        if (strpos($userAgent, 'Chrome') !== false) {
            preg_match('/Chrome\/([0-9.]+)/', $userAgent, $matches);

            return 'Chrome/'.($matches[1] ?? 'Unknown');
        } elseif (strpos($userAgent, 'Firefox') !== false) {
            preg_match('/Firefox\/([0-9.]+)/', $userAgent, $matches);

            return 'Firefox/'.($matches[1] ?? 'Unknown');
        } elseif (strpos($userAgent, 'Safari') !== false && strpos($userAgent, 'Chrome') === false) {
            preg_match('/Version\/([0-9.]+).*Safari/', $userAgent, $matches);

            return 'Safari/'.($matches[1] ?? 'Unknown');
        } elseif (strpos($userAgent, 'Edge') !== false) {
            preg_match('/Edge\/([0-9.]+)/', $userAgent, $matches);

            return 'Edge/'.($matches[1] ?? 'Unknown');
        }

        return 'Unknown';
    }

    public function withoutAuditing(callable $callback)
    {
        $this->auditing = false;
        $result = $callback();
        $this->auditing = true;

        return $result;
    }

    protected function isAuditingEnabled(): bool
    {
        return $this->auditing ?? true;
    }

    /**
     * The workspace the entry belongs to, so a workspace only ever reads its
     * own history: the record's own workspace_id, else (e.g. a user's account,
     * which can be in several workspaces) the workspace the change was made in.
     */
    protected function auditWorkspaceId(): ?int
    {
        $id = $this->getAttribute('workspace_id') ?? app(CurrentWorkspace::class)->id();

        return $id === null ? null : (int) $id;
    }

    /**
     * Columns never written to the audit trail: secrets, and bookkeeping that
     * changes on its own (e.g. a user's last sign-in). An update touching only
     * these columns logs nothing. Models override this to add their own.
     *
     * @return list<string>
     */
    protected function auditExclude(): array
    {
        return ['password', 'remember_token'];
    }

    private function withoutExcludedAudit(array $attributes): array
    {
        // deleted_at: deleting and restoring are entries of their own, not a field change.
        return array_diff_key($attributes, array_flip([...$this->auditExclude(), 'deleted_at']));
    }

    /**
     * Transform attributes before auditing.
     * Models can override this method to customize how attributes are logged.
     * Useful for replacing base64 data with file URLs, redacting sensitive info, etc.
     */
    protected function transformAttributesForAudit(array $attributes): array
    {
        // Default implementation - return attributes as-is
        // Models can override this to customize audit data
        return $attributes;
    }

    /**
     * Changes outside the model's own columns (e.g. a pivot table) to log in
     * the same entry as the save, as field => ['old' => …, 'new' => …].
     */
    protected function extraAuditChanges(): array
    {
        return [];
    }

    /**
     * Capture pivot relationships before deletion for restoration.
     * Models can override this method to define which pivot relationships to capture.
     *
     * @return array Array of pivot relationship snapshots in format:
     *               ['relationship_name' => ['pivot_table' => 'table_name', 'ids' => [1, 2, 3], 'pivot_data' => [...]]]
     */
    protected function capturePivotRelationships(): array
    {
        // Default implementation - return empty array
        // Models can override this to capture their specific pivot relationships
        return [];
    }
}
