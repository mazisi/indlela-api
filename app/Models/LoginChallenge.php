<?php

namespace App\Models;

use App\Enums\MfaChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sign-in waiting for its one-time PIN. See LoginChallengeService.
 */
class LoginChallenge extends Model
{
    protected $fillable = [
        'user_id',
        'token_hash',
        'code_hash',
        'channel',
        'attempts',
        'resends',
        'sent_at',
        'expires_at',
        'consumed_at',
        'ip_address',
    ];

    protected $hidden = ['token_hash', 'code_hash'];

    protected function casts(): array
    {
        return [
            'channel' => MfaChannel::class,
            'attempts' => 'integer',
            'resends' => 'integer',
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('consumed_at');
    }
}
