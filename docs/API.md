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
- Search Console: 409 `{message, error_type}` with `google_not_connected` (no Google account, or its access was revoked/expired: connect again) or `gsc_not_linked` (the site has no property). Google refusing a request: 502 `{message, error_type: google_api_error}`.
- Reports: 409 `{message, error_type: report_not_ready}` (send or download before the report is ready, or its PDF is missing). Public report links: 404 `{message: "This report link is not valid or has expired."}` for every bad token.
- Rank tracking: 409 `{message, error_type}` with `rank_checks_paused` (the platform's rank checks are off: not set up, or the monthly spend cap is reached) or `check_running` (a live check of the keyword is pending); 429 `{message, error_type: check_limit_reached}` (the workspace's daily "check now" limit). The provider refusing a request: 502 `{message: "Rank checks couldn't be reached. Try again shortly.", error_type: dataforseo_error}`. Messages never mention the provider, credentials or money.

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
| GET | `/api/workspace` | `view_settings_workspace` | `data` {id, name, slug, logo_url, brand_color} of the current workspace (`brand_color` also in the session's `workspace`) |
| PATCH | `/api/workspace` | `update_settings_workspace` | `name`, `logo` (png/jpg/svg/webp ≤ 2 MB, multipart: send as POST with `_method=PATCH`), `remove_logo`, `brand_color` (`#RRGGBB`, stored lower case; null = the default `#1f4e79`; anything else 422); logo stored on the s3 disk under `workspaces/{id}/` (a failed write is an error, never a row without its file) |
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
| GET | `/api/settings/change-history` | `view_settings_change_history` (+ each page's `view_*`; client records only for the user's clients) | Paginated ChangeEntry, newest first: `{id, at, action: create\|update\|delete\|restore, user, record_id, record_label, page, page_key, record_type, changes[{field, label, old, new}], undo: {available, reason}, undone_at, undone_by, undo_of}`. Filters `page_key`, `user` (id or `system`), `action`, `search`, `from`, `to` (Y-m-d), `per_page` 10–100 |
| GET | `/api/settings/change-history/options` | `view_settings_change_history` | `{pages[{key, label}], users[{id\|'system', name}]}` for the filters |
| POST | `/api/settings/change-history/{entry}/undo` | `update_settings_change_history` + the right to make that change on its page (throttled 30/min) | An edit put back (every field must still hold the edit's value) or a deletion restored; written to the history with `undo_of`, the entry marked undone → `{data: ChangeEntry}`. 409 `{message, error_type}`: `not_undoable` (adding, users, reports, Google, locked fields, already undone, parent deleted), `changed_since`, `record_gone`, `undo_conflict`; 422 `undo` when a restored keyword is over the plan |
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
| GET | `/api/google/connection` | `view_search_console` | `data`: null or {id, google_email, status (active/error), last_error, connected_by {id, name}, connected_at}: the workspace's Google account (one per workspace) |
| POST | `/api/google/connect` | `update_search_console` | `{url}`: Google's consent URL (scopes `openid email webmasters.readonly`, offline, `prompt=consent`). `state` is encrypted (workspace, user, single-use nonce, 10-minute expiry) |
| GET | `/api/google/callback` | public | Google's redirect (`code`, `state` or `error`): checks state and that the user still holds `update_search_console` there, stores the tokens (encrypted), replacing the workspace's previous connection, then redirects to `FRONTEND_URL/dashboard/search-console?google=connected` or `?google=error&message=…` |
| DELETE | `/api/google/connection` | `update_search_console` | Revokes the token at Google (best effort) and deletes the connection; 204. Sites keep their property, syncing stops |
| GET | `/api/google/properties` | `update_search_console` | `data[]` {site_url, permission_level} the account can read (unverified ones left out), by site_url. 409 `google_not_connected` |
| GET | `/api/search-console/sites` | `view_search_console` | Every site the user can access, by client then name (not paginated): `data[]` {id, name, url, client {id, name}, gsc_property, gsc_sync_status (idle/queued/syncing/done/failed), gsc_synced_at, gsc_data_through (Y-m-d), gsc_sync_error} |
| PUT | `/api/sites/{site}/search-console` | `update_search_console` + access | `property` (one of `/google/properties`, not linked to another site of the workspace) → links it and queues the 16-month backfill; `data` = the site as above. Another property than before deletes the old data. 409 `google_not_connected` |
| DELETE | `/api/sites/{site}/search-console` | `update_search_console` + access | Unlinks and deletes the site's Search Console data; 204 |
| POST | `/api/sites/{site}/search-console/sync` | `update_search_console` + access | Queues a sync now: 202 + the site. 409 `gsc_not_linked` / `google_not_connected` |
| GET | `/api/sites/{site}/search-console/overview` | `view_search_console` + access | Report params below. `{period, compare_period, data_through, totals {clicks, impressions, ctr, position}, compare_totals, series[{date, clicks, impressions, ctr, position}], compare_series, sync}`; every day of the period is in `series` (0 / null position on days without data) |
| GET | `/api/sites/{site}/search-console/queries` | `view_search_console` + access | Paginated `data[]` {query, clicks, impressions, ctr, position, clicks_change, position_change} + `period`, `compare_period`, `sync`. Sort `clicks` (default `-clicks`), `impressions`, `ctr`, `position`, `clicks_change`, `position_change` |
| GET | `/api/sites/{site}/search-console/pages` | `view_search_console` + access | Same with `page` instead of `query` |
| GET | `/api/sites/{site}/search-console/quick-wins` | `view_search_console` + access | Paginated `data[]` {query, page, impressions, clicks, ctr, position, potential_clicks}. Sort `potential_clicks` (default desc), `impressions`, `clicks`, `ctr`, `position` |
| GET | `/api/sites/{site}/search-console/low-ctr` | `view_search_console` + access | Paginated `data[]` {page, impressions, clicks, ctr, expected_ctr, position, missed_clicks, top_queries[3]}. Sort `missed_clicks` (default desc), `impressions`, `clicks`, `ctr`, `expected_ctr`, `position` |
| GET | `/api/sites/{site}/search-console/content-decay` | `view_search_console` + access | Paginated `data[]` {page, clicks_last_28, clicks_prev_28, clicks_change_pct, monthly[{month: YYYY-MM, clicks} x 6], decline_months} + `sync` (no `period`: always as of `gsc_data_through`, start/end ignored). Sort `clicks_lost` (= prev − last; default desc), `clicks_last_28`, `clicks_prev_28`, `clicks_change_pct`, `decline_months` |
| GET | `/api/site-audit/sites` | `view_site_audit` | Every site the user can access, by client then name (not paginated): `data[]` {id, name, url, client {id, name}, audit_schedule (off/weekly/monthly), audit_page_limit, latest_audit: AuditSummary\|null (latest **done** audit), running_audit: AuditSummary\|null (queued/crawling/analysing)} |
| PUT | `/api/sites/{site}/audit-settings` | `update_site_audit` + access | `audit_schedule` (off/weekly/monthly), `audit_page_limit` (10–5000) → `data` = the site as in `/site-audit/sites` |
| GET | `/api/sites/{site}/audits` | `view_site_audit` + access | The site's audits, newest first, paginated AuditSummary (`per_page` 10–100) |
| POST | `/api/sites/{site}/audits` | `update_site_audit` + access | Queue an audit now: 202 `{data: AuditSummary}` (status `queued`). 409 `{message, error_type: 'audit_running'}` while one is queued/crawling/analysing |
| GET | `/api/audits/{audit}` | `view_site_audit` + access | `{data: AuditSummary}`; poll it while the audit runs (`pages_crawled` is written every 10 pages) |
| POST | `/api/audits/{audit}/cancel` | `update_site_audit` + access | Cancel a queued or running audit (the crawler stops after its current batch; pages crawled so far are kept, nothing is analysed) → `{data: AuditSummary}` status `cancelled`. 409 `audit_not_running` once finished |
| GET | `/api/audits/{audit}/issues` | `view_site_audit` + access | `{data: [{type, severity, title, why, how_to_fix, count, new_count}]}`: every issue type found, critical → warning → notice, then count desc, then type |
| GET | `/api/audits/{audit}/issues/{type}` | `view_site_audit` + access | Paginated `data[]` {url, page_id, details, is_new} by URL; `search` (URL contains); plus `issue` {type, severity, title, why, how_to_fix}. 404 for a type not in the catalogue (a known type with no rows is an empty page) |
| GET | `/api/audits/{audit}/pages` | `view_site_audit` + access | Paginated pages: `search` (URL or title), `status` (2xx/3xx/4xx/5xx, on the first response), `indexable` (1/0), `sort` (`url`, `status_code`, `response_ms`, `inlinks_count`, `word_count`, `depth`; `-` = desc; default depth then URL). Rows {id, url, depth, status_code, final_url, final_status_code, content_type, response_ms, title, meta_description, h1, h1_count, canonical, meta_robots, is_indexable, in_sitemap, inlinks_count, outlinks_count, word_count, images_count, images_missing_alt, issues_count} |
| GET | `/api/audits/{audit}/pages/{page}` | `view_site_audit` + access | `{data: {…row above, redirect_chain [{url, status_code}]\|null, x_robots_tag, canonical_count, fetch_error, missing_alt_images [src ≤ 20], issues [{type, severity, title, details, is_new}], inlinks [{page_id, url, nofollow}] (first 50), inlinks_total}}`. A page of another audit 404s |
| GET | `/api/audits/{audit}/vitals` | `view_site_audit` + access | `{data: [{url, strategy (mobile/desktop), performance_score (0–100)\|null, lab, field, status (done/failed), error}]}` (shapes below) |
| GET | `/api/audits/{audit}/comparison` | `view_site_audit` + access | `filter` = `new` (default: in this audit, not the previous) or `fixed` (in the previous, not this one). Paginated `data[]` {type, severity, title, url, details} by severity, type, URL; `search`; plus `filter` and `comparison`. Empty when there is no previous audit |
| GET | `/api/rank-tracking/sites` | `view_rank_tracking` | Every site the user can access, by client then name (not paginated): `data[]` {id, name, url, client {id, name}, keywords_count, avg_position, top3, top10, top100, change_7d_avg, last_checked_on}. Figures over **active** keywords; top3/top10/top100 are **cumulative** (in the top N); `avg_position` over ranking keywords; `change_7d_avg` = mean change_7d of keywords that have one; `last_checked_on` = latest done check |
| GET | `/api/rank-tracking/locations` | `view_rank_tracking` | `search` (optional, ≤ 100 chars) → `{data: [{code, name, type, country_iso}]}`, top 20: names starting with the search first, then South Africa's, then Country → Region → City …, shorter names first. No search = South Africa and its regions. Names as "Sandton, Gauteng, South Africa". The list (~270k places, from the provider's live host even in sandbox mode: the sandbox list is a one-country sample) lives in `rank_locations`. A search never downloads it: with no list yet it queues `RefreshRankLocations` (long queue) and answers 409 `locations_loading`; when older than 30 days it queues a refresh and answers from the current list. 409 `rank_checks_paused` while rank checks aren't set up |
| GET | `/api/rank-tracking/usage` | `view_rank_tracking` | `{data: {keywords_tracked, keyword_limit, checks_now_today, check_now_limit, paused}}`, for the whole workspace (not limited to the user's clients). `keywords_tracked` = active, non-deleted keywords (keyword × location × device); `keyword_limit` = the workspace's own limit, else 500; `checks_now_today` = "check now" requests today (SAST calendar day, failed ones included); `check_now_limit` = 20; `paused` = rank checks are off (not set up, or the platform's monthly spend cap is reached; cached 60 s). Never money, balance or provider details |
| GET | `/api/sites/{site}/rank-keywords` | `view_rank_tracking` + access | Paginated KeywordRow (below). `search` (keyword contains), `device` (desktop/mobile), `location` (name contains), `tag`, `bucket` (`top3` ≤ 3, `top10` ≤ 10, `top100` ranking, `not_ranking` = latest result outside the top 100), `sort` (`keyword` default, `position`, `change_7d`, `change_30d`, `best_position`; `-` = desc; nulls last both ways), `page`, `per_page` (10–100, default 15). Inactive keywords included |
| POST | `/api/sites/{site}/rank-keywords` | `update_rank_tracking` + access | `{keywords: string[] (1–200 after trimming and de-duplicating, ignoring case; ≤ 200 chars), locations: [{type: 'code', code, name} \| {type: 'coordinate', coordinate: 'lat,lng', name}] (1–5), devices: ('desktop'\|'mobile')[], frequency?: daily\|weekly (default **weekly**), language_code? (default en), tags? (≤ 10, ≤ 30 chars)}` → 201 `{created, skipped}`: every keyword × location × device not tracked yet (skipped = already tracked or repeated). A coordinate is stored as "lat,lng,radius" (≤ 7 decimals, radius `RANK_COORDINATE_RADIUS`, 20000). `weekly_day` = today's weekday. 422 `keywords` "This workspace can track up to N keywords. You have M left." when the new combinations (after skips) would take the workspace's active keywords over its limit; nothing is created then. Unique per site: keyword (ignoring case) × location × device |
| POST | `/api/sites/{site}/rank-keywords/bulk` | `update_rank_tracking` + access | `{action: activate\|deactivate\|delete\|set_frequency, ids, frequency? (required for set_frequency)}` → `{affected}`. All or nothing: an id that isn't this site's keyword → 422 `ids`; `activate` of more inactive keywords than the workspace has room for → 422 `ids` (the keyword-limit message), nothing changed. ≤ 1000 ids |
| PUT | `/api/rank-keywords/{id}` | `update_rank_tracking` + access | Any of `{frequency, weekly_day (0 = Sunday … 6), tags, is_active}` → `{data: KeywordRow}`. Turning an inactive keyword on at the workspace's limit → 422 `is_active` (the keyword-limit message), nothing changed |
| DELETE | `/api/rank-keywords/{id}` | `update_rank_tracking` + access | 204. Soft delete: checks are kept; the same keyword can be added again |
| POST | `/api/rank-keywords/{id}/check` | `update_rank_tracking` + access | "Check now" in Live mode: today's check becomes a pending live check, queued (`CheckKeywordNow`) → 202 `{data: KeywordRow}` (`last_check_status` pending until the job ran). 409 `rank_checks_paused` (not set up, or a live check wouldn't fit under the platform's monthly spend cap); 409 `check_running` while a live check of the keyword is pending; 429 `check_limit_reached` "You've used today's 20 instant checks. They reset at midnight." past the workspace's daily limit (counted per request, before the call: failed checks count) |
| GET | `/api/rank-keywords/{id}/history` | `view_rank_tracking` + access | `days` (1–365, default 90) → `{data: [{date, position, url, serp_features}]}`: done checks, oldest first |
| GET | `/api/sites/{site}/rank-tracking/summary` | `view_rank_tracking` + access | `{data: {keywords_count, avg_position, distribution, distribution_7d_ago, visibility, visibility_series [{date, visibility, avg_position}] × 90 days, gains [{keyword_id, keyword, location_name, device, from, to, change}] ≤ 5, drops […] ≤ 5, serp_features {featured_snippet, local_pack, ai_overview: {present, ours} (keyword counts)}, locations: string[], tags: string[], cannibalised_count}}` (rules below). `distribution` buckets are **non-overlapping** (top10 = 4–10), unlike the cumulative top3/top10/top100 of `/rank-tracking/sites`; `visibility_series[].visibility` / `avg_position` are null on days before any result. `locations` / `tags` = distinct location names / tags of all the site's keywords (inactive included, deleted not), sorted: the keyword list's filter choices |
| GET | `/api/sites/{site}/rank-tracking/cannibalisation` | `view_rank_tracking` + access | Paginated `data[]` {keyword_id, keyword, location_name, device, urls [{url, position}]}: active keywords whose latest result has ≥ 2 of the site's URLs, most URLs first |
| GET | `/api/reports/sites` | `view_reports` | `{data: [{id, name, url, client: {id, name}, last_report: ReportSummaryRow\|null, schedule: Schedule\|null}]}`: every site the user can access, by client then name (not paginated) |
| GET | `/api/sites/{site}/reports` | `view_reports` + access | Paginated ReportSummaryRow, newest first (`per_page` 10–100, default 15) |
| POST | `/api/sites/{site}/reports` | `create_reports` + access | `{period: last_month\|this_month\|last_3_months\|custom, start?, end? (Y-m-d, required for custom; end ≤ today; ≤ 16 months), compare?: previous\|year\|none (default previous), sections: string[] ≥ 1 of search_console\|rank_tracking\|site_audit, note? (≤ 2000), title? (≤ 200; default "{client} · SEO report · {period label}"), send_to?: email[] ≤ 10}` → 202 `{data: ReportSummaryRow}` (status `generating`; emailed to `send_to` once ready). Poll `GET /reports/{id}` |
| POST | `/api/sites/{site}/reports/bulk` | `view_reports` + access; each report `delete_reports` (delete) or `create_reports` (unshare) | `{action: delete\|unshare, ids: int[] 1–100}` → `{affected}` (deleted, or links revoked: only reports that had one). All or nothing: an id that is gone or another site's → 422 `ids`; a report the user may not change → 403 |
| GET | `/api/reports/{id}` | `view_reports` + access | `{data: ReportSummaryRow + {data: Snapshot\|null (null until ready), branding: {name, logo_url\|null, brand_color}}}`. `logo_url` is a temporary signed URL (1 hour) to `/api/public/report-logos/{id}` |
| GET | `/api/reports/{id}/pdf` | `view_reports` + access | The PDF (`Content-Disposition: attachment; filename="{slug of the title}.pdf"`); 409 `report_not_ready` until it's ready |
| POST | `/api/reports/{id}/send` | `create_reports` + access | `{recipients: email[] 1–10, message?: string ≤ 2000}` → `{data: ReportSummaryRow}` (`sent_at`, `sent_to` recorded). One email per recipient, PDF attached; makes a share link (or extends the current one) to 90 days for the button. 409 `report_not_ready` |
| POST | `/api/reports/{id}/share` | `create_reports` + access | `{expires_in_days?: 7\|30\|90\|null}` (null or omitted = no expiry) → `{data: ReportSummaryRow}`. Always a new token: the old link stops working |
| DELETE | `/api/reports/{id}/share` | `create_reports` + access | Revoke the link → `{data: ReportSummaryRow}` (`share: null`) |
| DELETE | `/api/reports/{id}` | `delete_reports` + access | 204. Soft delete; the link stops working and the PDF is deleted from storage |
| GET | `/api/sites/{site}/report-schedule` | `view_reports` + access | `{data: Schedule\|null}` |
| PUT | `/api/sites/{site}/report-schedule` | `create_reports` + access | `{is_active, day_of_month (1–28), send_hour? (0–23 SAST; kept when omitted, 8 on a new schedule), recipients: email[] 1–10, sections ≥ 1, compare, note?}` → 200 `{data: Schedule}` (creates or replaces the site's one schedule) |
| POST | `/api/sites/{site}/report-schedule/send-now` | `create_reports` + access | "Send now": last calendar month's report with the schedule's sections, compare and note, emailed to the schedule's recipients once generated → 202 `{data: Row}` (status generating). The monthly run is untouched (`last_run_at` not set). 409 `no_schedule` when the site has no schedule. Throttled 5/min |
| GET | `/api/public/reports/{token}` | public, 60/min | The share page: `{data: {title, period_label, summary, note, data: Snapshot, branding: {name, logo_url\|null, brand_color}, generated_at}}`. Unknown, revoked, expired or not-ready → 404 `{message: "This report link is not valid or has expired."}` (identical for all). `X-Robots-Tag: noindex, nofollow` |
| GET | `/api/public/reports/{token}/pdf` | public, 60/min | The PDF (attachment); same 404 |
| GET | `/api/public/reports/{token}/logo` | public, 60/min | The agency logo of the report (the share page's `logo_url`); same 404 |
| GET | `/api/public/report-logos/{id}` | signed URL, 60/min | The logo behind `GET /reports/{id}`'s `branding.logo_url` (403 when the signature is wrong or expired) |
| GET | `/api/platform/usage` | `view_platform_usage`, platform workspace only | Platform › Usage header (Indlela staff; see Platform below): `{data: {month: 'YYYY-MM', spend: {this_month, pending, cap, cap_used_pct}, provider: {configured, sandbox, balance}, limits: {workspace_keyword_limit, check_now_daily_limit}, totals: {workspaces, active_workspaces, keywords_tracked, checks_this_month, live_checks_this_month}, series: [{month, cost, checks}] ×6}}` |
| GET | `/api/platform/usage/workspaces` | `view_platform_usage`, platform workspace only | Paginated rows of every workspace: `{id, name, slug, owner: {name, email}\|null, created_at, keywords_tracked, keyword_limit, keyword_limit_override, sites_count, checks_this_month, live_checks_this_month, cost_this_month, cost_last_month, last_check_at, series: [{month, cost}] ×6}`. `search` (name, slug, an Owner's email), `sort` (`name\|keywords_tracked\|checks_this_month\|cost_this_month\|cost_last_month\|last_check_at`, `-` = desc; default `-cost_this_month`; empty values last), `page`, `per_page` |
| PATCH | `/api/platform/workspaces/{id}/limits` | `view_platform_usage`, platform workspace only | `{rank_keyword_limit: int 10–100000 \| null}` (null = the default) → `{data: row}`. Audited in that workspace's history; applies at once to adding/reactivating keywords there |

### Search Console reports

Params on every report: `start`, `end` (Y-m-d; default the 28 days ending at the site's `gsc_data_through`, or yesterday before the first sync; at most 16 months), `compare` (`previous` default = the same number of days just before, `year` = the same dates a year earlier, `none`), and on the lists `search` (case-insensitive contains on query/page), `sort` (`col` ascending, `-col` descending; an unknown column is 422), `page`, `per_page` (10–100, default 15). Lists use the resource pagination shape (`data`, `links`, `meta`) plus `sync` {status, synced_at, data_through, error}.

- CTR is a fraction (0–1, 4 decimals); position is the impression-weighted average (1 decimal). Overview totals come from Google's daily totals (accurate); queries/pages/quick wins/low CTR/decay add up query × page rows, which leave out anonymised queries, so they sum lower.
- `clicks_change` = clicks − compare clicks; `position_change` = position − compare position (negative = moved up), null when the row had no impressions in the compare period; both null with `compare=none`.
- Quick wins: queries averaging position 5–15 with ≥ 100 impressions per 28 days (scaled to the period); `page` = the query's page with most impressions; `potential_clicks` = (impressions × expected CTR at position 3 − clicks) × 30 / days in period, at least 0.
- Low CTR: pages averaging position ≤ 10 with ≥ 200 impressions per 28 days and `ctr < expected_ctr × 0.7`; `missed_clicks` = impressions × expected_ctr − clicks over the period; `top_queries` = the page's 3 queries with most impressions.
- Expected CTR by rounded position (`config/indlela.php` `search_console.expected_ctr`): 1: 28 %, 2: 15.5 %, 3: 11 %, 4: 8 %, 5: 6.5 %, 6: 5 %, 7: 4 %, 8: 3.2 %, 9: 2.7 %, 10: 2.3 %, 11–20: 1.6 % falling to 0.45 % (beyond 20: 0.45 %).
- Content decay, with D = `gsc_data_through`: last 28 days = D−27..D, previous = D−55..D−28; `monthly` = the 6 full months ending with D's month when D is its last day, else the month before; `decline_months` = how many months in a row clicks fell, ending at the last full month. Listed when the page had ≥ 20 clicks in the previous 28 days or any of those months, and either fell ≥ 20 % (`clicks_change_pct ≤ −20`) or fell 3+ months running.
- An unlinked site: 409 `gsc_not_linked`. Linked but no data yet: 200 with zero totals, empty `series` / `data`, and `sync.status` (queued/syncing/failed) for the page to show.

### Site audit

Crawls run as queued jobs (`RunSiteAudit`, then `RunAuditVitals`) on the `redis-long` connection: **Horizon must run**. Status: `queued → crawling → analysing → done` (or `failed` with `error`, or `cancelled`); `vitals_status` runs separately: `pending → done | unavailable` (with `vitals_message`; a PageSpeed failure never fails the audit).

**AuditSummary** = `{id, site_id, status, trigger (manual/scheduled), triggered_by {id, name}|null, page_limit, pages_crawled, pages_found, started_at, finished_at, error, health_score (0–100, null until analysed), counts {critical, warning, notice, total} (zeros until analysed), comparison {fixed, new, persisting, previous_audit_id}|null, vitals_status, vitals_message, details_pruned, created_at}`. `details_pruned` = true for audits beyond the latest 10 of the site: only this summary is kept (pages, links, issues and vitals deleted). `pages_found` = URLs discovered (links + sitemap), crawled or not.

**Crawler.** Raw HTML (no JavaScript) with the `IndlelaBot/1.0 (+APP_URL)` user agent; robots.txt honoured (IndlelaBot group, else `*`); 4 requests at a time, 10 s timeout, 250 ms between batches; same site only (`www.` and apex are one site); breadth first from the home page up to depth 10, then sitemap URLs nobody linked to (depth null); page limit = the site's `audit_page_limit` (default 500, hard max 5000). Sitemaps: robots.txt `Sitemap:` lines, else `/sitemap.xml`, `/sitemap_index.xml`, `/wp-sitemap.xml`; indexes followed. URLs normalised (fragment and `utm_*`, `gclid`, `fbclid`, `msclkid`… dropped). Redirects followed hop by hop (max 10). After the crawl: internal images/files (≤ 300) and the 200 most-linked external URLs are status-checked (HEAD, GET fallback). Every fetched URL is a page row, redirects and errors included; status 0 = no response.

**Health score** = 100 − Σ weight × (pages with ≥ 1 issue of that severity ÷ pages crawled) − Σ points × site-wide issues of that severity, rounded and clamped 0–100. Weights: critical 50, warning 30, notice 10; site-wide points: critical 25, warning 5, notice 2 (site-wide types: `homepage_noindex`, `robots_blocks_site`, `http_not_https`, `sitemap_missing`). No page crawled → 0. Recomputed when vitals arrive.

**Comparison** with the previous `done` audit of the site by issue fingerprint (sha1 of type + URL + a detail key, e.g. the link target or the PSI strategy): fixed = in previous only, new = in this only, persisting = both. Vitals issues are left out unless both audits have `vitals_status` done.

**Vitals** run on the home page + the 4 most-linked indexable pages, mobile and desktop. `lab` = `{lcp_ms, cls, tbt_ms, fcp_ms, si_ms}` (Lighthouse; ms as integers). `field` (CrUX, 75th percentile; null when Google has no data) is flat: `{lcp_ms, lcp_category, inp_ms, inp_category, cls, cls_category, category, origin_fallback}`, categories `good` / `needs_improvement` / `poor`; `origin_fallback` true = whole-site data, not this URL's. Issues: `poor_core_web_vitals` when performance < 50 or any field category poor; `needs_improvement_core_web_vitals` when < 90 or any needs improvement. On 429/quota: `vitals_status` unavailable with a message about `PAGESPEED_API_KEY`; some runs failing = done with "N of M PageSpeed tests failed".

**Issue catalogue** (`App\Support\AuditIssues`: title, why it matters, how to fix; thresholds in `config/indlela.php` `audit.thresholds`). Content checks (title, description, H1, canonical presence, thin) apply to indexable pages (200, HTML, no noindex, canonical absent or self).

| Type | Severity | Flagged when |
|---|---|---|
| `page_5xx` | critical | URL answers 5xx or not at all (status 0) |
| `page_4xx` | critical | URL answers 4xx |
| `broken_internal_link` | critical | Internal link or image whose final status is 4xx/5xx/none (`details.kind` link/image); one per source page + target |
| `redirect_loop` | critical | Redirects come back to a URL in the chain, or > 10 hops |
| `missing_title` | critical | No `<title>` |
| `noindex_in_sitemap` | critical | In the sitemap but noindex (meta robots/googlebot or X-Robots-Tag) |
| `homepage_noindex` | critical | Home page is noindex (site-wide) |
| `canonical_to_non_200` | critical | Canonical target was crawled and didn't answer 200 |
| `robots_blocks_site` | critical | robots.txt disallows the home page for IndlelaBot or Googlebot (site-wide; crawl stops if it blocks IndlelaBot) |
| `http_not_https` | critical | The home page is served over http (site-wide) |
| `broken_external_link` | warning | External link answers 404, 410, 5xx or nothing (401/403/429/999 = bot blocked, not flagged) |
| `redirect_chain` | warning | ≥ 2 redirects before the final page |
| `duplicate_title` / `duplicate_meta_description` | warning | Same value (case-insensitive) on ≥ 2 indexable pages; `details.other_urls` (≤ 5) |
| `missing_meta_description` | warning | No meta description |
| `missing_h1` | warning | No H1 |
| `images_missing_alt` | warning | `<img>` without alt or with empty alt |
| `noindex_linked_internally` | warning | Noindex page with internal inlinks |
| `multiple_canonicals` | warning | > 1 distinct canonical |
| `orphan_page` | warning | In the sitemap, 200, no internal inlinks (home excluded) |
| `sitemap_missing` | warning | No sitemap found (site-wide) |
| `poor_core_web_vitals` | warning | See vitals |
| `internal_link_to_redirect` | notice | Internal link to a URL that redirects |
| `title_too_long` / `title_too_short` | notice | > 60 / < 30 characters |
| `meta_description_too_long` | notice | > 160 characters |
| `multiple_h1` | notice | > 1 H1 |
| `canonical_to_other_page` | notice | Canonical points to another URL (target 200 or not crawled) |
| `missing_canonical` | notice | No canonical |
| `slow_response` | notice | > 2 s to answer (200 HTML pages) |
| `thin_content` | notice | < 200 visible words |
| `needs_improvement_core_web_vitals` | notice | See vitals |

**Schedule.** `site-audit:run-scheduled` (daily 02:00) queues an audit for each active site with `audit_schedule` weekly/monthly whose last done-or-failed audit finished 7/30 calendar days ago or more (or never), with none running; then prunes detail rows of all but the latest 10 audits per site.

### Rank tracking

Positions come from the **DataForSEO SERP API** (Google organic, top 100, `depth` 100) on **one platform account**: credentials `DATAFORSEO_LOGIN` / `DATAFORSEO_PASSWORD` (HTTP Basic) are the platform's; workspaces never configure them and never see cost, balance or the provider. `DATAFORSEO_SANDBOX=true` (default) uses `sandbox.dataforseo.com` (free sample data). Without credentials check now and the location search answer 409 `{message: "Rank checks are paused for now. Your keywords are safe and checks resume automatically.", error_type: 'rank_checks_paused'}` and the scheduled commands log and skip. The provider refusing a request outright: 502 `{message, error_type: 'dataforseo_error'}` (neutral message; the provider's own is logged).

**Beta limits** (`config/indlela.php` `rank_tracking`; pricing plans later):
- Keywords per workspace: `RANK_WORKSPACE_KEYWORD_LIMIT` (500) active keyword × location × device combinations across all its sites; `workspaces.rank_keyword_limit` (nullable) overrides it for one workspace. Adding (POST), reactivating (PUT `is_active`, bulk `activate`) past it → 422.
- "Check now" per workspace per calendar day (Africa/Johannesburg): `RANK_CHECK_NOW_DAILY_LIMIT` (20) → 429 `check_limit_reached`. Counted in `rank_checks.live_requests` when requested (failed live calls are billed, so they count), under a per-workspace advisory lock.
- Platform monthly spend cap: `RANK_MONTHLY_SPEND_CAP_USD` (50; 0 or empty = no cap). Spend = Σ `rank_checks.cost` this calendar month across **all** workspaces + the expected price of checks posted and not answered yet. `rank-tracking:post-checks` creates nothing once it's reached; each `PostRankTasks` batch, under a platform advisory lock with the spend read fresh, posts only the checks that fit and fails the rest as "Skipped: rank checks paused" (none left pending), logging one warning a day with spend and cap. Check now → 409 `rank_checks_paused` when a live check wouldn't fit. Usage `paused` = spend ≥ cap (cached 60 s).

A **keyword** is one keyword × location × device of a site, checked daily or weekly (`weekly_day`). A **check** is one result for a keyword on a day (`rank_checks`, unique per keyword and day; status `pending → done | failed`).

**Jobs and schedule** (Horizon and the scheduler must run):
- `rank-tracking:post-checks` daily 01:00: a pending standard check for every active keyword of an active site that is due today (daily; weekly on its weekday; or never checked yet, so new weekly keywords don't wait a week) and has no check today, then one `PostRankTasks` job per workspace and 100 checks → one `task_post` call (Standard queue, `priority` 1, `tag` = check id). A keyword is never posted twice the same day (unique row; jobs claim their rows before posting). A refused task fails its check with DataForSEO's message.
- `rank-tracking:collect` every 5 minutes: checks pending > 24 h → failed ("No result from DataForSEO within 24 hours."); then `CollectRankResults` → `tasks_ready` → one `FetchRankResult` per finished task that is one of our pending standard checks → `task_get/advanced` → stored. Still queued (40601/40602) = left for the next run; no search results (40102) = done, not ranking.
- "Check now": `CheckKeywordNow` → `live/advanced`, stored as today's check (mode `live`, replacing a standard result of the day; costs add up; `live_requests` + 1). Not retried (each call is billed).
- `rank-tracking:locations` downloads DataForSEO's location list (~270k places, a few minutes, ~400 MB: the memory limit is raised to `RANK_LOCATIONS_MEMORY_LIMIT`, default 1024M). Scheduled weekly (Sunday 03:00) with `--if-stale`, which only downloads when the list is missing or older than 30 days. Location list and balance calls always use the live host (free); SERP checks follow `DATAFORSEO_SANDBOX`.

**Parsing a result.** The site matches an item when the item's host without `www.` is the site's domain or a subdomain of it. `position` = best organic `rank_group` of the site (organic items only; null = not in the top 100); `url` = that item's URL; `matching_urls` = every distinct site URL among the organic results with its best position (≥ 2 = **cannibalised**). `serp_features` = `{featured_snippet, local_pack, ai_overview: {present, ours}, people_also_ask, video, images, top_stories: {present}}`; `ours` = any URL or domain inside the element is the site's (AI overview: its references and links; it may be a top-level `ai_overview` item or inside a knowledge graph).

**KeywordRow** = `{id, keyword, location {type: code|coordinate, code, coordinate ("lat,lng,radius"), name}, language_code, device, frequency, weekly_day, tags, is_active, position, previous_position, change_1d, change_7d, change_30d, best_position, url, url_changed, cannibalised, serp_features, last_checked_on, last_check_status (pending|done|failed|null), history [{date, position}] (last 30 done checks, oldest first)}`. From done checks: `position` etc. = the latest; `previous_position` = the one before it; `change_Nd` = position at the latest check at least N days before the latest (1d = the previous check) − position now, so **positive = moved up**; not in the top 100 counts as 101 (dropping out is a drop); null when there is no such earlier check. `url_changed` = both latest and previous rank with different URLs. `last_checked_on` / `last_check_status` = the latest check of any status (a failed check keeps showing the last result).

**Summary rules** (active keywords): `distribution` = **exclusive** bands of the latest positions: top3 (1–3), top10 (4–10), top20 (11–20), top100 (21–100), not_ranking (latest result outside the top 100; never-checked keywords are left out); `distribution_7d_ago` = the same from each keyword's latest result on or before 7 days ago. `visibility` (0–100) = Σ expected CTR at each position ÷ (keywords with a result × expected CTR at position 1) × 100, with the Search Console expected-CTR curve (`search_console.expected_ctr`); positions past 20 and not ranking score 0; every keyword weighs the same. `visibility_series`: each of the last 90 days from every keyword's latest result on or before that day; `visibility` and `avg_position` are **null** on days before any keyword had a result (`avg_position` also null when none ranks). `weekly_day` is 0–6, **0 = Sunday**. Bulk answers `{affected}`. `gains` / `drops`: keywords with change_7d > 0 / < 0, biggest first (`from` = position 7+ days before, `to` = now; null = not ranking).

**Costs** (platform-only, never in a workspace response; `config/indlela.php` `rank_tracking`): Standard queue $0.0006 per check, Live $0.002; one check = one keyword × location × device × day. `rank_checks.cost` holds what DataForSEO actually charged (shown only in Platform › Usage).

### White-label reports

A **report** is one site's results for a period, generated once and **frozen** (`reports.data`): the PDF, the in-app view, the share page and the email all read that snapshot, so they always match even if data changes later. White-label: the client sees the **agency's** name, logo and colour (`workspaces.brand_color`, default `#1f4e79`), never Indlela (not in the PDF, share page, email subject, body, sender name or layout).

**Generation.** `POST /sites/{site}/reports` creates the row (`generating`) and queues `GenerateReport` (report + workspace ids, runs in `CurrentWorkspace::using()`, unique per report id, `redis-long`, 1 try, timeout 600 s): snapshot (`ReportSnapshot`) → summary (`ReportSummary`) → PDF (`ReportPdf`, dompdf, A4) → `reports/{workspace_id}/{report_id}.pdf` on the default disk, written inside the transaction that marks it `ready` (a `false` from the disk = failed, the row never points at a missing file). Any failure → `failed` with a plain `error` ("The PDF could not be saved…", "The report could not be generated…"). With `send_to` (or a schedule) it's emailed once ready; if that email fails the report stays ready with `error` "The report is ready, but the email could not be sent…".

**Periods.** `last_month` / `last_3_months` = whole calendar months before this one; `this_month` = the 1st to yesterday; `custom` = start–end. Compared with: `previous` = the same number of whole months before (October vs September) or, for other ranges, the same number of days before; `year` = the same dates a year earlier; `none`. Labels: "October 2026", "August – October 2026", "5 – 18 October 2026".

**ReportSummaryRow** = `{id, site_id, title, period_start, period_end, period_label, compare, status (generating|ready|failed), error, summary: string[], note, sections, trigger (manual|scheduled), generated_by: {id, name}|null, created_at, sent_at, sent_to: string[], share: {url: "{FRONTEND_URL}/r/{token}", expires_at|null}|null}` (`share` null when none or expired; the token is never returned on its own).

**Schedule** = `{site_id, is_active, day_of_month, send_hour, recipients, sections, compare, note, last_run_at, next_run_on, next_run_at}` (`next_run_at`: this month's day at `send_hour` if that's still ahead and the schedule hasn't run this month, else next month's, ISO 8601 SAST; `next_run_on` is its date; both null when off).

**Snapshot** (`data`; ids, emails and storage paths are never in it) = `{client: {name}, site: {name, url}, period: {start, end, label}, compare_period: {start, end, label}|null, sections, …one key per requested section}`. A section is `{available: false, reason}` (plain words, e.g. "Google Search Console is not connected for this website.") when it has nothing for the period, else:
- `search_console`: `{available: true, data_through, totals: {clicks, impressions, ctr, position}, compare_totals|null, changes: {clicks_pct, impressions_pct, ctr (percentage points), position (positive = moved up)}, series: [{date, clicks, impressions}] (days Google has published), top_queries: [{query, clicks, impressions, position}] (10), top_pages: [{page, …}] (10), quick_wins: [{query, page, position, impressions, potential_clicks}] (5)}` (the Search Console reports' maths).
- `rank_tracking` (active keywords; "now" = each keyword's latest done check by the period end, "start" = its latest before the period, else its first in it): `{available: true, keywords_tracked, keywords_checked, avg_position, avg_position_start, avg_position_change (positive = up), distribution, distribution_start (exclusive bands as in Rank tracking), entered_top10, left_top10, entered_top3, left_top3: {count, keywords: string[] (≤ 10)}, gains, drops: [{keyword, location_name, device, from, to, change}] (5 each; not ranking = 101), serp_features: {featured_snippet|local_pack|ai_overview: {present, ours}}}`.
- `site_audit` (the latest done audit finished by the period end): `{available: true, audited_on, pages_crawled, health_score, previous_health_score, health_change, counts: {critical, warning, notice, total}, fixed, new (null without a previous audit), top_issues: [{title, severity, count, how_to_fix (first sentence)}] (5)}`.

The stored snapshot also holds `branding: {name, logo (storage path), brand_color}`; responses replace it with the top-level `branding: {name, logo_url, brand_color}`. A logo replaced since generation is served from the workspace's current logo.

**Summary rules** (`App\Services\Reports\ReportSummary`, deterministic, from the snapshot): candidates in this order: search traffic ("Search traffic up 14% (5,190 clicks)" / "down 8% (…)" / "steady at …" / "5,190 clicks from Google search" without a comparison), keywords that entered the top 10, reached the top 3, dropped out of the top 10, site health ("Site health 88/100, up 4 points" / "steady at …"), critical issues ("2 critical issues need attention"), times shown in Google (only when it moved ≥ 5 %), issues fixed. **Significant** ones lead (keeping that order): |Δ| ≥ 5 % for traffic and impressions, ≥ 3 keywords for a band, ≥ 5 health points, any critical issue. At most 5, at least 2 when there's anything (the number of keywords tracked fills in); nothing at all → "No search, ranking or website health data was available for this period." Numbers with thousands separators (5,190), percentages whole, proper plurals. Thresholds in `config/indlela.php` `reports`.

**Email** (`EmailTemplateType::ClientReport`, `client_report`, editable in Settings › Email templates; variables `workspace, client, site, month, title, summary, note, message, url`; no `app_name`). Default subject "Your {{month}} SEO report from {{workspace}}". `{{summary}}` (a bullet list), `{{note}}` and `{{message}}` are built as escaped HTML after the template's variables. `ClientReportMail` uses its own layout (`emails/client-report`: the workspace logo embedded, its colour, "Sent by {workspace} for {client}"), **From name = the workspace name** (address `MAIL_FROM_ADDRESS`), **Reply-To** = the user sending, else the user who generated it, else the workspace's first active Owner; the PDF is attached. Settings' "send test email" for this template still uses the standard layout.

**Monthly schedule.** `reports:run-scheduled` hourly (on the hour, Africa/Johannesburg): active schedules whose `day_of_month` is today and whose `send_hour` has been reached (a missed hourly run catches up later the same day), not run this month, on an active site → last calendar month's report (`trigger` scheduled, `generated_by` null) emailed to the schedule's recipients. Each schedule is claimed with one conditional `UPDATE … last_run_at` before its report is made (idempotent per site per month, safe on two servers); every report gets its own job.

### Platform (Indlela staff)

**Platform › Usage** shows the company's DataForSEO spend and every workspace's rank-tracking usage, for pricing decisions. It exists **only in the platform workspace**: `INDLELA_PLATFORM_WORKSPACE` (`config('indlela.platform_workspace')`, a workspace slug; empty = no platform workspace, the page is off everywhere).

- **Permission** `view_platform_usage` (group Platform, resource `platform_usage`, view only; `Permissions::PLATFORM_ONLY`). In the platform workspace it is an ordinary permission: Owner always has it, a **Superuser** role is seeded (every grantable permission + this one, all clients, editable like Manager) and Settings › Roles shows the Platform group so it can be given to other roles. In **every other workspace** it doesn't exist: not in Owner's set, not in the Roles grid (`groups`), refused when saving a role (422 `permissions.N`), filtered out of `/me` / login / switch `permissions`, and `RolePermissionSeeder` takes it off any role there (e.g. after the config moves).
- **Endpoints** need both: the current workspace is the platform workspace **and** the user has `view_platform_usage` there (gate `view-platform-usage`). Otherwise 403 `{message: "Platform usage is only available to Indlela staff."}`. Switching workspaces hides it.
- **Figures** are the only cross-workspace queries in the app (`App\Services\Platform\PlatformUsage`, the workspace scope dropped explicitly, grouped SQL, no N+1). Months are calendar months of `rank_checks.checked_on` (SAST), as in the spend cap. `cost` = Σ `rank_checks.cost` (what DataForSEO charged); `spend.this_month` = that + `spend.pending` (expected price of checks posted and not answered yet) — the same number the cap uses; `cap` null = no cap, `cap_used_pct` = this_month ÷ cap × 100 (1 dp). A **check** = a row posted to DataForSEO (`posted_at` set; checks skipped by the cap or with no credentials don't count); **live checks** = Σ `live_requests` ("check now" requests, billed even when they fail). `series` = the last 6 months, oldest first, charged cost only (so this month's series cost excludes pending). Totals: `workspaces` = workspaces not deleted; `active_workspaces` = those with ≥ 1 active keyword; `keywords_tracked` = active, non-deleted keywords. Rows: `keyword_limit` = effective limit, `keyword_limit_override` = `workspaces.rank_keyword_limit`; `owner` = the workspace's first Owner (lowest user id; deleted accounts skipped); `last_check_at` = latest done check's `completed_at`. Spend from deleted workspaces counts in the totals but they have no row. USD amounts are rounded to 4 dp.
- **Provider**: `configured` = DataForSEO credentials set, `sandbox` = `DATAFORSEO_SANDBOX`, `balance` = the account balance in USD (cached 10 minutes; null when not configured or unreachable).

## Permission catalogue

Names are `{action}_{resource}` (`App\Support\Permissions`; the SPA's `src/config/modules.ts` mirrors it). Every action needs `view`. Owner-only resources can't be given to another role.

| Group | Resource | Actions | Notes |
|---|---|---|---|
| Dashboard | `dashboard` | view | |
| Clients | `clients_clients` | view, create, update, delete | Adding needs a role that sees every client |
| Clients | `clients_sites` | view, create, update, delete | |
| Search Console | `search_console` | view, create, update, delete | View = connection status, sites and reports; update = connect/disconnect Google, link/unlink a property, sync now (create and delete are not used yet) |
| Site audit | `site_audit` | view, create, update, delete | View = sites, audits, issues, pages, vitals, comparison; update = run an audit now, cancel, audit settings (create and delete are not used yet) |
| Rank tracking | `rank_tracking` | view, create, update, delete | View = sites, keywords, reports, usage, locations; update = add, change, delete keywords, bulk, "check now" (create and delete are not used yet) |
| Reports | `reports` | view, create, delete | View = sites, reports, a report and its PDF, the schedule; create = generate, send, share links (create / rotate / revoke), save the schedule; delete = delete a report |
| Settings | `settings_workspace` | view, update | Owner only |
| Settings | `settings_users` | view, create, update, delete | Owner only |
| Settings | `settings_roles` | view, create, update, delete | Owner only |
| Settings | `settings_login_history` | view | Owner only |
| Settings | `settings_email_templates` | view, update | |
| Platform | `platform_usage` | view | Platform workspace only (`INDLELA_PLATFORM_WORKSPACE`): Owner, Superuser, or a role given it there. Doesn't exist in any other workspace |

## Default roles (every new workspace)

| Role | Clients | Access |
|---|---|---|
| Owner | all | Everything, always. Can't be renamed, changed or deleted; the workspace keeps at least one active Owner |
| Manager | all | Every action on dashboard, clients, sites, the SEO tools and reports; email templates. No users, roles, login history or workspace |
| Analyst | assigned | View dashboard, clients and sites; view/create/update/delete on Search Console, site audit and rank tracking; view/create/delete reports |
| Client | assigned | View only: dashboard, clients, sites, Search Console, site audit, rank tracking, reports (read-only client login) |
| Superuser | all | **Platform workspace only.** Indlela staff: every permission a non-Owner role may hold, plus Platform › Usage. Seeded once, then editable like Manager |
