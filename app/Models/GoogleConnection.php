<?php

namespace App\Models;

use App\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A workspace's Google account for Search Console (one per workspace).
 * Tokens are encrypted at rest and never serialised.
 */
class GoogleConnection extends Model
{
    use BelongsToWorkspace;

    public const ACTIVE = 'active';

    public const ERROR = 'error';

    public const REVOKED = 'revoked';

    protected $fillable = [
        'workspace_id', 'connected_by', 'google_email', 'access_token', 'refresh_token',
        'token_expires_at', 'scopes', 'status', 'last_error',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
