# ODP bulletins

Gridhouse publishes plain-language summaries from one business-owned sheet through the public worker. This plugin only reads that feed. It does not call Google Sheets, does not store a service account, and does not expose a publish endpoint.

Public feed, no API key:

`https://gridhouse-odp-bulletins.gridhouse-digital10.workers.dev`

Schema 1 item fields, after the worker has already dropped drafts, future rows, and expired rows:

`id`, `headline`, `preview`, `body`, `published_at`, `expires_at`, `audience`, `source_url`

`audience` is `employee`, `agency_lead`, or `both`. It chooses which logged-in view lists the summary. It is not a secrecy boundary and it does not grant access. The viewer’s context comes from capabilities already on the account: `GHCA_ACD_Roles::user_can_view()` is the agency view (`agency_lead` and `both`). Any other logged-in account is the employee view (`employee` and `both`). A request cannot pick the other context.

## Consumer API

In-process, for a later employee plugin in the same site:

- `GHCA_ODP_Bulletins::snapshot( $context )` with `employee` or `admin` chosen by the caller’s own capability check
- `GHCA_ODP_Bulletins::lookup( $id, $context )`

REST responses send `Cache-Control: private, no-store`. Browser calls that use the login cookie must also send `X-WP-Nonce` (the `wp_rest` nonce). Without that nonce WordPress treats the cookie as logged out. The nonce is `ghcaOdp.nonce` on the redesigned dashboard and `<meta name="gh-odp-rest-nonce">` on bulletin pages. `ghcaOdp.context` is the trusted context. Do not send a different one.

- `GET /wp-json/ghca-odp/v1/bulletins?context=employee` only for accounts that are not dashboard viewers
- `GET /wp-json/ghca-odp/v1/bulletins?context=admin` only for accounts that can view the compliance dashboard
- `GET /wp-json/ghca-odp/v1/bulletins/{id}` uses the same trusted context. A current item for the other audience returns `not_in_view` and no text. An id missing from a stale saved feed returns `unconfirmed` (503), not a claim that it does not exist.

List responses contain only that context’s audience filter. Do not store them in a shared cache. The server cache stores the full validated feed in the option `ghca_odp_feed_cache` and filters per request.

The employee plugin should not register `/odp-bulletins`. This plugin owns those routes. Return something other than `ghca-acd` from `ghca_odp_route_owner` to opt out. There is no employee-plugin repository in this workspace. Existing hooks that stay as they are: page slug `employee-dashboard`, branding selector `.ghca-ecd`, and `ghca_employee_support_email`.

## Cache

- Fresh copy (under 30 minutes): no network call.
- Stale copy: the page renders the saved feed immediately and schedules one cron refresh (`ghca_odp_refresh_feed`) via `spawn_cron()`, which does not wait on the worker. If cron is disabled, the saved feed stays until a cold read replaces it.
- No saved copy: the request waits, up to 8 seconds, because there is nothing else to show. That first view is the cold-cache cost.
- Invalid JSON, unknown `schema_version`, oversize bodies, and HTTP errors do not replace the last valid feed.
- A valid empty feed does replace it. Empty and unavailable stay different. A stale unknown id is unconfirmed, not missing.

## Behavior

- Body cap 512 KB. Redirects are not followed. The URL must be the public worker allowlist.
- Dates are checked again at render. Expired and not-yet-available ids do not show the body.
- The other audience does not show the body on the full page or the single REST response.
- `source_url` is an explicit “Read source” link, http(s) only.
- Body and preview are plain text. The drawer and the full page use the same body.
- Routes are the path `/odp-bulletins/` and `/odp-bulletins/{id}/` only. A query parameter on another URL does not open them.
- Disclaimer: “This is a plain-language summary from Gridhouse, not an official ODP publication.”

Agency announcements stay on their own CPT.

## Rollback

Overview chrome is off until `ghca_acd_ui_redesign` is saved. Bulletin routes stay available either way. Deactivating the plugin removes the rewrite version and flushes rules. Deleting `ghca_odp_feed_cache` drops the last feed copy.

## Rewrite version and a stale `notoptions` cache

Rules are flushed once, at `shutdown`, until `ghca_odp_rewrite_version` holds the current version. With a persistent object cache, a cross-request race can leave that key in the cached `notoptions` list after the row was written. `get_option()` then reports it missing. `update_option()` falls through to `add_option()`, whose upsert changes 0 rows for an identical value, so core returns before it clears `notoptions`. Every request would register and run the flush again.

Before flushing, the plugin removes only this key from `notoptions`, the same way core does after a successful write, and reads the option again through `get_option()`. It does not query the table directly, write the row again, or flush the object cache. `tests/integration-odp-rewrite-notoptions.php` reproduces the state in a local WordPress and fails without this change. The test runs on the default in-memory object cache. It does not prove why a Redis install got into that state.

## Not in this slice

Employees, courses, certificates, reports, audit, external training, drawers other than the bulletin drawer, the employee dashboard plugin, and the settings boards beyond the overview checkbox.
