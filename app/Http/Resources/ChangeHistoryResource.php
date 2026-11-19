<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One audit_logs row as "who changed what, and when" for a page's History drawer.
 *
 * @mixin AuditLog
 */
class ChangeHistoryResource extends JsonResource
{
    /** Bookkeeping columns nobody needs to see in a change list; `_label` only names the record. */
    private const HIDDEN_FIELDS = ['id', 'workspace_id', 'created_at', 'updated_at', 'deleted_at', '_label'];

    public function toArray(Request $request): array
    {
        $changes = collect($this->field_changes ?? [])
            ->except(self::HIDDEN_FIELDS)
            ->map(fn ($change, $field) => ['field' => $field, 'old' => $change['old'] ?? null, 'new' => $change['new'] ?? null])
            ->values();

        return [
            'id' => $this->id,
            'at' => $this->timestamp->toIso8601String(),
            'action' => strtolower($this->action_type),
            'user' => trim((string) $this->user_name) ?: 'System',
            'record_id' => $this->record_id,
            'record_label' => $this->recordLabel(),
            'changes' => $changes,
        ];
    }

    /**
     * The record's name: a model's own `_label`, else `name` (with its client
     * when the record belongs to one, e.g. "acme.co.za · Acme"), or a
     * person's full name and surname.
     */
    private function recordLabel(): ?string
    {
        foreach ([$this->new_values ?? [], $this->old_values ?? []] as $values) {
            if (! empty($values['_label'])) {
                return $values['_label'];
            }
            if (! empty($values['name'])) {
                return empty($values['client']) ? $values['name'] : $values['name'].' · '.$values['client'];
            }
            if (! empty($values['full_name'])) {
                return trim($values['full_name'].' '.($values['surname'] ?? ''));
            }
        }

        return null;
    }
}
