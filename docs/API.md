# Indlela API reference

Every route, the permission it needs, and the error contract. Base URL `/api`; authenticated routes take a Sanctum Bearer token and act in the user's **current workspace** (route middleware `auth:sanctum, active, workspace`). Paginated lists default to 15 per page (`per_page` 10–100) in Laravel's resource pagination shape (`data`, `links`, `meta`). Update this file with every route change.

## Tenancy in one paragraph

A user belongs to one or more workspaces and acts in `users.current_workspace_id`. Roles are per workspace (spatie teams), so `roles` and `permissions` in every response below are the user's in the current workspace. Clients, sites, roles, users (members), email templates and change history are always the current workspace's: an id from another workspace answers **404**, never 403, and is never listed. Users whose role doesn't see every client (`all_clients` false: Analyst, Client) only see their assigned clients and those clients' sites (403 on others in the same workspace).

## Session payload

`POST /login` (when no PIN is needed), `POST /login/verify-pin` and `POST /register` (201) return:

```json
{
  "message": "Login successful",
  "requires_mfa": false,
  "user": {
    "id": 1, "name": "Thandi Mokoena", "full_name": "Thandi", "surname": "Mokoena",
    "email": "thandi@acme.co.za", "mobile": "0825550199", "mfa_channel": "email",
    "role": "Owner", "previous_login_at": "2026-11-18T09:12:00+02:00",
    "current_workspace_id": 3, "sees_all_clients": true,
    "clients": [{ "id": 7, "name": "Karoo Coffee" }]
  },
  "token": "1|…",
  "token_type": "Bearer",
  "roles": ["Owner"],
  "permissions": ["view_dashboard", "view_clients_clients", "…"],
  "workspace": { "id": 3, "name": "Acme Digital", "slug": "acme-digital", "logo_url": null },
  "workspaces": [
    { "id": 3, "name": "Acme Digital", "slug": "acme-digital", "logo_url": null, "role": "Owner" },
    { "id": 9, "name": "Karoo Coffee", "slug": "karoo-coffee", "logo_url": null, "role": "Client" }
  ]
}
```

`GET /me` (also `/user/permissions`, `/account`) and `POST /workspaces/{id}/switch` return the same without `message`, `requires_mfa`, `token`, `token_type`; `/me` adds `pin_enabled`.

Differences from workforce-api's contract: `workspace` and `workspaces` are new; `user.companies` / `user.sees_all_companies` became `user.clients` / `user.sees_all_clients` (the current workspace's clients); `user.current_workspace_id` is new; `/auth/check`'s `user` no longer carries `roles` (they depend on the workspace).

## Errors

- Validation: 422 `{message, errors{field: [..]}}`.
- Auth flow refusals (`App\Exceptions\AuthFlowException`): `{message, errors{field}, error_type}` plus extras (`attempts_left`, `retry_after`). `error_type`: `invalid_credentials`, `account_locked`, `account_disabled`, `no_workspace`, `pin_expired`, `invalid_pin`, `challenge_closed`, `resend_too_soon` (429), `invalid_reset_token` (400), `password_reused`.
- An authenticated request by a user disabled or locked meanwhile: 401 `{message, error_type: account_disabled|account_locked}` (token revoked). A user in no workspace: 403 `{message, error_type: no_workspace}`.
- Rows still in use: 409 `{message}`. A save racing another on a unique index: 409.
- Settings › Roles save failure: 500 `{message, error_type: role_permissions_failed}`.

## Endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/api/login` | public | `email`, `password`. Returns the session payload, or `{message, requires_mfa: true, challenge_token, channel, destination, expires_at, resend_available_in}` |
| POST | `/api/login/verify-pin` | public | `challenge_token` + 6-digit `code` → session payload |
| POST | `/api/login/resend-pin` | public | New PIN for an open challenge (30 s cooldown, 5 resends) |
| POST | `/api/forgot-password` | public | `email`. Emails a 60-minute (`AUTH_RESET_TTL_MINUTES`), single-use reset link to `FRONTEND_URL/reset-password?token=…&email=…`; same answer for unknown emails |
| POST | `/api/reset-password` | public | `token`, `email`, `password`, `password_confirmation`; signs out everywhere, unlocks a locked account |
| POST | `/api/register` | public | Workspace sign-up: `name`, `email`, `password`, `password_confirmation`, `workspace_name`, optional `mobile` (0XXXXXXXXX). Creates the account (PIN by email) and the workspace with its default roles, the account as Owner; 201 + session payload (signed in at once, no approval) |
| GET | `/api/auth/check` | optional | Always 200: `{authenticated, user {id, full_name, surname, email}}` |
| POST | `/api/logout` | token | Revokes the current token |
| GET | `/api/me` · `/api/user/permissions` · `/api/account` | token | Session payload without the token, + `pin_enabled` |
| PUT | `/api/account` | token | `mobile`, `mfa_channel` (only an Owner may choose `off`) |
| PUT | `/api/account/password` | token | `current_password`, `password`, `password_confirmation`; signs out other devices |
| GET | `/api/workspaces` | token | `data[]` {id, name, slug, logo_url, role}: the user's workspaces, by name |
| POST | `/api/workspaces` | token | `name`: another workspace with the user as Owner; switches to it. 201 + `/me` body |
| POST | `/api/workspaces/{workspace}/switch` | token, member | Make it current; `/me` body (same token). 404 if not a member |
| GET | `/api/workspace` | `view_settings_workspace` | `data` {id, name, slug, logo_url} of the current workspace |
| PATCH | `/api/workspace` | `update_settings_workspace` | `name`, `logo` (png/jpg/svg/webp ≤ 2 MB, multipart: send as POST with `_method=PATCH`), `remove_logo`; logo stored on the s3 disk under `workspaces/{id}/` |
| GET | `/api/workspace/history` | `view_settings_workspace` | The workspace's change history |
| GET | `/api/users` | `view_settings_users` | Members of the workspace, paginated; `search` (name, email, role), `status`; `summary` {all, active, locked, disabled}. Rows: {id, full_name, surname, name, email, mobile, role {id, name, is_system, all_clients}, clients[{id, name}], mfa_channel, status, status_label, locked_at, last_login_at, shared_account, is_you} |
| GET | `/api/users/options` | `view_settings_users` | `roles[]` (Owner first; `is_system`, `all_clients`) and active `clients[]` for the user forms |
| POST | `/api/users` | `create_settings_users` | `full_name`, `surname`, `email`, `mobile` (required for SMS), `mfa_channel`, `role_id`, `client_ids[]` (required unless the role sees all clients). New email: account created, emailed a 72-hour set-password link. Existing account: added to the workspace (its details untouched), emailed that it was added. 422 if already a member |
| PUT | `/api/users/{user}` | `update_settings_users` | Same body + `status` (active/disabled/locked). Name, email, mobile, PIN channel and status only while the account is in no other workspace (else 422 on `email`; role and clients still change). Not yourself disabled; never the last active Owner |
| DELETE | `/api/users/{user}` | `delete_settings_users` | Removes the user from the workspace (role and client assignments here); the account is soft-deleted only if it was its last workspace. Not yourself or the last active Owner |
| POST | `/api/users/bulk` | `update_`/`delete_settings_users` | `action` (enable/disable/delete), `ids[]` ≤ 100; all or nothing. Enable/disable refused for accounts shared with another workspace |
| GET | `/api/users/history` | `view_settings_users` | Users added, changed (incl. role and clients) and removed in this workspace |
| POST | `/api/users/{user}/unlock` | `update_settings_users` | Unlock after 5 wrong passwords |
| POST | `/api/users/{user}/password-reset` | `update_settings_users` | Email the user a reset link |
| GET | `/api/login-history` | `view_settings_login_history` | Members' sign-in events, paginated; `search`, `event`, `user_id` |
| GET | `/api/settings/roles` | `view_settings_roles` | `roles[]` {id, name, description, is_system, all_clients, users_count, permissions[]} (Owner first) and the grid `groups[]` {key, label, resources[{key, label, actions, grantable}]} |
| POST | `/api/settings/roles` | `create_settings_roles` | `name` (unique in the workspace), `description`, `all_clients`; a deleted role of that name comes back empty |
| PUT | `/api/settings/roles/{role}` | `update_settings_roles` | Same body. 403 for Owner |
| PUT | `/api/settings/roles/{role}/permissions` | `update_settings_roles` | `permissions[]`: the role's full list (grantable names only; `view_x` added wherever another action on x is held). Applies on the next request. 403 for Owner (always everything) |
| DELETE | `/api/settings/roles/{role}` | `delete_settings_roles` | Soft delete; 409 while it has members; 403 for Owner |
| GET | `/api/settings/roles/history` | `view_settings_roles` | Roles added, renamed, deleted and permission changes (one entry per save, one change per page) |
| GET | `/api/email-templates` | `view_settings_email_templates` | Every email type with this workspace's wording: {type, label, description, variables[{key, description}], button_text, subject, body, stores_body, direct_recipient, is_customised, updated_at}; `mail` {from_name, from_address}; `can.update` |
| GET | `/api/email-templates/{type}` | `view_settings_email_templates` | One type (404 unknown) |
| PUT | `/api/email-templates/{type}` | `update_settings_email_templates` | `subject`, `body` (HTML, sanitised) for this workspace |
| POST | `/api/email-templates/{type}/preview` | `view_settings_email_templates` | Optional draft `subject`, `body` → rendered with sample data |
| POST | `/api/email-templates/{type}/test` | `update_settings_email_templates` | Sends a `[TEST]` copy to the signed-in user |
| POST | `/api/email-templates/{type}/reset` | `update_settings_email_templates` | Back to the default wording |
| GET | `/api/clients` | `view_clients_clients` | The clients the user may access, paginated; `search` (name, contact), `status` (active/inactive), `sort` (name, contact_name, created_at, status) + `direction`; default active first, then name. Rows {id, name, contact_name, contact_email, is_active, notes, sites_count, created_at, updated_at}; `summary` {active, total} |
| POST | `/api/clients` | `create_clients_clients`, role sees all clients | `name` (unique in the workspace), `contact_name`, `contact_email`, `is_active`, `notes` |
| GET | `/api/clients/{client}` | `view_clients_clients` + access | One client |
| PUT | `/api/clients/{client}` | `update_clients_clients` + access | Same body |
| DELETE | `/api/clients/{client}` | `delete_clients_clients` + access | Soft delete; 409 while it has sites |
| POST | `/api/clients/bulk` | `update_`/`delete_clients_clients` + access to each | `action` (activate/deactivate/delete), `ids[]` ≤ 100; all or nothing (422 unknown id, 403 any out of reach, 409 any with sites) |
| GET | `/api/clients/history` | `view_clients_clients` | Changes to the clients the user may access |
| GET | `/api/sites` | `view_clients_sites` (+ access to `client_id`) | Sites of the clients the user may access, paginated; `client_id`, `search` (name, domain), `status`, `sort` (name, domain, created_at, status) + `direction`. Rows {id, client_id, client {id, name}, name, url, domain, is_active, created_at, updated_at}; `summary` {active, total} |
| POST | `/api/sites` | `create_clients_sites` + access to the client | `client_id`, `url` (normalised: `https://` added, query/fragment/trailing slash dropped; `domain` = lower-case host without `www.`, unique in the workspace), `name` (defaults to the domain), `is_active` |
| GET | `/api/sites/{site}` | `view_clients_sites` + access | One site |
| PUT | `/api/sites/{site}` | `update_clients_sites` + access | `url`, `name`, `is_active` (`client_id` prohibited: a site stays with its client) |
| DELETE | `/api/sites/{site}` | `delete_clients_sites` + access | Soft delete |
| POST | `/api/sites/bulk` | `update_`/`delete_clients_sites` + access to each | `action` (activate/deactivate/delete), `ids[]` ≤ 100; all or nothing |
| GET | `/api/sites/history` | `view_clients_sites` | Changes to the sites of the clients the user may access; `record_label` "karoocoffee.co.za · Karoo Coffee" |

