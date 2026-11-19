<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Global (not per workspace): the catalogue in App\Support\Permissions.
 */
class Permission extends SpatiePermission
{
    protected $guard_name = 'web';
}
