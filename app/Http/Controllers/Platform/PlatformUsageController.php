<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\Platform\PlatformUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Platform › Usage (Indlela staff): the company's DataForSEO spend and every
 * workspace's rank-tracking usage. Only in the platform workspace, for users
 * with view_platform_usage there (the `view-platform-usage` gate,
 * AppServiceProvider); 403 for everyone else.
 */
class PlatformUsageController extends Controller
{
    public function __construct(private readonly PlatformUsage $usage) {}

    public function overview(): JsonResponse
    {
        Gate::authorize('view-platform-usage');

        return response()->json(['data' => $this->usage->overview()]);
    }

    /** Laravel's resource pagination shape (data, links, meta); rows are built by PlatformUsage. */
    public function workspaces(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('view-platform-usage');

        $sorts = array_keys(PlatformUsage::SORTS);
        $params = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in([...$sorts, ...array_map(fn (string $s) => "-{$s}", $sorts)])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        return JsonResource::collection($this->usage->workspaces($params));
    }

    /** A workspace's own keyword limit (null = the default), e.g. raising a beta tester's. Audited on the workspace. */
    public function updateLimits(Request $request, int $workspace): JsonResponse
    {
        Gate::authorize('view-platform-usage');

        $data = $request->validate(['rank_keyword_limit' => ['present', 'nullable', 'integer', 'min:10', 'max:100000']]);
        $model = Workspace::query()->findOrFail($workspace);
        $model->forceFill(['rank_keyword_limit' => $data['rank_keyword_limit']])->save();

        return response()->json(['data' => $this->usage->row($model->id)]);
    }
}
