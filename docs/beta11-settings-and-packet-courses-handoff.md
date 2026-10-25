# Beta 11 — settings presentation and agency-selected packet courses

## Scope and decisions

User approved proceeding from the console prototype, matching WordPress admin colors. This first implementation pass adds a scoped shared settings header/navigation, active admin-scheme accent, native buttons/fonts, responsive form/table styling and form-specific unsaved-change warnings. It keeps the WordPress sidebar/toolbar, existing endpoints, option keys, forms, permissions and notices. Main settings form remains intact (training, branding, performance and lifespans); links jump to existing sections, avoiding lost values from splitting a registered option group. This is not a full port of the prototype's sidebar/overview or a bespoke redesign of every review table. Dashboard and employee drawer are unchanged. Agency-profile guidance uses disclosures without hiding errors or the unknown annual cycle.

The requested custom-report explanation paragraph is removed. Reporting heading, exact dates, reporting-only semantics, calculations, evidence warnings and absence of an annual verdict are unchanged. Removing wording does not create a frozen archive or recover overwritten completions.

Settings → Agency-selected packet courses: separate annual (also custom-date) and orientation selections; up to 30 each, LearnDash and active external catalog. A separate native Settings API form/group prevents accidentally overwriting main settings. Blank sentinels permit explicit clearing, a trailing completion marker protects against truncated submissions, capability/nonce protection is retained, invalid data preserves previous selections, and first-save double sanitization is supported. Unavailable prior selections remain visible for explicit removal.

Calculator returns display-only rows from the already-filtered `raw_completed_courses`. External catalog ID is carried from its approved snapshot. No new credit, enrollment, evidence, category or exemption is created. Missing matches say “No included evidence,” not regulatory failure; selected unmapped/ineligible courses do not magically become included. PDF places “Agency-selected courses” below ODP topics inside the first table; a short label explains that displayed hours are already in totals. Custom-date job snapshot checks include selected rows. Existing per-course and certificate sections are unchanged. Broad annual/category and completion-checkbox fixes from beta 9/10 remain included.

## Verification

- `php tests/test-packet-course-selection.php`: 283 cumulative checks (18 new), including both sources/modes, totals, escaping, date/approval exclusion, defaults, malformed/truncated/oversized saves and sanitizer idempotence.
- `php tests/test-settings-console.php`: 21 page scope/navigation/escaping checks, including messaging-only access and no frontend/unrelated-page styling.
- Agency profile 47, annual settings 27, course correction 64 cumulative, existing calculator and packet-job scripts pass. All 157 PHP files linted; settings JS syntax and DOM-double interaction checks pass.
- Browser attempt failed: `failed to write kernel assets: The system cannot find the path specified. (os error 3)`. No rendered desktop/mobile/keyboard or real WordPress save verification claimed. Final TCPDF table pagination remains unverified.

## Staging acceptance / remaining

Release packaged: `../packages/Gridhouse-admin-compliance-dashboard-1.7.3-beta.11-test-only.zip` (relative to plugin root), with adjacent `1.7.3-beta.11-manifest.csv`. All 116 ZIP entries verified against source SHA-256 hashes and byte counts. ZIP SHA-256: `5520D2850E353D3BE94D3B8A1DCF898A8FFC74F29173C05B80C6C470D788B78C`. Targeted packet (283 cumulative), console (21) and JavaScript interaction checks passed again at packaging. Compared with beta 10, changes are confined to the entrypoint, settings/calculator/PDF, four new console/selection assets and classes, README and TESTING; other packaged files retain their prior hashes.

Install as a replacement on staging, not alongside another copy. Review multiple WP admin schemes, narrow widths, keyboard access, notices, navigation and dirty-form warnings. Verify each existing settings screen still saves only its own fields under its existing permissions. On Settings save annual and orientation selections separately, reload, clear them, and generate fresh annual/custom/orientation packets. Confirm course rows follow topics, no doubled totals, correct dates, full certificates and acceptable page breaks with long labels/30 rows. Verify unauthorized roles cannot access or save either form. Existing downloaded PDFs do not change. Production approval is not granted by this package.

No site installation, database mutation, employee edits, Jotform request/submission, message or integration test was performed. Browser-independent testing uses fictional records. No Git metadata; package manifest records scoped release parity. Old ZIPs retained.
