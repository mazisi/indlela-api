<?php

namespace App\Support;

use App\Models\Workspace;

/**
 * The Indlela staff workspace (config indlela.platform_workspace, a slug;
 * empty = none). Only there do the platform pages exist
 * (Permissions::PLATFORM_ONLY: the view_platform_usage permission, the
 * Superuser role, cross-workspace figures). Anywhere else they are absent,
 * whatever a role holds.
 */
final class PlatformWorkspace
{
    public static function slug(): ?string
    {
        $slug = config('indlela.platform_workspace');

        return is_string($slug) && trim($slug) !== '' ? trim($slug) : null;
    }

    /** Whether $workspace is the platform workspace (false when the feature is off). */
    public static function is(?Workspace $workspace): bool
    {
        $slug = self::slug();

        return $slug !== null && $workspace !== null && $workspace->slug === $slug;
    }

    /** Whether the request (or job) acts in the platform workspace. */
    public static function isCurrent(): bool
    {
        return self::is(app(CurrentWorkspace::class)->get());
    }

    /** The platform workspace, or null (off, or no workspace has the slug). */
    public static function find(): ?Workspace
    {
        $slug = self::slug();

        return $slug === null ? null : Workspace::query()->where('slug', $slug)->first();
    }
}
