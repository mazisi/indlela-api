<?php

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A role with its permissions. Load `permissions` and `users_count`.
 *
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            // Owner: always has full access; can't be renamed, changed or deleted.
            'is_system' => $this->isSystem(),
            // Members see every client of the workspace, not only their assigned ones.
            'all_clients' => $this->isSystem() || (bool) $this->all_clients,
            'users_count' => $this->users_count ?? 0,
            'permissions' => $this->permissions->pluck('name')->sort()->values(),
        ];
    }
}
