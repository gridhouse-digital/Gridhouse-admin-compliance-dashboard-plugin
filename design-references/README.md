# Design references

These notes record what was recovered from the handoff bundles. The bundles are Claude artifact pages (gzip plus base64 under `__bundler/manifest`). Tokens below were decoded statically. The bundles are not runtime assets and are not enqueued.

## Authority

| Source | Role |
| --- | --- |
| `074e1b13` | Admin suite. Overview is the visual target for this slice. Other boards are inventory, not implemented screens. |
| `60f67e24` | Employee suite. Shared architecture only. This plugin does not absorb that dashboard. |
| `aa5355e2` | Responsive layout reference. |
| `be8cca04` | Design system v2.3. Brand themes Gridhouse, Akansoh, and Purple. Status colors stay fixed. See `tokens.css`. |
| `8b5a3d5e` | Developer handoff. Proposed APIs were reconciled with this plugin instead of treated as a spec to invent. |
| `1195298e` | Settings boards, including unsaved and save-failed. Existing settings forms and `assets/settings-console.js` already cover unsaved changes. The boards were not rebuilt. |
| `4fa32636` | Authoritative bulletin library, full page, drawer, and states. |
| `5cef753d` | Earlier drawer-only pack (loading, no source, offline, phone sheet). Use it where `4fa32636` does not overlap. Where they overlap, `4fa32636` wins. |
| PDF and the two dashboard screenshots | Visual companions for the current admin and employee dashboards. |

The drawer and the full page render one plain-text body. The library uses the separate preview. Sample banners and “NEW, proposed” are not production states.

Runtime theme tokens are scoped in `assets/gh-redesign.css`. `tokens.css` is the copy the employee plugin can reuse if it scopes the same variables itself.
