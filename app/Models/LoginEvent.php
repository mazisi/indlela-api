<?php

namespace App\Models;

use App\Enums\LoginEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row in Settings › Login history. Append-only.
 */
class LoginEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'email',
        'event',
        'note',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'event' => LoginEventType::class,
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
