<?php

namespace App\Services\Auth;

use App\Enums\LoginEventType;
use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Writes Settings › Login history. Every auth outcome — good or bad — goes
 * through here so the history is complete.
 */
class LoginEventLogger
{
    public function __construct(private readonly Request $request) {}

    public function record(LoginEventType $event, User|string $subject, ?string $note = null): LoginEvent
    {
        $user = $subject instanceof User ? $subject : null;

        return LoginEvent::create([
            'user_id' => $user?->id,
            'email' => strtolower($user?->email ?? (string) $subject),
            'event' => $event,
            'note' => $note,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ]);
    }
}
