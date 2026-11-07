# Dashboard redesign, phase 2, slice 2: table kit, Employees and Inactive

Opt-in, behind `ghca_acd_ui_redesign`. Unchecking it restores the previous tabs. Training math, audit records, capabilities, group scope and integrations are unchanged: rows, filters, sorting, paging and permissions come from the same `GHCA_ACD_Data_Provider`, `GHCA_ACD_Table_UI` and `GHCA_ACD_Roles` calls the legacy tabs use.

Boards: `Admin-Employees`, `Admin-Inactive`, `M-Admin-Employees`, `T-Admin-Employees`, and the shared components in `ControlsSet` (filter chips, toast) and `DataSet` (table, pager, empty state).

## What shipped

| Area | Change |
| --- | --- |
| Employees tab | Board toolbar, filter card, sortable table, pager. `GHCA_ACD_Redesign_Tables::render_employees()`. |
| Inactive tab | Same kit, gated on `user_can_manage_users()` exactly as before. |
| Filter bar | Search (300 ms debounce), Group, Course, Status, Overdue only. Same field names as the legacy form, so URLs and saved state are unchanged. |
| Filter chips | One chip per active filter with a remove button, "Clear all" from two filters up, and a "Showing x–y of z" line. A polite live region announces each result. |
| Table | Employee, Group, Progress (bar plus `done / total`), Next due, Certificate, Last activity, Status, View. Sort keys are the five the provider already supports. Overdue rows get the board's risk edge. |
| Pager | First, last and two pages either side, previous and next, per-page select. |
| Empty states | Filtered-empty with Clear filters; all-clear with nothing to clear. |
| Phone | Below 720 px the table becomes `EmployeeCard`s (`M-Admin-Employees`). Tablet keeps the table and scrolls it inside its box. |
| Toast | Board `.gh-toast` in a stack inside the themed wrapper. Success clears after 6 s, an error stays until dismissed, three at most. Used by every existing `ghcaToast` caller on the redesigned dashboard, and by the server notices (sync finished, reminder logged). |
| Build | `design-references/app-source.css` (canvas `gh-app.css`, sha256 `e4a09783…`) is folded into `assets/gh-redesign.css`. Prototype-only rules are dropped by `scope-board-css.py --app`. |

### Hooks that did not change

`data-ghca-filter-form`, `data-ghca-table`, `data-ghca-table-id`, `data-ghca-page-input`, `data-ghca-page`, `data-ghca-page-field`, `data-ghca-sort`, `data-ghca-sort-order`, `data-ghca-employee-drawer`, the field names `ghca_emp_*` and `ghca_inactive_*`, and the `ghca_acd_filter_table` action with its `ghca_acd_table` nonce. `assets/dashboard.js` drives both versions.

### How the refresh chooses its markup

The form carries `data-ghca-ui="board"` and the script sends `ghca_ui=board`. `GHCA_ACD_Redesign_Tables::board_requested()` honours it only while the redesign setting is on. Nonce, dashboard access and (for Inactive) manage-users are all checked first, so the value picks markup and never access. The legacy shortcode, the Priority and Courses tables, and any request without the value keep their existing markup.

## Fidelity

Method: the board's own markup and unscoped board CSS rendered in Chromium at the same viewport, against the plugin's render, compared per component on font family, size, weight, line height, letter spacing, transform, colour, background, borders, radius, padding, gap and height. The renderer's actual font was read with `CSS.getPlatformFontsForNode`: Wix Madefor from the plugin on every element checked, no system fallback.

| Screen | Components compared | Identical |
| --- | --- | --- |
| Employees, 1440 | 27 | 26. The one difference is the table wrapper height: the board has four rows, the plugin ten, and both cap at the board's 560 px. |
| Employee cards, 390 | 11 | 11 |

Board cascade details followed rather than the declared rule, as in phase 1: the Export control is a `<button>` on the board (so 400 weight at 14/20), View is an `<a>` (12 px, 600), sort buttons take the browser's letter spacing, and the card's `<dl>` carries the browser's 13 px margin.

Isolation was run against a stand-in theme that forces `!important` link colour, underline and hover fill, heading family and case, table cell borders and button hover fill, plus ordinary input, label and table rules. Eight controls and text styles measure the same as on a clean page.

Regression: phase-1 overview, library, empty library and bulletin page were rendered with the previous and the new stylesheet at 1440 and 390 and every element's computed style compared. 1,024 of 1,026 element, page and viewport combinations are unchanged. The two that changed are the new, empty toast stack.

### Build changes that reach phase-1 screens

Found by the tests above and fixed in `scope-board-css.py`. All add `!important` or an explicit value; none change a measured result.

