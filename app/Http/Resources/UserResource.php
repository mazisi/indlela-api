<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row in Settings › Users and access (current workspace). Load `roles`
 * and `assignedClients`.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = $this->roles->first();

        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'surname' => $this->surname,
            'name' => $this->displayName(),
            'email' => $this->email,
            'mobile' => $this->mobile,
            'role' => $role ? ['id' => $role->id, 'name' => $role->name, 'is_system' => $role->isSystem(), 'all_clients' => $role->isSystem() || $role->all_clients] : null,
            'clients' => $this->assignedClients->sortBy('name')->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'mfa_channel' => $this->mfa_channel?->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'locked_at' => $this->locked_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            // Also in another workspace: their account details and status are theirs to change.
            'shared_account' => (bool) ($this->other_workspaces_count ?? 0),
            'is_you' => $request->user()?->is($this->resource) ?? false,
        ];
    }
}
