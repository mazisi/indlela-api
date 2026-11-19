<?php

namespace App\Http\Middleware;

use App\Services\Workspaces\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `workspace`, after `auth:sanctum` and `active` on every
 * authenticated route: puts the request in the user's current workspace
 * (spatie team id, BelongsToWorkspace scope, audit entries). A user removed
 * from their current workspace lands in another of theirs; one in none gets
 * 403 `no_workspace`.
 */
class SetCurrentWorkspace
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $this->context->enter($user)) {
            return response()->json([
                'message' => 'This account is not in any workspace any more. Ask a workspace Owner to add you again.',
                'error_type' => 'no_workspace',
            ], 403);
        }

        return $next($request);
    }
}