- The `!important` guard now covers `.gh-table` (colour, background and the border family) and `.gh-pager-btns`.
- Heading classes `.gh-h1`–`.gh-h4` guard `font-family`, `letter-spacing`, `text-transform` and `font-style` only. Size and weight stay unguarded so a component can still size its own heading.
- The pager guard also fixes a latent ordering bug: a rule listing both `button` and `a` was flagged `!important`, so the unflagged `[aria-current="page"]` override lost to it.
- The reset stripped native checkbox painting (`appearance: none`, no border). `.gh-check` inputs get `appearance: auto`, which the board's `accent-color` assumes.

## Not built, and why

These board elements have no functionality behind them in the plugin. None is faked.

| Board element | What the plugin does instead |
| --- | --- |
| Bulk bar, row checkboxes, "Send reminder to N", "Export selected" | Omitted. There is no row selection or bulk messaging. Needs its own approval and a messaging-safety review. |
| Per-row send icon | Omitted. Reminders start from the employee drawer (slice 3). |
| "Export view (CSV)" | Labelled "Export all (CSV)". The export handler writes every active employee in scope and does not read the filters. A tooltip says so. Not offered on Inactive, because the export covers active employees only. |
| "Sorted by urgency" subtitle | Omitted. The plugin applies no default urgency sort; the subtitle says to select a heading to sort. |
| Job title under the name | The email, as the legacy table shows. The plugin has no title field in these rows. |
| Certificates count | The existing certificate label. The phone card omits it, following the board; the table and the drawer carry it. |
| Inactive: "Inactive since", Reason, Progress and Compliance at deactivation, Packets, Reactivate | Omitted. No suspension date, reason or deactivation snapshot is stored, and there is no reactivate action here. The tab shows the existing columns and the existing "Suspended" mark. |
| "Not counted…, records stay read-only until reactivated" | Only the first half, which the legacy note already states. Read-only behaviour is not claimed. |
| Status wording | The plugin's own, including "All assigned courses complete", which the provider words that way so it is not read as orientation completion. It is longer than the board's "Completed". |

Derived, not stored: the line under the due date ("13 days late", "in 9 days") is the whole-day difference between today and the due date already shown, both in the site time zone. It never says "in N days" for an overdue status, and is omitted for complete employees. The board's "· 3 courses" is not shown.

## Decisions to confirm

1. **Phone tab height.** `gh-app.css` raises tabs to 44 px on phones; the accepted phone overview board is 42 px. Kept 42 to leave phase 1 unchanged. The handoff's "44 px on phones" checklist would argue for 44. One line in `gh-redesign.tail.css`.
2. **Column drop.** `gh-app.css` ships rules that hide Certificate and Last activity below 1359 and 1179 px. Not applied: the tablet board says the table scrolls sideways, and hiding columns would remove information the legacy table shows on a 1280 px laptop.

## Known gaps

- Loading is a dim and `aria-busy`, not the board's skeleton rows.
- The employee drawer, reminder, edit-records and certificate modals are still the legacy UI (slice 3 and later).
- The legacy empty-state "Clear filters" button inside the table mount has no click handler in `dashboard.js` (the listener is on the filter form; the button sits in the table mount, outside it), so by reading the code it does nothing. Existing behaviour, left alone and not exercised in a browser; the board markup has its own working handler.
- Toast behaviour changes for every `ghcaToast` caller on the redesigned dashboard: success clears after 6 s (was 3.2 s), errors stay until dismissed (were 5 s).

## Tests

| Test | What it covers |
| --- | --- |
| `php tests/test-redesign-tables.php` (120 checks) | Real provider, pager and handler on synthetic rows in a stand-in WordPress (`tests/fixtures/redesign-tables-env.php`): hooks, filters, chips, sorting, paging, empty states, escaping, status tones, due notes, permissions, nonce and capability order, legacy markup unchanged, the redesign switch. |
| `node tests/browser-redesign-tables.cjs [dir]` (53 checks) | Chromium and Playwright. Real PHP for the page and every refresh. Fonts, filtering, debounce, chips, sort and focus return, paging, toasts, hostile theme, 1440, 834 and 390, keyboard order and focus rings, drawer open and close. Not part of the default run. |
| `php tests/test-odp-bulletins.php` | Still 103 checks, including that every top-level rule in `assets/gh-redesign.css` is scoped. |
| Every other `tests/test-*.php` and JS test | All 68 PHP scripts and the JS tests pass. PHP and `node --check` pass. |

`php tests/render-tables-preview.php page <dir>` writes the synthetic page for screenshots.

Not verified: LearnDash, BuddyBoss and Elementor are not installed here, so isolation is by selector weight and the stand-in theme, not a render with them. The stand-in WordPress runs the plugin's own provider and renderer but not real LearnDash data. Staging was not accessed.
