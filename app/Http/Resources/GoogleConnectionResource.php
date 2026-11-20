<?php

namespace App\Http\Resources;

use App\Models\GoogleConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The workspace's Google account (never its tokens).
 *
 * @mixin GoogleConnection
 */
class GoogleConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'google_email' => $this->google_email,
            'status' => $this->status,
            'last_error' => $this->last_error,
            'connected_by' => $this->connectedBy ? ['id' => $this->connectedBy->id, 'name' => $this->connectedBy->displayName()] : null,
            'connected_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
