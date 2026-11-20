<?php

namespace App\Support;

/**
 * Set while Settings › Change history undoes an entry, so every audit entry
 * the undo writes records which entry it reverted (one global, not a
 * static on the Auditable trait, which PHP copies into each model).
 */
final class AuditContext
{
    public static ?int $undoOf = null;

    /** @template T  @param  callable(): T  $callback  @return T */
    public static function undoing(int $auditLogId, callable $callback): mixed
    {
        $previous = self::$undoOf;
        self::$undoOf = $auditLogId;
        try {
            return $callback();
        } finally {
            self::$undoOf = $previous;
        }
    }
}
