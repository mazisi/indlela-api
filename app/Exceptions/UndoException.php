<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Settings › Change history's Undo can't go ahead: 409 `{message, error_type}`
 * (`not_undoable`, `changed_since`, `record_gone`, `undo_conflict`).
 */
class UndoException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorType)
    {
        parent::__construct($message);
    }

    public static function notUndoable(string $reason): self
    {
        return new self($reason, 'not_undoable');
    }

    public static function changedSince(string $field): self
    {
        return new self("{$field} has changed again since. Undo the later change first.", 'changed_since');
    }

    public static function gone(?string $type): self
    {
        return new self('The '.mb_strtolower($type ?? 'record').' no longer exists, so this can\'t be undone.', 'record_gone');
    }

    public static function conflict(?string $type): self
    {
        return new self('Another '.mb_strtolower($type ?? 'record').' already uses those details, so this can\'t be put back.', 'undo_conflict');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'error_type' => $this->errorType], 409);
    }
}
