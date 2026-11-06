# ODP bulletins

Gridhouse publishes plain-language summaries from one business-owned sheet through the public worker. This plugin only reads that feed. It does not call Google Sheets, does not store a service account, and does not expose a publish endpoint.

Public feed, no API key:

`https://gridhouse-odp-bulletins.gridhouse-digital10.workers.dev`

Schema 1 item fields, after the worker has already dropped drafts, future rows, and expired rows:

`id`, `headline`, `preview`, `body`, `published_at`, `expires_at`, `audience`, `source_url`

`audience` is `employee`, `agency_lead`, or `both`. It is a label for which list shows the summary. It is not a permission. Logged-in page rules still apply.

## Consumer API

In-process, for a later employee plugin in the same site:

- `GHCA_ODP_Bulletins::snapshot( 'employee' )` or `'admin'`
- `GHCA_ODP_Bulletins::lookup( $id )`

REST, cookie session, `Cache-Control: private, no-store`:

- `GET /wp-json/ghca-odp/v1/bulletins?context=employee`
- `GET /wp-json/ghca-odp/v1/bulletins?context=admin` (requires the compliance dashboard capability)
- `GET /wp-json/ghca-odp/v1/bulletins/{id}` (any logged-in user)

List responses contain only that context’s audience filter. Do not store them in a shared cache. The server cache stores the full validated feed in the option `ghca_odp_feed_cache` and filters per request.

The employee plugin should not register `/odp-bulletins`. This plugin owns those routes. Return something other than `ghca-acd` from `ghca_odp_route_owner` to opt out. There is no employee-plugin repository in this workspace. Existing hooks that stay as they are: page slug `employee-dashboard`, branding selector `.ghca-ecd`, and `ghca_employee_support_email`.

## Behavior

- Fetch timeout 8 seconds, body cap 512 KB, logical cache 30 minutes.
- Invalid JSON, unknown `schema_version`, oversize bodies, and HTTP errors do not replace the last valid feed.
- A valid empty feed does replace it. That is a real empty state.
- Network failure with a saved feed is `offline`. A bad payload with a saved feed is `cached`. No saved feed is `unavailable`.
- Dates are checked again at render. Expired and not-yet-available ids do not show the body.
- Direct links still show a current bulletin when its label is for the other list, with a visible note. Library and ticker stay filtered.
- `source_url` is an explicit “Read source” link, http(s) only, and never followed automatically.
- Body and preview are plain text. The same body string is used for the drawer and the full page.
- Disclaimer on every bulletin view: “This is a plain-language summary from Gridhouse, not an official ODP publication.”

Agency announcements stay on their own CPT. Nothing here publishes them.

## Routes

Logged-in only. Dashboard capability selects the agency shell. Any other logged-in user gets the employee shell, which is not the employee dashboard plugin.

- `/odp-bulletins/`
- `/odp-bulletins/{id}/` with ids matching `[a-z0-9]+(?:-[a-z0-9]+)*`

Unknown id: 404. Not available yet: 404. Expired: 410. Feed unavailable: 503. Those pages do not invent a sample bulletin.

## Rollback

Overview chrome is off until `ghca_acd_ui_redesign` is saved. Uncheck it under Gridhouse Compliance → Settings → Performance & warning windows. Bulletin routes are additive. Deactivating the plugin removes the rewrite flag and flushes rules. Deleting `ghca_odp_feed_cache` drops the last feed copy.
