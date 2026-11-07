# Dashboard redesign, phase 2, slice 4: Overdue, Courses, Certificates

Opt-in, behind `ghca_acd_ui_redesign`. Unchecking it restores the previous tabs. Training math, certificate counts, group scope, capabilities, messaging and certificate downloads are unchanged. Rows, filters and paging come from the same `GHCA_ACD_Data_Provider` and `GHCA_ACD_Table_UI` calls the legacy tabs use. The legacy renderers in `class-shortcodes.php` were not edited.

Boards: `Admin-Overdue`, `Admin-Courses`, `Admin-Certificates`, from the design canvas "Gridhouse Compliance — Phase 2 Dashboards", version `1793995837-628a`. Commits, oldest first: `3ebfd91` (legacy goldens, taken before any change), `87d0cb6` (implementation and tests), then this document with the browser suite and fidelity fixes.

## What shipped

| Area | Change |
| --- | --- |
| Overdue | Board toolbar with a headline ("3 employees overdue on 6 courses · 2 more due within 14 days"), filter card (search, group, Show, Sort), an Overdue table and a "Due within N days" table, with a pager below them. `GHCA_ACD_Redesign_Lists::render_overdue()`. |
| Overdue rows | Courses still owed (up to three, then "+ N more"; an expired course is marked "(expired)"), days late with the due date, status, View, Remind and a row menu. |
| Due-soon rows | Courses not complete with the next course, days left, progress, status, View and Remind. |
| Last reminder | The date and channels of the latest message, plus the state of its first delivery. Shown only to people with `user_can_view_communication_history()`, and only when the messaging schema is current. One read-only query per page: `GHCA_ACD_Messaging_Repository::latest_for_employees()`. |
| Courses | Board toolbar, filter card (search, group, certificate, Sort), and a table with course, group badge, required, done, in progress, not started, completion bar and certificate flag. `render_courses()`. |
| Certificates | Four tiles (Issued, Expiring soon, Missing, Completed in the last 30 days), a missing-certificate alert above the list, and a list with Issued / Expiring / Missing toggles, search, course and date filters, and sortable Completed, Employee and Course headers. PDF download and Recent completions are also on the tab. `render_certificates()`. |
| Phone | Below 720 px each table becomes cards, with full-size buttons (44 px touch targets). |

### Where the numbers come from

- Overdue and due soon are the provider's `priority` classification from `get_priority_rows()`. The rows and their order are the legacy rows; each section keeps the provider's order.
- The window in "Due within N days" is `GHCA_ACD_Settings::get_at_risk_days()`.
- Days late and days left are whole days between today and the due date already shown, both read in the site time zone.
- If an employee is overdue because a completed course expired, but the cycle due date is still ahead, days late shows "—" and does not count down.
- Course counts are `get_course_overview_rows()`. The headline's tracked-course figure is `count_unique_tracked_courses()`, the same number as the Courses tab badge (acceptance gate for this slice).
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
| Courses default sort "Lowest completion first" | Offered; the default stays Course A–Z, the legacy order, so the same inputs give the same rows as before. |
| Tiles as links | Plain tiles. The alert's "Show missing" and the toggles change the list. |

## Decisions still to confirm

1. **Courses default sort.** The board selects "Lowest completion first"; the plugin keeps the legacy A–Z default and offers the board's order in Sort. One line in `course_sort()` to change.
2. **Fourth certificate tile.** Expiring soon replaces Pending issuance (see above). The alternative is three tiles.
3. **Carried over:** phone tab height, column drop, the footer modals and the phone sheet, from slices 2 and 3.

## Known gaps

- Remind opening the reminder modal is covered by the slice-3 suite, which binds `[data-ghca-reminder]` document-wide. The slice-4 preview does not load the modal; its browser test checks the hook and the permission gate.
- The overdue table lets its row menu hang outside the table, as the board does. On a narrow tablet the table cannot scroll sideways inside its box; the menu stays reachable, and the page scrolls instead.
- On phones the pager's chevrons render at 44 px. This is slice-2 behaviour from the canvas app layer, not changed here.
- Loading is a dim and `aria-busy`, not skeleton rows.
- Not verified here: LearnDash, BuddyBoss, Elementor and FluentCRM are not installed. The stand-in WordPress runs the plugin's own classes on synthetic data. Staging has not been touched.

## Tests

| Test | What it covers |
| --- | --- |
| `php tests/test-redesign-lists.php` (154 checks) | Real provider, renderers, AJAX handler and messaging query on synthetic records: legacy goldens in 15 states, rows and order against the legacy rows, counts against the provider, sections, day counts, escaping, Sort, filters, chips, empty states, the Last reminder gates, FluentCRM gating, the certificate views, filters and sort, download URLs, isolation, nonce and capability order, and the redesign switch. |
| `node tests/browser-redesign-lists.cjs [dir]` (34 checks) | Chromium. Real PHP for the page and every refresh; canned certificate endpoint. Fonts, row order, the risk edge, the row menu (not clipped, Escape, focus return), Remind hooks and permission, Sort through the hidden fields, filters and debounce, Clear all, Courses sort, filter and per-page, Certificates "Show missing", toggles, sort, PDF download (success and refused), the date filter, phone cards with no sideways scroll, and the hostile theme. |
| `php tests/test-redesign-tables.php` (120 checks) | Slice 2. Its assertion that Courses stays legacy now expects board parts. |
| `node tests/browser-redesign-tables.cjs` (53), `node tests/browser-redesign-drawer.cjs` (152) | Slices 2 and 3, unchanged and passing. |
| Every other `tests/test-*.php` (72 scripts in all) and JS test | All pass. PHP lint and `node --check` pass. |

`php tests/render-lists-preview.php page <dir>` writes the synthetic page for screenshots. `GHCA_PREVIEW_PERMS` sets the permissions (`remind,docs,comms,admin`, or `none`).
