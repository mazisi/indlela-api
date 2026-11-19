<?php

// One file per area. Every route, and the permission it needs, is listed in
// docs/API.md — update it whenever a route is added or changed.
//
// Authenticated routes use ['auth:sanctum', 'active', 'workspace']: `workspace`
// puts the request in the user's current workspace (roles, permissions and
// the BelongsToWorkspace scope), before route model binding runs.
require __DIR__.'/auth.php';
require __DIR__.'/workspaces.php';
require __DIR__.'/users.php';
require __DIR__.'/settings.php';
require __DIR__.'/clients.php';
