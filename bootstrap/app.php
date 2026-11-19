<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetCurrentWorkspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'workspace' => SetCurrentWorkspace::class,
        ]);

        // Route model binding must run inside the workspace: `active` and
        // `workspace` (after auth:sanctum) go before SubstituteBindings, so
        // another workspace's id 404s through the BelongsToWorkspace scope
        // and the user/role bindings.
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureUserIsActive::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, SetCurrentWorkspace::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Requests check uniqueness before saving; two saves of the same value at
        // the same moment can both pass, and the database index stops the second.
        $exceptions->render(fn (UniqueConstraintViolationException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'Someone saved the same details a moment ago. Refresh the page and check before saving again.'], 409)
            : null);
    })->create();
