# Dashboard redesign, phase 2, slice 5: External training, Audit data, Reports, Courses certificate column

Opt-in, behind `ghca_acd_ui_redesign`. With the redesign off the dashboard still calls the legacy renderers (`GHCA_ACD_Jotform_UI::render_frontend_review()`, `GHCA_ACD_Audit_UI::render()`, `GHCA_ACD_Shortcodes::render_reports()`), which were not edited: the diff to `class-jotform-ui.php` only adds methods, and `class-audit-ui.php` and `class-shortcodes.php` are unchanged. No handler, capability, nonce, validation rule, training calculation, compliance rule, export or stored record changed. The employee dashboard, the outbound guard and bulletin publishing were not touched.

Boards: `Admin-ExternalReview`, `Admin-AuditData`, `Admin-Reports` and `Admin-Courses`, from the design canvas "Gridhouse Compliance — Phase 2 Dashboards", version `1793995837-628a` (the three new boards appeared in this version; the local board copy was refreshed before this slice).

## Mapping: board versus what the plugin can do today

| Area | On the board | Existing behaviour | This slice |
| --- | --- | --- | --- |
| External training | Pending / Approved / Rejected subtabs; one card per submission (file column, "Counts toward", status, Employee, Completed on, Provider, Submitted, warning); Reject… opens a required reason; Approve; "Recently approved" table | All pending / Unsigned only filter, pager (25), per row: catalog lesson select (required), employee ID (required), completion date (required), override reason (required on a mismatch), orientation checkbox, "Employee confirmed" (required), PDF preflight, Approve, Reject (no reason); approved table with orientation and revoke forms | Built: card layout, filters as subtabs, every existing field and decision in board controls, approved table. Not built: Rejected subtab and reject reason (see Decisions). |
| Audit data | Orientation / Annual subtabs, four verdict tiles, 30-day verdict and five topic columns per employee, an "Editing dates" card for the verified start | Two CSV exports; per employee: verified start (read-only), first-service and worked-alone dates (MM/DD/YYYY text), exclude checkbox, packet buttons, Save (AJAX) | Built: toolbar with both exports, the verdict explanation, a board table with the existing fields and Save. Not built: subtabs, tiles, verdict and topic columns, the edit card (see Decisions). |
| Reports | Four destination cards; a "Course report" with group filter, search, Excel and CSV, Enrolled / Not started / In progress / Completed / Avg quiz / Avg time / Completion | Four destination cards (Compliance export, User training report, Certificate records, Manage users) | Built: the four cards in the board layout with the same URLs. Not built: Course report (a new report type; see Decisions). |
| Courses | Certificate column | Column read "Issues certificate" / "No certificate" per row | Column header "Issues certificate", cells "Yes" or "No" from the course's LearnDash certificate setting (`_ld_certificate`), not from whether anyone earned one. Phone cards use the same label. |
| Calendar colour | — | Native date fields | See [Date pickers](#date-pickers). |

## What shipped

### External training (`GHCA_ACD_Jotform_UI::render_review_board()`)

- Toolbar: title, the existing explanation in plain words, and the All pending / Unsigned only filter as board subtabs (links with `aria-current`, the count on the current one).
- One `gh-card` per pending submission:
  - Evidence column (board 180 px, sunken): the indexed files with Preview and Download (the existing `data-ghca-document-open` broker hooks), or the manual-entry manifest (name, size, start of the SHA-256), or the existing "re-sync / no certificate" messages.
  - Body: "Submitted training" eyebrow and title, "Needs review" chip, a `gh-dl` with Employee, Completed on, Where it came from, Submitted, the manual-entry reason, and the warning as a `gh-alert--warning`.
  - Decision form: same fields, names, `required` flags, nonce (`ghca_acd_jotform_admin`), `frontend_review` / `return_url`, and decisions (`preflight`, `reject`, `approve`) as the legacy form; Approve is disabled when the evidence store is not ready, as before. `jotform.js` still drives the mismatch warning and the override-reason requirement.
- "Approved and counting" table: employee, counts toward, completed, approved, status (plus "Also orientation"), and a Change disclosure holding the existing orientation and revoke forms with their required reasons.
- States: success / failure flash after a decision (alert), evidence store not ready (danger alert), nothing pending (empty card), no approved evidence, no permission (tab not offered, nothing rendered).

### Audit data (`GHCA_ACD_Redesign_Admin::render_audit()`)

- Toolbar: "ODP audit data", ODP Annual CSV (secondary) and ODP Orientation CSV (primary), the legacy URLs and nonce.
- Neutral alert explaining how the CSV's existing 30-day verdict is worked out (text matches `GHCA_ACD_Audit_Calculator`).
- Table: employee (avatar, name, role), verified start (or a "Not verified" chip), first-service and worked-alone date inputs, "Leave out of CSVs", Orientation / Annual packet buttons, Save. Same employees in the same order as the legacy tab.
- Save posts `ghca_acd_save_audit_dates` with the same nonce name and fields. New, client-side only: a value that is not a real MM/DD/YYYY date is refused before sending, marked `aria-invalid`, and focused, because the server would silently store it as empty. The row reports Saved, the server's refusal text, or a network error; the button shows "Saving…" and is disabled while the request runs.
- Permissions: without edit permission the values are shown read-only with an explanation and no Save (the handler would refuse anyway); without document permission there are no packet buttons (as before); without view permission a denied state.
- Phone: each row becomes a labelled card; controls are 44 px.

### Reports (`GHCA_ACD_Redesign_Admin::render_reports()`)

Four cards in the board layout (40 px icon tile in the agency tint, title, description, a meta line, button). URLs and order are the legacy ones. Meta lines are counts the dashboard already shows: employees in view; certificates issued and missing; active and inactive (inactive only for people who can manage users). Nothing new is computed. The board's "Last run" and "files expire after 1 hour" text was not used: the plugin records neither.

### Stylesheet

New adapter rules at the end of `design-references/gh-redesign.tail.css` ("Slice 5"), all scoped to `:is(.ghca-ui, #ghca-ui-scope)`, tokens only. Also a phone rule giving the pager's per-page select the 44 px touch size (it was 36 px on phones on every table tab; the tablet rule already had it).

## Date pickers

- External training completion date: native `input type="date"` (unchanged format, ISO `yyyy-mm-dd`, `required`). It now sits inside the redesign, so the scoped `accent-color` rule from `cf2ae60` applies (agency brand). As recorded in `docs/compliance-health-and-design-audit.md`, Chromium 153 ignores `accent-color` in its popup and in the highlighted segment, so the popup stays system blue there; Firefox and Safari were not tested.
- Audit data dates: plain text fields in MM/DD/YYYY, the stored format. No native picker, no popup to colour.
- Edit Records and packet dates: unchanged since `cf2ae60`.
- Consistent brand colour inside the popup needs a custom picker. **Proposal, not built:** a small accessible calendar (button next to the field, dialog grid with arrow-key navigation, Escape to close) that writes the same ISO or MM/DD/YYYY value into the existing field, so validation, form posts and stored values do not change; the native field stays as the keyboard path. Needs approval first.

## Decisions needed (not guessed)

1. **External training, Rejected subtab.** The repository has no query for rejected rows. Showing them needs a new read-only query. Approve the list?
2. **External training, reject reason.** The board makes a reason required and says it is "sent to the employee and kept in their record". Today reject takes no reason and sends nothing. Adding it changes the handler, storage and possibly outbound messaging. Out of scope without a decision.
3. **Audit data, verdict, tiles, topic columns and subtabs.** The values exist in `GHCA_ACD_Audit_Calculator::calculate_employee_audit_data()` (used by the CSVs), but (a) running it for every employee on page load is a performance question for large agencies, (b) the board's four states (Within 30 days, Late, In window, Needs review) do not map one-to-one to the calculator's Yes / No / Missing evidence ("In window" is not computed), and (c) the board's topic labels (Done, In progress, Review, Missing, N/A) need a mapping from the calculator's category statuses. Needs a mapping and a performance decision.
4. **Audit data, "Editing dates" card.** The board edits the verified start (with source and confirmation) here. That is done today in the drawer's Edit Records. Move or duplicate it?
5. **Reports, Course report.** Avg quiz and Avg time, Tin Can and xAPI tabs and an Excel export are new report types and metrics. Not built, per the brief. Also: should "View certificates" open the dashboard's Certificates tab (board) instead of the cert-download page (current)?
6. **Courses, board extras.** The board has an "Export (CSV)" toolbar button and a per-row "Report" link. There is no course CSV or per-course report today.
7. **Custom date picker** (above).

## Tests

All synthetic: a stand-in WordPress with synthetic people, submissions and catalog (`tests/fixtures/redesign-admin-env.php`), the plugin's own renderers, and Chromium 153. Nothing was run on WordPress, staging or live, or against real LearnDash, Jotform, BuddyBoss or Elementor.

- `php tests/test-redesign-admin.php` (73 checks): every review form posts the same field names, decisions, handler and nonce as the legacy form; required flags, ISO date value, override hooks, document hooks; escaping; warnings; manual entries; filters; not-ready, empty, flash and no-permission states; audit rows equal the legacy rows, export URLs, nonce, stored values, read-only and no-document states; report URLs equal the legacy URLs, no invented metrics; every slice-5 CSS rule scoped and token-only.
- `php tests/test-redesign-lists.php` (167): updated for the Yes / No certificate column and its header and card label.
- `node tests/browser-redesign-admin.cjs` (20): the four tabs at 1440, 1023, 834, 720 and 390 px and under a hostile theme (no sideways scroll, Wix Madefor, 44 px controls below 1024 px); audit keyboard path, invalid and impossible dates refused before sending, exact fields posted, Saved / refused / network failure; review mismatch warning and override requirement, required confirmation, ISO completion date with the agency accent, Change panel by keyboard, Approve in the agency colour; not-ready, empty and no-permission states; report cards; Yes / No column.
- Full regression run: all 76 PHP test files and all 8 browser suites pass (corrections 21, ODP pages 24, themed ODP 34, page background 18, admin tabs 20, drawer 159, lists 51, tables 53).
- Fidelity: `tools/design-audit/audit.cjs` now includes the three new boards. 384 component pairs, 364 identical. External training 21 of 22 (the one left is screen-reader-only text), Audit data 13 of 13, Reports 6 of 6. The remaining differences elsewhere are the ones already explained in `docs/compliance-health-and-design-audit.md`. Only the Gridhouse theme was measured; only components present on both sides are compared, so the board parts not built (Decisions) are not in these numbers.

## Not verified

- Anything on staging or live. The Jotform repository, evidence store and catalog are stand-ins; real submissions, Drive evidence, PDF preflight and approval were not exercised. The audit save was answered with canned replies.
- Firefox and Safari; Akansoh and Purple themes (brand tokens only differ).
- Real page builders and themes beyond the synthetic hostile theme.

## Rollback

Uncheck the redesign option: the legacy tabs return, unchanged. To go back to the previous build, reinstall `Gridhouse-admin-compliance-dashboard-1.7.3-rc.8-51d8679.zip` (same folder, "Replace current with uploaded"). Nothing in this slice writes new data or changes stored formats, so no data rollback is needed.

## Remaining admin-dashboard work

- The decisions above.
- Boards not yet implemented: `Admin-UserReport` (user training report page), `ManageUsers`, `Modal-Announcement`, `Print-AuditPacket`, the `WP-Settings-*` boards, and the `OverlaySet` items not already used.
- No tablet or phone boards exist for External training, Audit data, Reports, Overdue, Courses or Certificates; their responsive layouts follow the existing phone patterns.
- Earlier outstanding staging checks (see the slice-4 doc and earlier build records) remain open; staging is not cleared.

## Package

| | |
| --- | --- |
| File | `Gridhouse-admin-compliance-dashboard-1.7.3-rc.8-ce36016.zip` |
| Source commit | `ce360169c635b1c7e0f569cb62733e838defd890` (branch `gridhouse-overview-odp-bulletins-af45`) |
| Byte size | 661,159 |
| SHA-256 | `b940b15488ab661e4a3342673028e535f621e3c12aa39bdfd6d2c4fc8c751c0e` |
| Root folder / entry file | `Gridhouse-admin-compliance-dashboard-plugin-main/gridhouse-admin-compliance-dashboard.php` (same as staging; updates in place) |
| Contents | Cumulative Slices 1-5 on top of 1.7.3-rc.8. 140 runtime files, each byte-identical to the commit; `BUILD.txt` inside lists every file's SHA-256 |
| Checks on the archive | `unzip -t` clean; every file compared with `git show ce36016:<path>`; `php -l` on every PHP file |

Not installed anywhere. Older builds are kept unchanged.
