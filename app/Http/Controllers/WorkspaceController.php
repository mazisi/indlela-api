<?php

namespace App\Http\Controllers;

use App\Enums\LoginEventType;
use App\Http\Resources\ChangeHistoryResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\AuditLog;
use App\Models\Workspace;
use App\Services\Auth\LoginEventLogger;
use App\Services\Workspaces\WorkspaceContext;
use App\Services\Workspaces\WorkspaceProvisioner;
use App\Support\AccountState;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The workspace switcher (any member) and Settings › Workspace
 * (`*_settings_workspace`, Owners).
 */
class WorkspaceController extends Controller
{
    /** The workspaces the user belongs to, with their role in each. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => AccountState::workspaces($request->user())]);
    }

    /**
     * Another workspace (e.g. an agency adding a second brand), with the user
     * as its Owner; they switch to it. Same body as GET /me.
     */
    public function store(Request $request, WorkspaceProvisioner $provisioner, WorkspaceContext $context): JsonResponse
    {
        $name = $request->validate(['name' => ['required', 'string', 'min:2', 'max:100']])['name'];
        $user = $request->user();

        $provisioner->create(preg_replace('/\s+/', ' ', trim($name)), $user);
        $context->enter($user->refresh());

        return response()->json(AccountState::for($user), 201);
    }

    /** Make another of the user's workspaces current; same body as GET /me, same token. */
    public function switch(Request $request, Workspace $workspace, WorkspaceContext $context, LoginEventLogger $events): JsonResponse
    {
        $user = $request->user();
        // Someone else's workspace reads as missing, not forbidden.
        abort_unless($user->belongsToWorkspace($workspace), 404);

        $context->switchTo($user, $workspace);
        $events->record(LoginEventType::SwitchedWorkspace, $user, "To {$workspace->name}");

        return response()->json(AccountState::for($user));
    }

    /** Settings › Workspace. */
    public function show(CurrentWorkspace $current): WorkspaceResource
    {
        Gate::authorize('view_settings_workspace');

        return WorkspaceResource::make($current->get());
    }

    /**
     * Rename the current workspace and/or set its white-label branding:
     * `name`, `logo` (image, ≤ 2 MB) or `remove_logo`, `brand_color` (#RRGGBB
     * or null for the default).
     */
    public function update(Request $request, CurrentWorkspace $current): WorkspaceResource
    {
        Gate::authorize('update_settings_workspace');

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'logo' => ['sometimes', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'remove_logo' => ['sometimes', 'boolean'],
            'brand_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], ['brand_color.regex' => 'Choose a colour as #RRGGBB, e.g. #1f4e79.']);
        $workspace = $current->get();

        if (isset($data['name'])) {
            $workspace->name = preg_replace('/\s+/', ' ', trim($data['name']));
        }
        if (array_key_exists('brand_color', $data)) {
            $workspace->brand_color = $data['brand_color'] === null ? null : strtolower($data['brand_color']);
        }
        $old = $workspace->logo_path;
        if ($request->hasFile('logo') || ($data['remove_logo'] ?? false)) {
            $path = $request->hasFile('logo') ? $request->file('logo')->store("workspaces/{$workspace->id}") : null;
            // The s3 disk doesn't throw: a failed write returns false (no row pointing at a missing file).
            if ($path === false) {
                throw new RuntimeException('The logo could not be stored.');
            }
            $workspace->logo_path = $path;
        }
        $workspace->save();
        if ($old && $old !== $workspace->logo_path) {
            Storage::delete($old);
        }

        return WorkspaceResource::make($workspace);
    }

    /** The page's History drawer. */
    public function history(Request $request, CurrentWorkspace $current): AnonymousResourceCollection
    {
        Gate::authorize('view_settings_workspace');
        $perPage = $request->validate(['per_page' => ['nullable', 'integer', 'min:10', 'max:100']])['per_page'] ?? 15;

        return ChangeHistoryResource::collection(
            AuditLog::forWorkspace($current->id())->forTable('workspaces')->where('record_id', (string) $current->id())
                ->latest('timestamp')->latest('id')->paginate($perPage),
        );
    }
}
