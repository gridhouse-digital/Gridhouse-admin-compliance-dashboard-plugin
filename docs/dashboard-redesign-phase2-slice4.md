# Dashboard redesign, phase 2, slice 4: Overdue, Courses, Certificates

Opt-in, behind `ghca_acd_ui_redesign`. Unchecking it restores the previous tabs. Training math, certificate counts, group scope, capabilities, messaging and certificate downloads are unchanged. Rows, filters and paging come from the same `GHCA_ACD_Data_Provider` and `GHCA_ACD_Table_UI` calls the legacy tabs use. The legacy renderers in `class-shortcodes.php` were not edited.

Boards: `Admin-Overdue`, `Admin-Courses`, `Admin-Certificates`, from the design canvas "Gridhouse Compliance — Phase 2 Dashboards", version `1793995837-628a`. Commits, oldest first (the bulletin-page correction after the staging review is `c422dc2`, see below): `3ebfd91` (legacy goldens, taken before any change), `87d0cb6` (implementation and tests), `3b06039` (this document, the browser suite and fidelity fixes), `e1370cb` (review changes: Courses default sort, date-only certificate sort, phone tab focus), then this update with the package and rollback notes. The package's exact commit is in its `BUILD.txt` and under [Package](#package).

## What shipped

| Area | Change |
| --- | --- |
| Overdue | Board toolbar with a headline ("3 employees overdue on 6 courses · 2 more due within 14 days"), filter card (search, group, Show, Sort), an Overdue table and a "Due within N days" table, with a pager below them. `GHCA_ACD_Redesign_Lists::render_overdue()`. |
| Overdue rows | Courses still owed (up to three, then "+ N more"; an expired course is marked "(expired)"), days late with the due date, status, View, Remind and a row menu. |
| Due-soon rows | Courses not complete with the next course, days left, progress, status, View and Remind. |
| Last reminder | The date and channels of the latest message, plus the state of its first delivery. Shown only to people with `user_can_view_communication_history()`, and only when the messaging schema is current. One read-only query per page: `GHCA_ACD_Messaging_Repository::latest_for_employees()`. |
| Courses | Board toolbar, filter card (search, group, certificate, Sort), and a table with course, group badge, required, done, in progress, not started, completion bar and certificate flag. `render_courses()`. |
| Certificates | Four tiles (Issued, Expiring soon, Missing, Completed in the last 30 days), a missing-certificate alert above the list, and a list with Issued / Expiring / Missing toggles, search, course and date filters, and a sortable Completed (date) header, the board's only certificate sort. PDF download and Recent completions are also on the tab. `render_certificates()`. |
| Phone | Below 720 px each table becomes cards, with full-size buttons (44 px touch targets). |

### Where the numbers come from

- Overdue and due soon are the provider's `priority` classification from `get_priority_rows()`. The rows and their order are the legacy rows; each section keeps the provider's order.
- The window in "Due within N days" is `GHCA_ACD_Settings::get_at_risk_days()`.
- Days late and days left are whole days between today and the due date already shown, both read in the site time zone.
- If an employee is overdue because a completed course expired, but the cycle due date is still ahead, days late shows "—" and does not count down.
- Course counts are `get_course_overview_rows()`. The default order is the board's, Lowest completion first; ties go by course, then group. "Course A–Z" gives the legacy table's order. Only the order changes, never a count. The headline's tracked-course figure is `count_unique_tracked_courses()`, the same number as the Courses tab badge (acceptance gate for this slice).
- Certificate rows come from the same course records `summarize_certificates()` counts. Issued equals `certificates_issued`, and Missing equals `certificates_missing`; a test checks both.
- Expiring and expired come from the rolling-lifespan state already on each course (`GHCA_Course_Lifespans`).

### Downloads

The PDF button calls the existing `ghca_acd_certificate_download` endpoint, so the broker and every check in it still apply. The button carries `user_id` and `course_id` only, never a certificate link or quiz id.

It is offered only to people with `user_can_view_employee_documents()`, the endpoint's own gate. The script saves the file only if the response is a real PDF; anything else saves nothing and shows an error toast (acceptance gate for this slice).

### How the redesigned markup is chosen

The three filter forms carry `data-ghca-ui="board"`, as on the Employees tab. `ghca_acd_filter_table` answers `priority` and `courses` with board parts only when `board_requested()` is true, after the nonce and dashboard-access checks; otherwise it returns the legacy table.

