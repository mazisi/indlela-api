<?php

namespace App\Http\Controllers\SearchConsole;

use App\Exceptions\SearchConsoleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\GoogleConnectionResource;
use App\Services\SearchConsole\GoogleConnectionService;
use App\Services\SearchConsole\SearchConsoleApi;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The workspace's Google account for Search Console: one-click OAuth
 * (connect → Google → callback), status, its properties, disconnect.
 */
class GoogleConnectionController extends Controller
{
    public function __construct(private readonly GoogleConnectionService $connections) {}

    public function show(): JsonResponse
    {
        Gate::authorize('view_search_console');
        $connection = $this->connections->current();

        return response()->json(['data' => $connection ? GoogleConnectionResource::make($connection) : null]);
    }

    /** The Google consent URL to send the browser to. */
    public function connect(Request $request, CurrentWorkspace $current): JsonResponse
    {
        Gate::authorize('update_search_console');

        return response()->json(['url' => $this->connections->connectUrl($request->user(), $current->get())]);
    }

    /** Public: Google redirects the browser here. Always ends on the SPA's Search Console page. */
    public function callback(Request $request): RedirectResponse
    {
        try {
            $this->connections->completeCallback($request->query('code'), $request->query('state'), $request->query('error'));
            $query = ['google' => 'connected'];
        } catch (SearchConsoleException $e) {
            $query = ['google' => 'error', 'message' => $e->getMessage()];
        }

        return redirect()->away(rtrim(config('app.frontend_url'), '/').'/dashboard/search-console?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public function destroy(): Response
    {
        Gate::authorize('update_search_console');

        if ($connection = $this->connections->current()) {
            $this->connections->disconnect($connection);
        }

        return response()->noContent();
    }

    /** The properties the connected account can read, to link sites to. */
    public function properties(SearchConsoleApi $api): JsonResponse
    {
        Gate::authorize('update_search_console');

        return response()->json(['data' => $api->properties($this->connections->requireActive())]);
    }
}
