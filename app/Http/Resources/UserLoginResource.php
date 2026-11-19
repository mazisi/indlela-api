<?php

namespace App\Http\Resources;

use App\Enums\LoginEventType;
use App\Models\Client;
use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user as the SPA stores it (ported from workforce-api:
 * companies → clients of the current workspace). `sees_all_clients` drives
 * an "All clients" option in a client picker. Built in the current workspace.
 *
 * @mixin User
 */
class UserLoginResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $seesAll = $this->resource->seesAllClients();

        return [
            'id' => $this->id,
            'name' => $this->displayName(),
            'full_name' => $this->full_name,
            'surname' => $this->surname ?? '',
            'email' => $this->email,
            'mobile' => $this->mobile,
            'mfa_channel' => $this->mfa_channel->value,
            // The role in the current workspace.
            'role' => $this->resource->currentRole()?->name,
            // The sign-in before this one: what a user checks to spot an
            // unexpected sign-in. (last_login_at is already "now" by the time
            // this resource is built at login.)
            'previous_login_at' => LoginEvent::where('user_id', $this->id)
                ->where('event', LoginEventType::SignedIn)
                ->latest('created_at')
                ->latest('id')
                ->skip(1)
                ->value('created_at')?->toIso8601String(),
            'current_workspace_id' => $this->current_workspace_id,
            'sees_all_clients' => $seesAll,
            // The clients this user can pick: every active client of the
            // workspace for sees-all roles, otherwise their assignments.
            'clients' => ($seesAll ? Client::active()->orderBy('name')->get() : $this->resource->assignedClients()->orderBy('name')->get())
                ->map(fn (Client $client) => ['id' => $client->id, 'name' => $client->name])
                ->values(),
        ];
    }
}
