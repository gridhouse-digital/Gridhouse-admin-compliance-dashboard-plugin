# Dashboard redesign, phase 2 — remaining screens, sequence and acceptance

Planning document only. No runtime code changes. Phase 1 (overview plus the ODP
bulletin ticker, drawer, library and detail page) is `c6170035137f908eaabcdccd167948d5e9c59d40`
on `gridhouse-overview-odp-bulletins-af45`, open as PR 19 against
`feature/dashboard-redesign-odp-bulletins`. Plugin header stays `1.7.3-rc.8`.

## 1. Sources reviewed

| Source | What it gave |
| --- | --- |
| Design canvas "Gridhouse Compliance — Phase 2 Dashboards", version `1793995837-628a` | 72 artboards on 7 pages, the authoritative board set for phase 2. |
| `project/canvas.json` (sha256 `526187cc…`) | Board inventory, page grouping, frame sizes, titles. |
| `project/Handoff.dc.html` | 45-component contract, the theming pipeline, screen-to-endpoint map against 1.7.3-rc.8, and the design's own acceptance checklist. |
| `project/gh-tokens.css`, `project/gh-components.css` | Design-system CSS. |
| `project/gh-app.css`, `project/wp-admin.css` | App/prototype layer. Not yet vendored here. |
| `docs/dashboard-redesign-phase1.md`, `docs/odp-bulletins.md`, `design-references/README.md` | What shipped, the scoped-CSS build, fonts and licences. |
| `includes/`, `assets/`, `tests/` | Actual data sources, capabilities, AJAX actions and options. |

### Design-system CSS is already current

`design-references/board-source.css` is byte-identical to the canvas's
`gh-tokens.css` + `gh-components.css` (one blank line apart; the `@font-face`
`@import` is stripped because the fonts are self-hosted). Tokens v2.1.0 through
v2.4.0 are present. **No design-system CSS refresh is needed.**

Two canvas stylesheets are *not* vendored:

- `gh-app.css` (16 KB, sha256 `e4a09783…`) — the app layer. It is not only
  prototype scaffolding: it carries `.app-adm-*` (the Administration drawer),
  `.app-cal-*` (date picker popover), `.app-overlay` / `.app-drawer--wide` /
  `.app-modal--sm|md|lg` (overlay sizing in a real viewport), `.app-split`,
  `.app-stack`, `.app-grid-2`, `.app-toggle`, `.app-tabbar`, and the
  `.app-hide-md` / `.app-hide-lg` rules that drop low-priority table columns
  before a table would scroll sideways. Every remaining drawer, modal and
  settings board depends on it. Prototype-only parts (`.app-protobar`,
  `.app-doc`, `.app-new`, `html`/`body`/`#root` rules) must be dropped by
  `scope-board-css.py`, as the board-only classes already are.
- `wp-admin.css` (3.6 KB) — emulated WordPress admin chrome. **Not needed.**
  Real wp-admin provides it. Reference only, for checking that the plugin's
  screen sits correctly inside the native menu and admin bar.

## 2. Screen inventory

### Shipped in phase 1

`Admin-Overview`, `M-Admin-Overview`, `AdminChrome` (page head and tabs only —
app bar, global nav and footer deliberately not rendered when embedded), and
the whole ODP set: `ODP-Drawer-Admin`, `ODP-Drawer-Employee`,
`ODP-List-Admin`, `ODP-List-Employee`, `ODP-List-Drawer-Admin`,
`ODP-List-Drawer-Employee`, `ODP-Page-Admin`, `ODP-Page-Employee`,
`M-ODP-List`, `M-ODP-Page`, `M-ODP-Sheet`.

### Remaining in this repository — 37 boards

| Group | Boards |
| --- | --- |
| Admin tabs (9) | `Admin-Employees`, `Admin-Inactive`, `Admin-Overdue`, `Admin-Courses`, `Admin-Certificates`, `Admin-Reports`, `Admin-UserReport`, `Admin-AuditData`, `Admin-ExternalReview` |
| Employee drawer (5) | `Drawer-Training`, `Drawer-Documents`, `Drawer-Communications`, `Drawer-Administration`, `Drawer-EditRecords` |
| Modals and tools (4) | `Modal-SendReminder`, `Modal-Announcement`, `ManageUsers`, `Print-AuditPacket` |
| WP admin settings (16) | `WP-Settings-` `Training`, `Validity`, `Audit`, `Oltl`, `Branding`, `Profile`, `People`, `Permissions`, `Email`, `Sms`, `Templates`, `Jotform`, `Catalog`, `Performance`, plus `TrainingDirty` and `TrainingError` states |
| Mobile and tablet (3) | `M-Admin-Employees`, `M-Admin-Sheet`, `T-Admin-Employees` |

