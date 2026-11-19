<?php

namespace App\Http\Resources;

use App\Models\LoginEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoginEvent
 */
class LoginEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'at' => $this->created_at->toIso8601String(),
            'email' => $this->email,
            'user' => $this->user?->displayName(),
            'event' => $this->event->value,
            'event_label' => $this->event->label(),
            'note' => $this->note,
            'ip_address' => $this->ip_address,
        ];
    }
}
