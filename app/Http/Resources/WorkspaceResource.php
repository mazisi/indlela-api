<?php

namespace App\Http\Resources;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The current workspace as the SPA keeps it: `{id, name, slug, logo_url, brand_color}`
 * (brand_color: the reports' accent, null = the default navy).
 * The switcher's list (AccountState::workspaces) adds the user's `role` there.
 *
 * @mixin Workspace
 */
class WorkspaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'logo_url' => $this->resource->logoUrl(),
            'brand_color' => $this->brand_color,
        ];
    }
}