### Remaining in the employee plugin — 10 boards

Repository identified: **`gridhouse-digital/Gridhouse-employee-compliance-dashboard-plugin`**
(public, last pushed 2026-07-13). Not attached to this session; nothing has been
read from or written to it. Boards: `EmpChrome`, `Emp-Home`, `Emp-Courses`,
`Emp-Certificates`, `Emp-Handbook`, `Emp-Policies`, `M-Emp-Home`,
`M-Emp-Courses`, `M-Emp-Policy`, `M-Emp-Certs`.

### Reference boards — not screens, do not implement

`Main`, `Foundations`, `ControlsSet`, `DataSet`, `OverlaySet`,
`Library-3Themes`, `Handoff`, `ODP-Drawer-Spec`. The `*-States` boards
(`ODP-Drawer-States`, `ODP-List-States`, `ODP-Page-States`) are states of
shipped screens: verify against them, do not rebuild.

## 3. Proposed implementation sequence

Each slice is one commit range on `gridhouse-overview-odp-bulletins-af45`, with
its own evidence pack (commit, screens, tests, screenshots, gaps, ZIP plus
checksum). Order is driven by shared dependencies first, then by the journey a
user actually walks, so no slice leaves a half-redesigned path.

### Slice 2 — Table kit, Employees and Inactive

Boards: `Admin-Employees`, `Admin-Inactive`, `M-Admin-Employees`,
`T-Admin-Employees`.

Fold `gh-app.css` into `build-gh-redesign.sh` (prototype rules dropped), then
build the shared primitives every later tab reuses: `FilterBar`, `FilterChip`,
`DataTable` (sortable headers, row tone, sticky header, empty and
filtered-empty, skeleton), `Pagination`, `EmployeeCard` for < 720 px, `Toast`
over the existing flash notices, `EmptyState`, `Skeleton`.

Data: `GHCA_ACD_Shortcodes::render_employee_table()` / `get_employee_table_html()`,
`wp_ajax_ghca_acd_filter_table`. Sorting, `per_page` and `paged` already exist.
Inactive is the same renderer with `account_status = 'inactive'`, gated on
`GHCA_ACD_Roles::user_can_manage_users()`.

First because: eight of the nine remaining tabs are the same kit with different
columns. Doing it once removes the risk from every later slice.

### Slice 3 — Employee drawer and the reminder path

Boards: `Drawer-Training`, `Drawer-Documents`, `Drawer-Communications`,
`Drawer-Administration`, `Drawer-EditRecords`, `Modal-SendReminder`,
`M-Admin-Sheet`.

Data: `wp_ajax_ghca_acd_get_employee_drawer`; `…_jotform_documents`,
`…_document_token`, `…_document_access`, `…_manual_token`, `…_manual_access`;
`…_get_communication_history`, `…_get_communication_draft`, `…_retry_reminder`;
`…_get_edit_records_form`, `…_save_employee_records`, `…_agency_training`,
`…_mark_reviewed`, `…_save_audit_dates`; `…_get_reminder_context`,
`…_send_reminder`. Drawer is 860 px per `gh-app.css`, a full-screen sheet below
720 px, focus-trapped, Esc closes, focus returns to the row.

Immediately after slice 2 because: every row in the new Employees table opens
this drawer. Shipping the table alone would put a redesigned list in front of a
legacy panel on the most-used screen in the plugin.

### Slice 4 — Overdue, Courses, Certificates

Boards: `Admin-Overdue`, `Admin-Courses`, `Admin-Certificates`.

Data: `render_overdue_employees()` / `get_priority_table_html()`,
`render_course_overview()` / `get_course_table_html()`,
`render_certificate_tracking()`, `wp_ajax_ghca_acd_certificate_download` and
the certificate broker. Same kit as slice 2; the certificates board adds the
`CertList` tones already built for the overview.

