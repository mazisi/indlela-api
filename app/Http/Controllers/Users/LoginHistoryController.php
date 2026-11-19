<?php

namespace App\Http\Controllers\Users;

use App\Enums\LoginEventType;
use App\Http\Controllers\Controller;
use App\Http\Resources\LoginEventResource;
use App\Models\LoginEvent;
use App\Models\User;
use App\Support\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Settings › Login history: the sign-in events of the current workspace's
 * members (sign-ins are the account's, so a shared account's events show in
 * each of its workspaces; unknown-email attempts show in none).
 */
class LoginHistoryController extends Controller
{
    public function index(Request $request, CurrentWorkspace $current): AnonymousResourceCollection
    {
        Gate::authorize('view_settings_login_history');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', Rule::enum(LoginEventType::class)],
            'user_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $events = LoginEvent::with('user')
            ->whereIn('user_id', User::inWorkspace($current->id())->select('users.id'))
            ->when($filters['search'] ?? null, function ($q, string $search) {
                // Name or email, case-insensitive; the user's own % and _ are literal.
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
                $q->where(fn ($q) => $q
                    ->whereRaw('LOWER(email) LIKE ?', [$like])
                    ->orWhereHas('user', fn ($u) => $u
                        ->whereRaw("LOWER(full_name || ' ' || COALESCE(surname, '')) LIKE ?", [$like])));
            })
            ->when($filters['event'] ?? null, fn ($q, $e) => $q->where('event', $e))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->latest('created_at')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15);

        return LoginEventResource::collection($events);
    }
}
