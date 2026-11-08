# Dashboard redesign, phase 2, slice 7: Communications, row reminders, packet header and packet-ready notice

Opt-in, behind `ghca_acd_ui_redesign`, like every slice. Builds on Slice 6 as hardened in `826d342`. The bulk selection and messaging work, the bulk-retry fix, the concurrency hardening, the Administration access fixes and every Slice 5 change are unchanged, and their tests still pass. The legacy screens are unchanged when the redesign is off: the legacy history modal, the legacy drawer and the legacy packet download (which still starts the download automatically) behave as before. The outbound guard, bulletin publishing and the employee dashboard were not touched.

**Design source:** "Gridhouse Compliance — Phase 2 Dashboards", canvas version `1794092638-1fc0`. Between the Slice 6 version and this one, only `Drawer-Communications` changed and `Modal-CommHistory` was added. The component sheet went to `gh-components.css` v2.4.1, which adds the `.gh-ch-*` history styles and the `i-copy` icon; those rules are copied into `gh-redesign.tail.css`, scoped to `:is(.ghca-ui, #ghca-ui-scope)`, tokens only.

**Screenshots:** the screenshots attached to the request were not visible in this session. Everything below was matched against the design artifact's boards, not against those images.

## 1. Drawer Communications tab (Drawer-Communications, Modal-CommHistory)

### Built

- **Channel sub-tabs:** All (with the message count), Email and SMS. They are toggle buttons with `aria-pressed`. Choosing one asks the existing history endpoint (`ghca_acd_get_communication_history`, same nonce, capability and scope checks) for that channel (`view=drawer`) and swaps the list in place. A polite status line announces "SMS: 3 messages". A failed load shows an alert; selecting the same tab again retries.
- **Message history (latest three):**
  - Each entry has the channel, date and sender, the subject, a status chip per delivery, and a Show/Hide message control (native `details`; the label follows its state). It also has the reason and Retry for a failed delivery, and "Urgency: … · Template: …" (the template is named only when the message was sent from one).
  - The footer reads "Showing latest N · View all communication history".
- **New reminder:** opens the existing single-recipient composer.
- **Full communication history (new board modal, replacing the legacy modal when the redesign is on):**
  - Totals tiles: All messages, Email, SMS, Failed (recorded totals only).
  - Filters for search, channel, status, from date and to date, with Apply and Clear filters.
  - A results count with "Newest first", and 20 messages per page with a pager.
  - Per message: the channel icon, the subject, the channel and urgency, date and sender, and status chips per delivery. A "View full message" toggle (`aria-expanded`/`aria-controls`).
  - **Use again** reopens the composer with the message as a draft. It is offered only for reminders sent from the drawer, as the server already enforces.
  - **Retry** on a failed delivery uses the existing per-delivery retry.
  - The note that "Accepted by email transport" is not proof of receipt.
- **States:** empty for All, Email and SMS each with its own wording; loading (`aria-busy`); error; expired session (the Slice 6 session message); no permission (the history modal and the View all link are not rendered).
- **Permissions and behaviour preserved:**
  - Viewing history still needs `user_can_view_communication_history()` and scope on the employee.
  - Use again and Retry need the same capabilities as before, and the server checks them again.
  - Nothing new can send a message: Use again only fills the existing composer, which keeps its review-before-send step.

### Behaviour to know

- The channel filter is per message, as in the existing repository. A message sent by Email + SMS appears under both Email and SMS, with both deliveries' statuses.

### Design elements that need new functionality (not built, not silently dropped)

| Board element | Why not built | What would be needed |
| --- | --- | --- |
| "Logged" sub-tab, logged calls in the timeline, "Logged" totals tile | The plugin has no record of phone calls or other logged contact | A logged-contact table, a form to record a call, permissions and audit rules |
| "Try again" on a failed SMS opening the composer with a corrected number | There is no in-dashboard way to correct a phone number | The existing per-delivery **Retry** is used instead, which retries the same delivery |
| "Open Administration" link from a missing-phone failure | Phone numbers are not edited in the Administration panel | A phone field in Edit Records (with consent rules) |
| "Email sent" / "SMS sent" tile wording | The totals count all deliveries, not only sent ones | Labelled "Email" and "SMS" so they do not overstate |

## 2. Employees table: per-row reminder action

- Every active row in the redesigned Employees table has a paper-plane icon button next to View. Phone cards have a "Remind" button.
  - It is a real `<button>`, labelled "Send reminder to {name}".
  - Enter or Space operates it; the focus ring and hover come from the design system.
  - Targets are 44px at tablet and phone widths, and 32×30px on desktop (above the 24px WCAG 2.5.8 minimum).
- It opens **the existing single-recipient composer** for that row's employee. The composer is the same one the drawer's Send reminder button uses, with the same messaging context request for that user id. So the recipient, masked destinations, templates, channel readiness, review-before-send step and server-side capability and scope checks are all the existing ones.
- It is rendered only when the viewer can send reminders. It is not on the Inactive tab.

## 3. Packet-generator header

- Only the header of the packet card ("Packet generator / Compliance packet / Compiles the compliance summary…") takes the active agency's main colour (`--gh-agency`). Text and icon use `--gh-agency-on`, the contrasting colour the plugin already computes for each agency.
- The card body is unchanged.
- No colour is hardcoded: the header follows whichever agency is active, with the plugin's primary colour as the fallback.
- The icon sits top-right of the copy, as on the board.
- Contrast was measured in the browser for a dark agency colour (purple `#6A2C91`, 8.8:1) and a light one (yellow `#F2C94C`, 11.2:1). Header text has no reduced opacity, so the measured contrast is the contrast seen.
- **Deviation:** the board shows a brand tint here; the main colour is used, as instructed.