### Slice 5 — Reports and user report

Boards: `Admin-Reports`, `Admin-UserReport`.

Data: `render_reports()`, `GHCA_ACD_User_Report`,
`admin_post_ghca_acd_export_csv`, `GHCA_ACD_Export`. The four export cards and
the `SubTabs` course report. See §5 for the sub-tabs and columns that have no
data behind them.

### Slice 6 — Audit data, external review, print packet

Boards: `Admin-AuditData`, `Admin-ExternalReview`, `Print-AuditPacket`.

Data: `GHCA_ACD_Audit_UI::render()`,
`admin_post_ghca_acd_audit_export_csv`, `GHCA_ACD_Audit_Calculator`;
`GHCA_ACD_Jotform_UI::render_frontend_review()`,
`admin_post_ghca_acd_jotform_training_action`,
`…_jotform_bulk_reject_unsigned`, `wp_ajax_ghca_acd_jotform_ownership_claim`;
`GHCA_ACD_Audit_PDF` and `class-audit-pdf-jobs.php` for the packet, with
`.gh-print` page-break CSS. Audit calculations, the verified-start-date rule and
the 30-day verdict are presentation-only here — no change to
`class-audit-calculator.php`.

### Slice 7 — Announcements and manage users

Boards: `Modal-Announcement`, `ManageUsers`.

Data: `wp_ajax_ghca_acd_get_announcements`, `…_save_announcement`,
`…_delete_announcement`, `GHCA_ACD_Announcements`;
`GHCA_ACD_Manage_Users_UI`, `wp_ajax_ghca_acd_save_employee`. Agency
announcements stay separate from ODP bulletins.

### Slice 8 — WordPress plugin settings

Boards: the 14 `WP-Settings-*` sections plus `TrainingDirty` and
`TrainingError`.

All 14 sections map onto options that already exist in `class-settings.php`,
`class-branding.php` and `class-settings-console.php` — new-hire groups and
window, `ghca_acd_annual_cycle` and `ghca_acd_at_risk_days`,
`ghca_acd_isp_manual_delivery`, `ghca_acd_packet_course_details`, course
validity and packet course selection, audit mapping, OLTL, `ghca_dashboard_brand`,
`ghca_acd_agency_profile`, employment types, the seven
`ghca_acd_permission_*` options, reminder email, Twilio and SMS, message
templates, Jotform, external catalog, `ghca_acd_cache_ttl`.

**One decision needed before this slice starts.** The board's note reads "one
sectioned screen replacing the plugin's nine admin pages", with a sticky section
rail. The plugin currently registers eight sub-pages under one top-level menu
(`GHCA_ACD_Admin_Menu::pages()`), with slug-stable legacy-bookmark redirects and
per-page capabilities — messaging is reachable by users who cannot
`manage_options`. Collapsing nine screens into one changes capability gating,
URLs and bookmarks. Two options:

- **A (recommended): keep the eight registered pages, restyle each in place**
  and add the section rail as in-page navigation across them. Slugs,
  capabilities and redirects are untouched; visual result matches the boards.
- **B: one screen with client-side sections.** Matches the board literally, but
  needs a new capability model for a single page and breaks saved URLs.

Unsaved-changes and save-failed behaviour already exists in
`assets/settings-console.js`; those two boards are a restyle, not new logic.

### Slice 9 — Employee plugin (separate repository)

Not started until this repository's slices are accepted and the employee repo is
attached to a session. The ODP employee boards are already served by this
plugin's ODP module and are not part of that work.

### Alternative order

If a single finished tab matters more than shared groundwork, slices 4–6 can
move after slice 7; slices 2 and 3 cannot move, because everything else depends
on the table kit and the drawer sits on the Employees journey.

## 4. Acceptance checklist

### Gates applied to every slice

Design fidelity

- [ ] Spacing, colour, border, radius and icon match the board at 1440 px, read
      from computed styles, not by eye.
- [ ] Typography matches family, weight, size, line height and letter spacing
      per component class, checked with `CSS.getPlatformFontsForNode`.
      `document.fonts` shows only Wix Madefor Display and Text; nothing falls
      back to a system font or synthesises bold.
- [ ] No colour literal in new CSS — `var(--gh-*)` only. Status tokens are never
      overridden by a theme.
