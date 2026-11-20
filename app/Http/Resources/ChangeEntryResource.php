<?php

namespace App\Http\Resources;

use App\Mail\EmailTemplateType;
use App\Models\AuditLog;
use App\Services\ChangeHistory\UndoChange;
use App\Support\ChangeHistory;
use App\Support\Permissions;
use Illuminate\Http\Request;

/**
 * One audit_logs row on Settings › Change history: a page's History entry
 * plus the page, the kind of record, the labelled fields, and whether it
 * can be undone (`undo.available`, else `undo.reason`). Only the fields the
 * catalogue labels are listed; the rest is bookkeeping.
 *
 * @mixin AuditLog
 */
class ChangeEntryResource extends ChangeHistoryResource
{
    public function toArray(Request $request): array
    {
        $resource = ChangeHistory::resource($this->table_name);
        $labels = ChangeHistory::fieldLabels($this->table_name);
        $entry = parent::toArray($request);
        $blocked = UndoChange::blocked($this->resource);
        $canUndo = (bool) $request->user()?->can('update_settings_change_history');

        return [
            ...$entry,
            'record_label' => $entry['record_label'] ?? $this->fallbackLabel(),
            'changes' => collect($entry['changes'])
                ->filter(fn (array $c) => isset($labels[$c['field']]))
                // In the catalogue's order (the record's name first), not the table's column order.
                ->sortBy(fn (array $c) => array_search($c['field'], array_keys($labels), true))
                ->map(fn (array $c) => [
                    ...$c,
                    'old' => $this->readable($c['field'], $c['old']),
                    'new' => $this->readable($c['field'], $c['new']),
                    'label' => $labels[$c['field']],
                ])
                ->values(),
            'page' => $resource ? Permissions::resources()[$resource]['label'] : null,
            'page_key' => $resource,
            'record_type' => ChangeHistory::recordType($this->table_name),
            'undo' => [
                'available' => $canUndo && $blocked === null,
                'reason' => $this->undone_at ? null : ($canUndo ? $blocked : null),
            ],
            'undone_at' => $this->undone_at?->toIso8601String(),
            'undone_by' => $this->undone_at ? ($this->undoneBy?->displayName() ?? 'Unknown user') : null,
            'undo_of' => $this->undo_of,
        ];
    }

    /** Records named by another field (a keyword, a report's title, a Google account), or an email by its type. */
    private function fallbackLabel(): ?string
    {
        foreach ([$this->new_values ?? [], $this->old_values ?? []] as $values) {
            foreach (['keyword', 'title', 'google_email'] as $field) {
                if (! empty($values[$field]) && is_string($values[$field])) {
                    return $values[$field];
                }
            }
        }
        if ($this->table_name !== 'email_templates') {
            return null;
        }
        $type = $this->new_values['type'] ?? $this->old_values['type'] ?? null;

        return $type ? EmailTemplateType::tryFrom($type)?->label() : null;
    }

    /** Stored values as people read them: an email's type by name, its message as text. */
    private function readable(string $field, mixed $value): mixed
    {
        if ($this->table_name !== 'email_templates' || ! is_string($value)) {
            return $value;
        }

        return match ($field) {
            'type' => EmailTemplateType::tryFrom($value)?->label() ?? $value,
            'body' => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], ' ', $value))))),
            default => $value,
        };
    }
}
