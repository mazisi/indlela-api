<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A token outlives the account's status: when an administrator disables a
 * user, or five wrong passwords lock them, their open sessions must stop too.
 * Revoke the token and answer 401 so the FE's interceptor signs them out.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => $user->status->loginMessage() ?: 'Your session has ended.',
                'error_type' => 'account_'.$user->status->value,
            ], 401);
        }

        return $next($request);
    }
}