## Permission catalogue

Names are `{action}_{resource}` (`App\Support\Permissions`; the SPA's `src/config/modules.ts` mirrors it). Every action needs `view`. Owner-only resources can't be given to another role.

| Group | Resource | Actions | Notes |
|---|---|---|---|
| Dashboard | `dashboard` | view | |
| Clients | `clients_clients` | view, create, update, delete | Adding needs a role that sees every client |
| Clients | `clients_sites` | view, create, update, delete | |
| Search Console | `search_console` | view, create, update, delete | Not built yet. Create = connect a property, delete = disconnect |
| Site audit | `site_audit` | view, create, update, delete | Not built yet. Create = run a crawl, update = mark issues / settings |
| Rank tracking | `rank_tracking` | view, create, update, delete | Not built yet. Create = add keywords / "check now" |
| Reports | `reports` | view, create, update, delete, approve | Not built yet. Approve = approve and send |
| Settings | `settings_workspace` | view, update | Owner only |
| Settings | `settings_users` | view, create, update, delete | Owner only |
| Settings | `settings_roles` | view, create, update, delete | Owner only |
| Settings | `settings_login_history` | view | Owner only |
| Settings | `settings_email_templates` | view, update | |

## Default roles (every new workspace)

| Role | Clients | Access |
|---|---|---|
| Owner | all | Everything, always. Can't be renamed, changed or deleted; the workspace keeps at least one active Owner |
| Manager | all | Every action on dashboard, clients, sites and the SEO tools (incl. approving reports); email templates. No users, roles, login history or workspace |
| Analyst | assigned | View dashboard, clients and sites; view/create/update/delete on Search Console, site audit, rank tracking and reports |
| Client | assigned | View only: dashboard, clients, sites, Search Console, site audit, rank tracking, reports (read-only client login) |