- [ ] Verified in all three themes: Gridhouse, Akansoh, Purple.

Decisions already settled

- [ ] Embedded pages use the site's own WordPress, BuddyBoss or Elementor
      header, menu and footer. No duplicate global navigation, and nothing
      restyles the theme's chrome.
- [ ] Section tabs and page actions stay.
- [ ] Quick links and primary page actions, including hover, active, visited and
      keyboard focus, use the agency's saved Branding colour on every theme —
      never a fixed blue. Ink darkens until it passes 4.5:1 on white and on the
      hover tint.
- [ ] Card title/body dividers where the design has them.

Responsive and interaction

- [ ] One 1320 px container (1256 content) on dashboard screens; reading text
      ≤ 760 px; WP settings ≤ 1200 px.
- [ ] Tables collapse to cards below 720 px; tab bars scroll sideways; drawers
      become full-screen sheets.
- [ ] Touch targets ≥ 44 px on phones, ≥ 40 px on desktop.
- [ ] Verified at 1440×900 and 390×844.
- [ ] Keyboard: tab bars use arrow keys; drawers and modals trap focus and
      return it to the trigger on close; Esc closes.
- [ ] Focus visible on every interactive element, ring ≥ 3:1.
- [ ] Anything that moves can be paused and stops under
      `prefers-reduced-motion`.

Accessibility

- [ ] Text ≥ 4.5:1; inputs and focus rings ≥ 3:1, in all three themes.
- [ ] Every status is icon plus label plus colour, readable in greyscale and in
      print.
- [ ] Real `<button>`, `<a href>`, `<input>` and `<label>`; `aria-label` on
      icon-only controls; decorative SVG `aria-hidden`.

Behaviour

- [ ] Overdue, missing and needs-review render above everything else on a page.
- [ ] Every send, delete and generate has a confirm step and a success or error
      toast; success auto-dismisses after 6 s, errors stay, stack caps at 3.
- [ ] Errors say what did *not* happen and keep the user's input.

Correctness and safety

- [ ] Existing calculations unchanged — compliance rate, risk split, annual
      cycle, 30-day verdict, audit dates. Presentation only.
- [ ] Permissions unchanged: every `GHCA_ACD_Roles` gate still applied; no
      control appears for a capability the user lacks.
- [ ] Agency isolation unchanged: `GHCA_ACD_Scoping` group scope enforced on
      every new view; scoping fail-closed tests still pass.
- [ ] Training records untouched — no write path added or altered.
- [ ] Real data only. No invented metric, and no control that does not work.
- [ ] Redesign stays behind `ghca_acd_ui_redesign`; unchecking it restores the
      previous UI completely.
- [ ] Every CSS rule sits under `:is(.ghca-ui, #ghca-ui-scope)`;
      `tests/test-odp-bulletins.php` still fails on any top-level rule.
- [ ] Legacy panels still inside `.gh-legacy` and visually unchanged.

Regression and evidence

- [ ] Every `tests/test-*.php`, the JS and `.cjs` tests, PHP syntax checks and
      `node --check` pass.
- [ ] No regression in LearnDash, BuddyBoss or Elementor (see §6 — currently
      blocked).
- [ ] Evidence pack: exact commit, changed screens, tests run, desktop and
      mobile screenshots, known gaps, installable ZIP with SHA-256, and
      implemented functionality separated from prototype-only behaviour.
- [ ] Staging: change and rollback described, existing private rollback copies
      retained, approval obtained before anything is installed.

### Per-slice gates

- **Slice 2** — sort, filter, `per_page` and pagination still return the same
  rows as the legacy table for the same inputs. Filters debounce at 300 ms.
  Column drop order at 1359 px and 1179 px before any sideways scroll. Inactive
  hidden from users without `user_can_manage_users`.
- **Slice 3** — all four drawer tabs load against real records; document tokens
  and access checks unchanged; edit-records saves write the same values as the
  legacy form; the reminder path keeps its confirm step and sends nothing during
  testing.
- **Slice 4** — certificate downloads still route through the broker; course
  counts match `count_unique_tracked_courses()`.
- **Slice 5** — exports produce byte-identical CSV to the current handler.
- **Slice 6** — generated packets identical to current output; FPDI integrity
  tests pass; print board checked as actual print CSS, not a screen mock.