`certificates` exists only on the redesigned tab, so a request for it without the redesign is refused (400).

### Hooks and fields that did not change

The `priority` and `courses` table ids, and the field names `ghca_pri_*`, `ghca_orderby`, `ghca_order` and `ghca_crs_*`, are unchanged.

`data-ghca-filter-form`, `data-ghca-table`, `data-ghca-table-id`, `data-ghca-page`, `data-ghca-sort`, `data-ghca-employee-drawer` and `data-ghca-reminder` are kept. The Overdue Sort select has no name of its own: it writes into the existing hidden `ghca_orderby` / `ghca_order` fields.

New fields: `ghca_crs_sort`, which the legacy table ignores, and the `ghca_cert_*` fields of the new certificates table.

### Safety properties

- Nothing in this slice writes. The new repository method is a single prepared `SELECT`, with ids cast to integers and bound as placeholders. It is never called without the communication-history permission and a current schema.
- Remind opens the slice-3 reminder modal (confirm step included) and is printed only for `user_can_send_reminders()`.
- The row menu's "Log a reminder" is the existing nonce-protected FluentCRM link. It appears only when FluentCRM is active, the viewer can edit records and can view that employee.
- Only active employees in the provider's scoped view appear. A test checks that inactive employees never do.

## Fidelity

Method as in slices 2 and 3. Each board's own markup is rendered with the unscoped board CSS in Chromium at 1440 px and compared per component with the plugin's render. The properties compared are typography, colour, borders, radius, padding, margin, gap, shadow, height and list style. The comparison script is scratch, not committed.