## 4. Packet-ready notification

### Before

When the merge finished, the redesigned drawer navigated to the download URL, so the packet downloaded automatically.

### Now (redesign only)

- Generating a packet **does not download anything and does not navigate**. The progress dialog closes, and the drawer and Edit Records stay open with any unsaved changes kept. A notice appears in the packet card: "Annual packet ready" or "Orientation packet ready". It has an explicit **Download PDF** link, labelled "Download Annual packet (PDF)".
- **Details only from the real result:**
  - The merge response now includes `pages` (from the generated PDF), `flagged` (the evidence-review list printed in the packet), `tracker` and `expires` (the job's creation time plus its one-hour TTL).
  - The notice shows each one only when it is present and numeric. With none supplied it shows none, never placeholders.
  - Expiry is shown in the viewer's local time.
- **Accessible:**
  - `role="status"` when ready; `role="alert"` when it reports a problem.
  - The dismiss button is labelled "Dismiss notice (the download link stays available)" and moves focus to the link.
  - The link and dismiss are 44px on touch widths.
- **Dismissed or timed out:** after 12 seconds, or on dismiss, the notice collapses to its title and link. It is never removed while the link is usable.
- **Leaving Edit Records:** the notice moves to a page-level tray (bottom-right, above the drawer) when its card stops being visible. This covers going Back, closing the drawer and opening another employee. Packets generated from the drawer's More menu go straight to that tray.
- **Downloading:**
  - The link fetches the existing private endpoint (`ghca_acd_pdf_download`: nonce, `user_can_view_employee_documents()`, ownership of the job, expiry) and saves the file only if the answer is a real PDF. The plugin's existing PDF check (content type and `%PDF-` signature) is reused.
  - Otherwise the server's message is shown and nothing is saved.
  - A network error keeps the link and says nothing was saved.
- **States the notice reports:**
  - **Downloaded:** "the private copy on the server has been deleted". Links are single use; this was already the server's behaviour.
  - **Expired:** at the reported expiry the link is removed. If the server refuses an older link, the notice shows "no longer available".
  - **Replaced:** starting another packet replaces the earlier one. The server keeps one packet per person, so the earlier notice says so and drops its link.
- **Repeated clicks:** while a packet builds, every packet button is marked busy (`aria-disabled`) and further starts are ignored. Three rapid clicks start one generation, and the server's job lock and "already merged" guard still apply.
- **Failures:** the progress dialog shows the server's failure message (for example "No packet was created"). No notice or link is created, and the buttons become usable again.
- **Unchanged:**
  - Private storage outside the web root, or in a deny-rule directory.
  - File mode 0600.
  - Authorization on download.
  - The one-hour expiry, single use and cleanup.
  - One packet per person.
  - The link is the authorized admin-ajax endpoint; no permanent or public URL is exposed.

## Tests

### Synthetic (no WordPress screen, no staging)

| Suite | Result | What it covers |
| --- | --- | --- |
| `tests/browser-slice7.cjs` (new) | 150 checks | Covers all four items at 1440, 834 and 390px. **Paper-plane:** label, keyboard, right recipient, review step, one canned send for that id only, absent without permission. **Communications:** sub-tabs, Show/Hide, failure and retry, the board modal's totals, filters, paging, toggle, Retry, Use again and empty states. **Header:** colour and contrast for two agency colours. **Packet:** no download or navigation, unsaved field kept, details only when supplied, keyboard Download saves the PDF, single use, expiry, replaced, dismiss and time-out, tray, network error and generation failure. Packet bytes and send answers are canned; nothing is queued or sent. |
| `tests/test-redesign-drawer.php` | 142 | Footer copy and empty-state count updated for the new tab |
| All `tests/test-*.php` and `tests/test-*.js` | 81 files, all pass | |
| All other browser suites | drawer 159, tables 53, bulk 24, drawer access 9, lists 51, admin 20, corrections 21, ODP pages 24, themed ODP 34, page background 18; all pass | Slice 1–6 regressions |

### Real WordPress 6.6.2 + MariaDB 10.11 (disposable `ghca_test` site; synthetic users; not staging)

| Test | Result | What it covers |
| --- | --- | --- |
| `tests/integration-packet-ready.php` (new) | 29 checks | The plugin's own init, merge and download handlers build real Annual and Orientation packets with TCPDF 6.7.5 and FPDI for a synthetic employee. The reported pages equal the stored PDF's page count, flagged equals the evidence-review list, and expiry equals created + TTL. The file is private (0600) and stored outside the web root, and the link is the admin-ajax endpoint. Another user is refused, the first download returns the PDF and deletes the file, and a second download, an expired job and a replaced job are each refused with "no longer available". Merging twice is refused. LearnDash is stubbed (no courses); nothing is sent. |
| `tests/integration-bulk-recovery.php` | 29 | Slice 6 hardening, unchanged |
| Other `tests/integration-*.php` | pass | |
| History query spot check (not a committed test) | pass | Inserted one synthetic two-channel message, then read it through the real repository and `history_data()`: channel filters, template name, Use again and Retry rendered. The rows were then deleted. |

### Not verified

- **Not checked on staging:** none of this has been installed on Akansoh staging. Staging still runs `51d8679`.
- **Untested in other browsers:** the browser checks ran in Chromium only. Safari and Firefox download handling for the blob save was not tested.

## Rollback

Install the previous build. No schema or option changes were made in this slice. The merge response only gained fields.
