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

## Permission catalogue

Names are `{action}_{resource}` (`App\Support\Permissions`; the SPA's `src/config/modules.ts` mirrors it). Every action needs `view`. Owner-only resources can't be given to another role.

| Group | Resource | Actions | Notes |
|---|---|---|---|
| Dashboard | `dashboard` | view | |
| Clients | `clients_clients` | view, create, update, delete | Adding needs a role that sees every client |
| Clients | `clients_sites` | view, create, update, delete | |
| Search Console | `search_console` | view, create, update, delete | View = connection status, sites and reports; update = connect/disconnect Google, link/unlink a property, sync now (create and delete are not used yet) |
| Site audit | `site_audit` | view, create, update, delete | View = sites, audits, issues, pages, vitals, comparison; update = run an audit now, cancel, audit settings (create and delete are not used yet) |
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