| Screen | Components | Identical | Remaining differences |
| --- | --- | --- | --- |
| Overdue | 24 | 21 | The More control is a `<summary>`, not a `<button>`, so text properties differ on an icon-only control, and the board shows its focus ring as a demo state. The menu and table heights differ only because of content (two menu items without FluentCRM, three overdue rows against the board's two). |
| Courses | 16 | 9 | The board's rows come from a `{{template}}` that does not render as a table outside the canvas, so cell borders, heights, the bar and the certificate cell colour cannot be compared there. The Overdue board's real rows match. The filter card and pager are the slice-2 kit (chip line, per-page select). |
| Certificates | 40 | 30 | Content heights (five rows and four completions against the board's template rows), the filter strip's chip line, the pager's per-page select, the 4 px KPI margin that turns the tab's 16 px gap into the board's 20 px, and the template table cells. |

Fixed during the comparison:

- The row menu's text weight and `<small>` size now follow the board render.
- The More control's padding is the board's.
- The PDF control is a `<button>`, as on the board.
- The "Show missing" button takes the board link's 12 px / 600 type.

Isolation: the hostile stand-in theme (forced link colour and underline, hover fills, heading case and family, table borders, input, label, `summary` and list rules) was loaded. Twenty component styles on each of the three tabs measure the same with and without it.

Regression: the phase-1 overview, library, empty library and bulletin page were rendered with the stylesheet from before this slice and with the current one, at 1440 and 390. 0 of 1,026 element/viewport combinations changed. Every new class name was checked against the board and app CSS for collisions; there are none.

## Not built, and why

These board elements have no functionality behind them in the plugin. None is faked.

| Board element | What the plugin does instead |
| --- | --- |
| "Remind all overdue (N)" | Omitted. There is no bulk send. |
| "Last contact · logged by…" | Omitted. The plugin does not read FluentCRM notes or any contact log. The Last reminder column shows the plugin's own messages. |
| "Contact employee: Phone and email on file" | Email only, the address on the WordPress account. No phone number is stored. |
| Row menu entries that cannot apply | "Log a reminder" without FluentCRM and "Open CRM contact" without a contact record are left out, not shown disabled. |
| "CPR card missing" chip on an overdue row | Omitted. There is no required-documents rule per role. |
| Course length and lessons ("5.5 hrs · 3 lessons") | Omitted. The course rows have no such data. |
| Courses "Export (CSV)" and per-row "Report" | Omitted. There is no course export and no per-course report. The existing export writes employees and is on the Employees tab. |
| "Pending issuance" tile | Omitted. In the plugin it is the same number as Missing (completed, certificate configured, no link), so showing both would count one thing twice. Missing carries it, with the overview's wording. The tile slot shows Expiring soon instead, from real data. |
| Missing as a "required record not on file" (CPR card) | Missing means a completed course whose certificate LearnDash has not produced. The alert says that. |
| "Download all (ZIP)", row checkboxes | Omitted. No archive download, no row selection. |
| Source column and filter, "Outside (approved)" | Omitted. This list is LearnDash certificates. Approved outside training lives in the External training tab and the employee drawer's Documents. |
| "This cycle" date filter | Omitted. The certificate rows do not carry a cycle. "Last 30 days" is offered. |
| Sub-tabs as ARIA tabs | Toggle buttons with `aria-pressed`. They filter one list, not switch panels. Styled as the board's selected tab. |
| Certificate sort by employee or course | Not offered. The board sorts this list by date only; a request for another sort is ignored. |
| Tiles as links | Plain tiles. The alert's "Show missing" and the toggles change the list. |

## Decisions

Settled in review:

1. **Courses default sort:** Lowest completion first, as on the board (`GHCA_ACD_Redesign_Lists::COURSE_SORT_DEFAULT`).
2. **Fourth certificate tile:** Expiring soon stays in place of the unsupported "Pending issuance" count. Its number is the rolling-lifespan state already on each course; no new calculation.
3. **Certificate sorting:** the date column only, as on the board. No new certificate sort control.

Still open, carried over from slices 2 and 3: phone tab height, column drop, the footer modals and the phone sheet.

## Changes after the first review (`e1370cb`)

| Change | Detail |
| --- | --- |
| Courses default | `course_sort()` returns `rate_asc` when the request has no valid sort. The Sort select lists Lowest completion first, Highest completion first, Course A–Z. `ghca_crs_sort` is still ignored by the legacy table. |
| Certificate sort | The Employee and Course sort headers were removed. `certificate_filters()` always sorts by completion date (newest first by default; the header toggles oldest first). |
| Phone tab bars | Found while verifying: on a phone, keyboard selection could leave the chosen tab partly off-screen in the drawer's tab bar and in the dashboard's main tab bar. The drawer focused the tab before the other tabs lost their bolder selected state, so the scroll was computed for a width that then changed. Now every tab gets its state first and focus comes after. The redesigned drawer and main tab bars also call `scrollIntoView({ inline: 'nearest' })`. The legacy tab bars end in the same state as before, and the scroll call is not applied to them. |
| Tests | Course, Overdue-only and combined filters; empty states and reset; the new defaults; phone tab scrolling and keyboard access in both tab bars. See [Tests](#tests). |

## Correction after staging review: bulletin pages inside the theme (`c422dc2`)

Taz's review of Slices 1–4 on Akanso staging (build `d93f640`) found that the ODP bulletin library (`/odp-bulletins/`) and each bulletin page (`/odp-bulletins/<id>/`) did not use the site's theme. They were sent as standalone documents with the plugin's own app bar (logo, navigation, avatar), so the theme header and footer were missing and the plugin header duplicated them.

| | Before | After |
| --- | --- | --- |
| Page around the content | A standalone HTML document from the plugin | The active theme's header and footer. Classic themes (BuddyBoss, Hello with Elementor Pro header/footer locations, and others) through `get_header()` / `get_footer()`; block themes through their `header` and `footer` template parts in core's canvas, rendered before `wp_head()` as core does. |
| Plugin app bar, logo, site nav, avatar, second skip link | Shown | Removed. The theme supplies them. |
| Bulletin content, drawer, breadcrumb back to the dashboard | — | Unchanged markup (`GHCA_ODP_UI::library()` / `single()` / `drawer()`). |
| Background | `#F3EFEA` page body | `#F3EFEA` from the board's page surface, full width between the theme's header and footer (the dashboard's own full-bleed wrapper). |
| Branding | Agency brand style on the app wrapper | Same. The theme header shows the site's own logo. |
| Routing, login redirect, audience (agency or employee), HTTP status, `Cache-Control: private, no-store`, `X-Robots-Tag: noindex` | — | Unchanged, decided before the page is printed. Also `noindex, nofollow` through `wp_robots`, a document title, and a `gh-odp-page` body class. |
| Assets | Printed by the document | `gh-redesign.css` and `gh-bulletins.js` enqueued under the dashboard's handles (`ghca-redesign`, `ghca-bulletins`). |
| Feed | — | Untouched. The staging feed is blocked by the outbound guard, as expected; the library shows its existing "unavailable" state inside the theme. Nothing in this change weakens the guard or adds a request. |

Inside a theme the page now meets theme CSS it never met before. Two leaks found by the hostile-theme test were fixed:

- **The `<main>` box.** It is pinned to the board values (28 / 32 / 56 px padding, 16 px sides on phones, centred, 1320 px max) with `!important`, against rules such as `main { padding: 40px !important }`.
- **The ODP headings.** The bulletin, card and library headings (`.gh-bp-h`, `.gh-bul-h`, `.gh-bl-h`, `.gh-odp-hd h1`) are added to the build's heading guard, so their font family and letter spacing hold. `text-transform: none` and `font-style: normal` are declared for them as well.

The theme's own header and footer are not restyled: their computed styles are identical with and without the plugin stylesheet. `GHCA_ODP_UI::document()` is no longer sent by the site; it is kept for the phase-1 synthetic previews.

Files: `includes/odp/class-odp-bulletins.php` (`send_document()`, new `prepare_theme_page()` and `themed_page()`), `includes/odp/class-odp-ui.php` (new `themed()`), `design-references/gh-redesign.tail.css`, `design-references/scope-board-css.py`, rebuilt `assets/gh-redesign.css`. No dashboard, Slice 2–4 or legacy file changed.

Verified (synthetic):
- `php tests/test-odp-themed.php` (28 checks): the fragment has no document, app bar, logo, nav or skip link; it keeps the wrapper, surface, brand style, main, breadcrumb and drawer. Classic theme: `get_header` then `get_footer`, exactly one header. Block theme: parts before `wp_head`, then `wp_body_open` and `wp_footer`, one document. Assets, title and robots. The source order of route match, owner, login redirect and audience, and the status and no-store headers, are unchanged.
- `node tests/browser-odp-themed.cjs` (34 checks), in Chromium at 1440 and 390, inside a stand-in classic theme with its own header, footer and 1100 px container:
  - only the theme header;
  - `#F3EFEA` at full viewport width, with no sideways scroll;
  - content between the header and footer, with one `<main>`;
  - Wix Madefor in the content while the theme keeps its own font;
  - the breadcrumb to the dashboard;
  - the theme header and footer unchanged by the plugin CSS;
  - the drawer opens over the page, pinned to the viewport, and closes with Escape;
  - library and bulletin components unchanged under hostile theme rules.
- Phase-1 regression (overview, library, empty library, bulletin; 1440 and 390): 0 of 1,026 element/viewport combinations changed against the `d93f640` stylesheet. Every other suite passes: 73 PHP scripts, JS, Playwright, and the list (51), table (53) and drawer (159) browser suites.

Not verified: the real Akanso theme, BuddyBoss or Elementor Pro header and footer, real roles, or the live bulletin feed. Staging must confirm the bulletin pages show the site header and footer once, with no plugin header.

## Remaining gaps after the staging review

| Item | Classification | Detail |
| --- | --- | --- |
| No certificate sort control visible on staging | **Not a plugin defect: by design**, with one display condition to check | The only certificate sort is the **Completed** column header: a header button with a small arrow, newest first by default, toggled by clicking. There is no separate Sort select, and none is added, as agreed. It is absent in three cases: below 720 px, where the list is cards (no table, no headers); when the current view has no rows (the empty state replaces the table); and in the Missing view when nothing is missing. If Taz saw a desktop table with rows and no clickable Completed header, that would be a defect; a screenshot would settle it. |
| Certificate PDF downloads unverified | **Unverified, not known to be broken.** Likely blocked on staging by the environment | The plugin side is tested synthetically: the button sends ids only to `ghca_acd_certificate_download`, the endpoint keeps its permission and scope checks, and only a real PDF is saved, otherwise an error toast. On the server the endpoint fetches the certificate with an **HTTP request from the site to itself**: `wp_safe_remote_post` to its own `admin-ajax.php` for the broker, or `wp_safe_remote_get` of the certificate link for LearnDash admins. If staging's outbound guard, HTTP authentication or firewall blocks self-requests, every download fails with "The certificate could not be downloaded. Nothing was saved." That would be the environment, not a new defect, and the guard must not be weakened for it. Read-only check first: Tools → Site Health → "loopback request" status, and the PHP log entry at the time of a failed download. A same-host allowance in the guard is the owner's decision, not part of this change. The drawer's certificate download (Slice 3) uses the same endpoint and is subject to the same condition. |
| Elementor editor stuck on "Loading" | **Unresolved; no evidence it is a GHCA defect** | No new evidence since the last package. The code review and the approval-gated plan in `docs/staging-elementor-loading-diagnostics.md` stand. The bulletin correction does not touch the editor. Its only effect near Elementor is that bulletin pages now render the theme's header and footer, which on an Elementor Pro site are Elementor's header and footer templates; that is the intended result. |
| Blocked bulletin feed on staging | **Expected** (outbound guard) | The library shows the "unavailable" state, now inside the theme. Not changed. |

Confirmed plugin defects fixed in this round: the bulletin pages' missing theme header and footer, and the duplicate plugin header. Two theme-CSS leaks were found and fixed at the same time (above). No other defect is confirmed.

## Known gaps

- Remind opening the reminder modal is covered by the slice-3 suite, which binds `[data-ghca-reminder]` document-wide. The slice-4 preview does not load the modal; its browser test checks the hook and the permission gate.
- The overdue table lets its row menu hang outside the table, as the board does. On a narrow tablet the table cannot scroll sideways inside its box; the menu stays reachable, and the page scrolls instead.
- On phones the pager's chevrons render at 44 px. This is slice-2 behaviour from the canvas app layer, not changed here.
- Loading is a dim and `aria-busy`, not skeleton rows.
- Not verified here: LearnDash, BuddyBoss, Elementor and FluentCRM are not installed. The stand-in WordPress runs the plugin's own classes on synthetic data. Staging has not been touched.
- The plugin header still reads `1.7.3-rc.8`, the same as the Slice 3 build on staging (the phase-2 plan keeps the header until release). WordPress's upload screen will show the same version on both sides. `BUILD.txt` and the ZIP name carry the commit that tells them apart. Asset URLs carry the file time, so browsers fetch the new CSS and JS.
- **Staging Elementor editor stuck on "Loading": open, cause unknown.** The code review found no GHCA path that blocks the editor. Two candidates to test are the dashboard shortcode's render inside the preview (dashboard pages only) and `jotform-ownership.js`, which loads on every front-end page for logged-in users when Jotform is enabled. Controlled, approval-gated diagnostics are in `docs/staging-elementor-loading-diagnostics.md`. Staging is not cleared while this and the other staging checks below are outstanding.

## Tests

| Test | What it covers |
| --- | --- |
| `php tests/test-redesign-lists.php` (160 checks) | Real provider, renderers, AJAX handler and messaging query on synthetic records: legacy goldens in 15 states, rows and order against the legacy rows (Course A–Z) and the same rows under the default order, counts against the provider, sections, day counts, escaping, Sort, filters, chips, empty states, the Last reminder gates, FluentCRM gating, the certificate views, filters and sort, download URLs, isolation, nonce and capability order, and the redesign switch. |
| `node tests/browser-redesign-lists.cjs [dir]` (51 checks) | Chromium. Real PHP for the page and every refresh; canned certificate endpoint. Fonts (Wix Madefor only), row order, the risk edge, the row menu (not clipped, Escape, focus return), Remind hooks and permission, Sort through the hidden fields, search debounce, **Show: Overdue only alone and with a group**, **three-filter empty state and Clear filters (all filters reset, focus to search)**, Courses default (Lowest completion first) and Course A–Z, **group, certificate and search filters alone and combined**, chip removal, **Courses empty state and reset**, per page, Certificates "Show missing", toggles, the date-only sort, **course + date + search combined, empty state and reset**, PDF download (success and refused), phone cards with no sideways scroll, **the main tab bar on a phone (scrolls, End/ArrowLeft keep the tab in view)**, and the hostile theme. |
| `php tests/test-redesign-tables.php` (120 checks) | Slice 2. Its assertion that Courses stays legacy now expects board parts. |
| `node tests/browser-redesign-tables.cjs` (53) | Slice 2, passing. Covers the Employees tab's "Overdue only" checkbox (mouse and keyboard) and Clear all. |
| `node tests/browser-redesign-drawer.cjs` (159) | Slice 3, plus seven new phone checks: the drawer tab bar scrolls sideways (4 tabs, 548 of 374 px), End, Home and ArrowRight keep the selected tab fully in view, a focus indicator shows, swipe/wheel scrolls it, and the page never scrolls sideways. |
| Every other `tests/test-*.php` (72 scripts in all), the JS tests and the Playwright `.cjs` tests | All pass. PHP lint and `node --check` pass. |

`php tests/render-lists-preview.php page <dir>` writes the synthetic page for screenshots. `GHCA_PREVIEW_PERMS` sets the permissions (`remind,docs,comms,admin`, or `none`).

## Synthetic tests and staging verification

| | Done | Not done |
| --- | --- | --- |
| Synthetic (this repository) | Everything under [Tests](#tests): the plugin's own PHP on synthetic records in a stand-in WordPress, and Chromium on a synthetic page with the plugin's real AJAX handler and canned certificate responses. Fidelity measured against the boards' own markup. Package integrity, see [Package](#package). | Real WordPress, LearnDash, BuddyBoss, Elementor, FluentCRM, Jotform; real roles and group scope; real certificates. |
| Staging | Nothing. Nothing was installed, deployed, sent or saved. The email/outbound guard, reminders, employee records and training data were not touched. | Installing the package, every check below, and the Elementor investigation. |

Suggested staging checks for Taz, after the package is approved for staging (fictional employees only; no sends):

1. Install over the existing plugin (see [Install and rollback](#install-and-rollback)). Confirm one GHCA entry on the Plugins screen, active, at `Gridhouse-admin-compliance-dashboard-plugin-main/gridhouse-admin-compliance-dashboard.php`.
2. Redesign on: Overdue, Courses and Certificates match the boards at desktop and phone width. Courses opens with Lowest completion first. Certificates sorts only by date. Fonts are Wix Madefor.
3. Filters on each tab, alone and combined. Empty states and Clear filters. The Employees tab's Overdue only checkbox.
4. Phone: the drawer's tab bar and the main tab bar scroll sideways; arrow keys, Home and End keep the selected tab in view.
5. Permissions: a group leader sees only their groups; a viewer without the employee-documents permission gets no PDF button; without the send-reminders permission there is no Remind.
6. PDF download of a real certificate through the broker, as a non-LearnDash-admin dashboard role.
7. Redesign off: the legacy Overdue, Courses and Certificates tabs are as before.
8. Elementor editor: follow `docs/staging-elementor-loading-diagnostics.md`; do not disable plugins or change settings without approval.
9. Bulletin pages (correction build): `/odp-bulletins/` and one bulletin open with the site's own header and footer, once, and no plugin app bar. The background between them is `#F3EFEA`. Check the breadcrumb back to the dashboard, the drawer from a library card, a phone width, and that signed-out visitors are sent to log in. With the feed blocked, the library shows the "unavailable" state; that is expected.
10. Certificate sort: on a desktop width with rows in the list, the Completed header is clickable and toggles oldest/newest. Before judging PDF downloads, check Site Health's loopback status (see Remaining gaps).

## Package

### Correction build (current)

| | |
| --- | --- |
| File | `Gridhouse-admin-compliance-dashboard-1.7.3-rc.8-0f5d4e0.zip` |
| Size | 648,815 bytes |
| SHA-256 | `947fc47aba628e2984f0252ed42507f2bde0f93873b7c802e41766769396ffa9` |
| Built from | `0f5d4e043c50aa4dfc9669f9d21ca5e50c5038f2` on `gridhouse-overview-odp-bulletins-af45` (PR 19), plugin version `1.7.3-rc.8` |
| Contents | Cumulative Slices 1–4 plus the bulletin-page correction. 161 entries: 139 runtime files, `BUILD.txt`, 21 folder entries, under the same root folder `Gridhouse-admin-compliance-dashboard-plugin-main/` and entry file `gridhouse-admin-compliance-dashboard.php`. |
| Difference from the installed `d93f640` build | Three runtime files: `assets/gh-redesign.css`, `includes/odp/class-odp-bulletins.php`, `includes/odp/class-odp-ui.php` (plus `BUILD.txt`). Checked by comparing every extracted file's SHA-256. |
| Earlier build | `…-d93f640.zip` kept unchanged (SHA-256 `556295da…5e115609`, re-checked). |

The archive was verified the same way as before. `unzip -t` reports no errors. There is one root folder, with the entry file at its expected path. The 139 files are byte-identical to the commit, and the `BUILD.txt` manifest verifies. Fonts are present, and `php -l` and `node --check` pass. All 73 PHP scripts, the JS and Playwright tests, and the browser suites pass against the extracted package (lists 51, tables 53, drawer 159, themed bulletin pages 34). Building again with the same name was refused. Commits after `0f5d4e0` change only `docs/`.

### First Slices 1–4 build (installed on staging)

| | |
| --- | --- |
| File | `Gridhouse-admin-compliance-dashboard-1.7.3-rc.8-d93f640.zip` |
| Size | 647,355 bytes |
| SHA-256 | `556295da6a07f0e983d8861949216918ddaba11fb753bbf9da8edd505e115609` |
| Built from | `d93f6404c85190d77419da2ea839d3099eab8538` on `gridhouse-overview-odp-bulletins-af45` (PR 19), plugin version `1.7.3-rc.8` |
| Contents | Cumulative Slices 1–4. 161 entries: 139 runtime files, `BUILD.txt`, 21 folder entries, under one root folder `Gridhouse-admin-compliance-dashboard-plugin-main/`. |
| Included | `gridhouse-admin-compliance-dashboard.php`, `includes/`, `assets/` (with the self-hosted Wix Madefor fonts and `OFL.txt`), `README.md`, `TESTING.md`, `BUILD.txt` |
| Excluded | `tests/`, `docs/`, `design-references/`, dotfiles. As in earlier packages, they stay in the repository. |
| Built by | `tools/build-package.py` (added in the commit after the build). It refuses to overwrite an existing file. A rebuild gives the same 139 runtime files; the ZIP checksum differs because `BUILD.txt` records the build time. |

The tested runtime commit `e1370cb` and the build commit `d93f640` have no runtime difference: the commit between them changed only `docs/`. Later commits on the branch change only `docs/` and `tools/`; `BUILD.txt` names the build commit.

Verification of the archive (synthetic, in this environment):

1. `unzip -t`: no errors.
2. One root folder, and the main file at `Gridhouse-admin-compliance-dashboard-plugin-main/gridhouse-admin-compliance-dashboard.php`.
3. The file list equals `git ls-tree` of the commit for the included paths (139 files), and every file is byte-identical to its git blob.
4. The SHA-256 manifest inside `BUILD.txt` verifies against the extracted files.
5. The four `woff2` fonts that `gh-redesign.css` references are present. All 71 `require` targets in the packaged PHP resolve inside the package. `php -l` passes on every PHP file and `node --check` on every script.
6. Running the repository's tests against the extracted package (tests copied in next to it):
   - all 72 PHP scripts and the JS tests pass;
   - the Playwright tests pass: lists 51, tables 53, drawer 159, plus the settings and manage-users tests.
7. Building again with the same name was refused.

## Install and rollback

**Root folder.** The ZIP's single top-level folder is `Gridhouse-admin-compliance-dashboard-plugin-main/`, the folder staging already uses (`wp-content/plugins/Gridhouse-admin-compliance-dashboard-plugin-main/gridhouse-admin-compliance-dashboard.php`). Uploading it through Plugins → Add New → Upload Plugin makes WordPress offer **Replace current with uploaded**, which updates the existing plugin in place. A ZIP with a different root folder would install a second copy beside it; do not rename the folder.

**What the update changes, compared with the Slice 3 build on staging (`a890189`).** Files only. The runtime diff adds no database table, migration, option, scheduled event, mail call or write of any kind; it was checked with a search of the diff. `ghca_crs_sort` and `ghca_cert_*` are request parameters, and the new messaging query only reads. Nothing is sent or written on update or activation. The package is cumulative (Slices 1–4), so a site on an older build would also receive the Slice 1–3 settings those slices introduced; staging already has them.

**Rollback, fastest first:**

1. **Switch the redesign off** (GHCA Settings → the redesign setting, `ghca_acd_ui_redesign`). The legacy dashboard returns at once, with no file change. The legacy screens are byte-identical to before (golden checks). This is a setting change, so it needs the owner's approval on staging.
2. **Reinstall the previous build** the same way, Replace current with uploaded. For the correction build, that is the Slice 1–4 package staging runs now, `Gridhouse-admin-compliance-dashboard-1.7.3-rc.8-d93f640.zip`; before that, the Slice 3 package (`a890189`). Only the bulletin pages differ between `d93f640` and the correction build. Keep that package and the existing private rollback copies; this package does not replace them.
3. After either step, clear any page cache or CDN for the dashboard pages. Asset URLs carry the file time, so browsers need no action.

No data rollback is needed: Slice 4 writes nothing.
