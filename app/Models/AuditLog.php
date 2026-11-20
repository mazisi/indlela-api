<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change in a page's History drawer (written by App\Traits\Auditable).
 * Always read through forWorkspace(): entries carry the workspace they
 * belong to.
 */
class AuditLog extends Model
{
    protected $fillable = [
        'event_id',
        'timestamp',
        'workspace_id',
        'user_id',
        'user_name',
        'action_type',
        'table_name',
        'record_id',
        'field_changes',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'device_browser',
        'undone_at',
        'undone_by',
        'undo_of',
    ];

    protected $casts = [
        'field_changes' => 'array',
        'old_values' => 'array',
        'new_values' => 'array',
        'timestamp' => 'datetime',
        'undone_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Who undid this entry (Settings › Change history). */
    public function undoneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by');
    }

    public function scopeForWorkspace(Builder $query, ?int $workspaceId): Builder
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeForTable(Builder $query, string $table): Builder
    {
        return $query->where('table_name', $table);
    }
}