- **Slice 7** — announcement capability gates unchanged; manage-users picker
  tests pass.
- **Slice 8** — every option round-trips; page slugs and legacy redirects
  unchanged; per-page capabilities unchanged; unsaved-changes and save-failed
  states behave as `settings-console.js` already does.

## 5. Design elements with no current functionality behind them

To be rendered as the board's static state, omitted, or built only on explicit
approval. None will be faked.

| Board element | Status |
| --- | --- |
| `ScopePicker` — page-head "All groups" popover with a checkbox group list | **No feature.** Scope is fixed by role and LearnDash group assignment; there is no user-switchable scope. Keep phase 1's static scope pill. |
| `BulkBar` on `Admin-Employees` — row selection, "Send reminder to 2", "Export selected" | **No feature.** No row selection, no bulk messaging, no selected-rows export. The design's own handoff marks bulk and dry-run as new. Needs approval and a messaging-safety review before it is built. |
| Course report sub-tabs "Tin Can" and "xAPI quizzes"; "Avg quiz" and "Avg time" columns | **Unconfirmed.** No Tin Canny or xAPI integration exists in the plugin. To be confirmed in slice 5; if there is no source, the sub-tabs are omitted and the columns show "—". |
| Report card metadata, e.g. "Last run Oct 30 · 10 rows" | **No feature.** No export-run history is stored. Omit. |
| Certificates "pending issuance" row | **No data.** Already omitted in phase 1. |
| Settings: bulletin composer, audience publisher, Sheet connection | **Out of scope by design.** Agencies do not author bulletins. |
| Settings: assignment-level completion fraction | **No data.** The completion card uses the existing employee compliance rate. |
| Handbook, policies and onboarding checklist | **No plugin equivalent.** Employee plugin scope. |
| Theming pipeline step 2 — `derive-theme.ts` ported to PHP, storing a `gh_theme_tokens` map from six brand inputs | **Blocked on a missing source file.** See §6. Today the plugin overrides primary and accent from saved Branding on preset themes, which covers the settled decision about brand colours but is not the full OKLCH derivation. |

## 6. Missing source files and access

Needed before the slices they affect:

1. **`tokens.json` and `derive-theme.ts`.** The handoff board names both as
   "delivered with this canvas", and `gh-tokens.css` refers to
   `tokens.json → $derivation`. Neither is published on the canvas (98 files
   listed; only `gh-tokens.css`, `gh-components.css`, `gh-app.css` and
   `wp-admin.css` are present). Without them the per-agency OKLCH derivation
   cannot be ported faithfully. Affects slice 8 (Branding). Everything else is
   unblocked.
2. **Employee plugin repository access.** Identified as
   `gridhouse-digital/Gridhouse-employee-compliance-dashboard-plugin`. Not
   attached to this session. Needed only for slice 9.
3. **LearnDash, BuddyBoss and Elementor.** Not installed in the development
   environment. Isolation is currently argued by selector weight plus a
   simulated hostile stylesheet, which is weaker than a real render. Either
   those plugins in a local environment, or an approved staging verification
   pass, is required to tick the regression gate.
4. **Staging verification of populated bulletins.** Staging's outbound guard
   blocks the Worker feed, so only the unavailable state has been seen there.
   The guard stays as it is; the Sheet and Worker are not to be modified and no
   test content is to be published. Populated behaviour therefore remains
   locally verified only until a sanctioned route exists.

Also noted: `BUILD.txt` is not committed — it is generated into the ZIP. The
commit is identified from the PR evidence and the ZIP's `BUILD.txt`.

## 7. Staging and rollback protocol

Unchanged from phase 1 and applied to every slice:

- Work stays on `gridhouse-overview-odp-bulletins-af45`. No merge, no live
  deploy, no email send, no change to staff or training data.
- Before any staging install: describe the change and the rollback, keep the
  existing private rollback copies, and obtain approval.
- Staging is InstaWP site 2707128 only. Replace the existing plugin folder; do
  not install a second copy; do not use the Akanso connector, which points at
  live.
- Rollback is reinstalling the retained previous ZIP, or simply unchecking
  `ghca_acd_ui_redesign`, which restores the previous UI without touching data.
