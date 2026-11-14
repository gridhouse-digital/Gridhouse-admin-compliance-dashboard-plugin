# Dashboard redesign, phase 2, slice 3: employee drawer, Edit Records, Send reminder

Opt-in, behind `ghca_acd_ui_redesign`. Unchecking it restores the previous drawer and modals. Training math, audit records, capabilities, agency isolation, packet building and messaging are unchanged: the redesigned screens call the same handlers with the same field names, and the legacy markup is still what prints when the setting is off.

Boards: `Drawer-Training`, `Drawer-Administration`, `Drawer-Documents`, `Drawer-Communications`, `Drawer-EditRecords`, `Modal-SendReminder`, `M-Admin-Sheet` (phone). Commits, oldest first: `ef7156d` (3a), `9623fb7` (3b), `6271fa2` (3c), `5234a3e` (3d).

## What shipped

| Area | Change |
| --- | --- |
| Drawer shell and Training (3a) | 600 px right drawer (full-screen sheet on phones) in board markup, mounted inside the themed wrapper. Summary ring, the alert for the most urgent course, collapsible course lists, header status chip, More menu above the footer. `GHCA_ACD_Redesign_Drawer`. |
| Administration (3a) | Employment details with warnings for an unset employment type and an unverified start date, review line and Mark reviewed, Edit records entry. |
| Documents (3b) | File rows with the board icon and status chip, certificate preview through the existing script, and the outside-certificate form restyled. Upload still posts to `ghca_acd_jotform_manual_upload`. |
| Communications (3b) | Timeline of recent messages, combined Email + SMS entries, failed deliveries marked, Retry where the legacy rule allows it, "View all communication history" still opens the full history. |
| Edit Records (3c) | The sub-page widens the drawer to 920 px. Three cards (employment and role, milestones and dates, course records), an unsaved-changes counter, a sticky Save bar, Cancel, Back and Escape-back. Packet generator and agency-delivered training sit beside it. |
| Send reminder (3d) | Board modal inside the wrapper, above the drawer: compose, a **Review & send** confirm step, then a toast. A refused send keeps everything typed and reports in an error toast. On phones it is a bottom sheet. `GHCA_ACD_Redesign_Reminder`. |
| Toasts | The stack now sits above every dashboard overlay (it was level with the drawer), so an error is never hidden behind the reminder modal. |

### How the redesigned markup is chosen

The drawer, the Edit Records form and the upload reply each ask for board markup with `ghca_ui=board`. `GHCA_ACD_Redesign_Tables::board_requested()` honours it only while the redesign setting is on, and only after the nonce, dashboard access and employee-scope gates have run. The redesigned dashboard prints its own drawer and reminder modal inside `.ghca-ui`; the footer copies (`render_employee_drawer_modal`, the reminder block of `GHCA_ACD_Messaging_UI::render_modal`) step aside only when it has. The Documents, history and certificate modals and the PDF progress modal still print in the footer in the legacy style.

### Legacy output is proven unchanged

Each legacy handler was split into a data model and two renderers. Golden SHA-256 fixtures captured before the split hold the legacy drawer in ten states (`tests/fixtures/golden-legacy-drawer.sha256.json`) and the legacy Edit Records form in seven (`golden-legacy-edit-form.sha256.json`). The tests recompute them.

### Hooks that did not change

Every `data-ghca-*` attribute and id the scripts bind to is kept, with a `[data-…]` alternative added to the selector where the board element has a different class. The legacy selector strings were left as they were, because tests pin them. This includes `data-ghca-employee-drawer`, `data-ghca-reminder`, `data-ghca-reminder-*` (every one of the legacy modal's, checked by a test), `data-ghca-edit-records`, `data-ghca-edit-form`, `data-ghca-pdf-packet`, `data-ghca-audit-*`, `data-ghca-agency-*`, `data-ghca-packet-*`, `data-ghca-manual-*`, `data-ghca-document-open`, `data-ghca-history-open` and `data-ghca-reminder-retry`.

### Safety properties

- The Edit Records form submits exactly the legacy field names (`employment_type`, `registration_date`, `employment_date`, `employment_source`, `employment_verified`, `first_service_date`, `worked_alone_date`, `course[ID][completed|minutes|mark_complete]`). Role, group and status are read-only and unnamed, so they cannot be posted. A test compares the name sets of the two forms.
- The confirm step in the reminder modal is client-side only. It sends nothing itself: its button submits the same form through the same handler, with the same one-time idempotency token and the SMS no-sensitive-information confirmation.
- The reminder modal is only printed for people with `user_can_send_reminders()`. Nothing in the tests sends, queues or writes: every send, save and retry route is answered with a canned response.

## Fidelity

Method as in slice 2: the board's own markup and unscoped board CSS in Chromium at the same viewport, against the plugin's render, compared per component on typography, colour, border, radius, padding, margin, gap, shadow, size and layout. The comparison tools are scratch scripts, not committed.

| Screen | Components compared | Identical | Remaining differences |
| --- | --- | --- | --- |
| Training, 1440 | 34 | 20 | Content: different wording and status text, extra plugin copy, the board's 30-day verdict banner (not computed by the plugin, see gaps). |
| Administration, 1440 | 18 | 15 | The plugin's existing explanatory copy. |
| Documents, 1440 | 23 | 11 | The board's required-documents list and expiry field have no plugin data; row and form components match. |
| Communications, 1440 | 12 | 5 | The board's All/Email/SMS/Logged sub-tabs are not built; entries are laid out from real history. |
| Phone sheet, 390 | 11 | 4 | The board's phone sheet is a different compact layout (chip placement, tab labels, Packets button). |
| Edit Records, 1440 | 28 | 19 | Content and structure: the board's header holds the back bar and title, the plugin's sits in the sub-page body; longer legacy wording on the verify box and chips; the plugin's extra packet description. Course grid, cards, labels, inputs and helper text match. |
| Reminder compose and confirm, 1440 | 37 | 30 | Content and state only: the extra preview and notes make the dialog taller, the recipient strip has no due chip, and the board's static page shows one field focused. |

Isolation was run against the same stand-in theme as slice 2 (forced `!important` link colour, underline and hover fill, heading family and case, table borders, button hover, `summary` size and list style). Measured styles of the drawer, every tab, the Edit Records page and the reminder modal are identical with and without it. Two `summary` leaks it found were fixed by declaring font properties on `.gh-fold-hd` and `.gh-mod-pv-hd`.

Regression: phase-1 overview, library, empty library and bulletin page were rendered with the phase-1 stylesheet and the current one, with the legacy dashboard stylesheet loaded, at 1440 and 390, and every element's computed style compared. 1,024 of 1,026 element/viewport/page combinations are unchanged. The two that changed are the toast container, which phase 1 did not style (it is the slice-2 stack). The slice-2 browser suite (53 checks) still passes.

## Not built, and why

These board elements have no functionality behind them in the plugin. None is faked.

| Board element | What the plugin does instead |
| --- | --- |
| 30-day verdict banner on Training | Omitted. The drawer does not compute it. |
| "Required documents" per role, an Expires field and a size limit on the upload | Omitted. No such data or rule exists. |
| Communications sub-tabs All, Email, SMS, Logged | Omitted. The timeline shows the three most recent messages; "View all communication history" opens the full, filterable history. |
| Bulk reminder confirm ("Send to 2 people") | Omitted. There is no multi-select send. |
| Merge-field hint under the message | Omitted. It would imply custom messages are merged, which the plugin does not do. Templates are rendered by the server as before. |
| Due chip on the recipient strip | Omitted. The messaging context does not return it. |
| Packet "Includes" checkboxes and "Pre-check" | Omitted. The packet content is fixed by the existing generator, and the plugin does no pre-check. The existing progress mirror is shown while a packet builds. |
| Registration date shown read-only | Kept editable, as in the legacy form, so existing functionality is preserved. |
| Orientation / Annual toggle in the packet card | Two buttons, Orientation and Annual (and OLTL training where that feature applies), as the generator has. |

## Decisions still to confirm

1. **Phone tab height** (from slice 2): `gh-app.css` makes tabs 44 px, the accepted overview board 42 px. Kept 42.
2. **Column drop** (from slice 2): the canvas rules that hide table columns on narrower screens are not applied.
3. **Footer modals.** The Documents, history and certificate modals and the PDF progress modal are still the legacy UI above a redesigned drawer. Restyling them is the same pattern as the reminder modal; say if you want it before slice 4.
4. **Phone sheet.** The board's sheet is a compact layout that differs from the drawer in chip placement, tab labels and the Packets button. The plugin keeps the same drawer as a full-screen sheet.

## Known gaps

- The agency-delivered training body is server markup, styled by adapter rules rather than rebuilt in board classes.
- The OLTL card in the packet page stays legacy markup.
- Loading is a dim and `aria-busy`, not skeleton rows.
- A success toast appears bottom-right over the drawer footer, as on the board. Dismiss it or wait six seconds to reach the footer.
- Not verified here: LearnDash, BuddyBoss and Elementor are not installed, so isolation is by selector weight and the stand-in theme. The stand-in WordPress runs the plugin's own classes on synthetic data, not a real site. Staging has not been touched.

## Tests

| Test | What it covers |
| --- | --- |
| `php tests/test-redesign-drawer.php` (139 checks) | Real drawer handler on synthetic records: gates, tabs, counts, wording, hooks, the legacy goldens in ten states. |
| `php tests/test-redesign-edit.php` (66 checks) | Real Edit Records handler: field-name parity with the legacy form, read-only fields, gates, the legacy goldens in seven states. |
| `php tests/test-redesign-reminder.php` (24 checks) | Hook parity with the legacy reminder modal, gating, the consent and record-keeping wording, no unsupported claims. |
| `node tests/browser-redesign-drawer.cjs [dir]` (152 checks) | Chromium. Real PHP for the page and every drawer and form load. Drawer geometry, tabs, keyboard, focus trap and return, the More menu, certificate modal layering, error and retry states, upload, Edit Records (widening, dirty counter, canned save, Back, Escape, Cancel, packet period, agency load), the reminder modal (compose, validation, templates, preview, confirm, Escape and Back, refused and accepted sends, SMS consent, channel not ready, retry of a failed delivery), rendered fonts, a hostile theme, and phone layouts. |
| `node tests/browser-redesign-tables.cjs` (53 checks) | Slice 2, unchanged and passing. |
| Every other `tests/test-*.php` (71 scripts) and JS test | All pass. |

`php tests/render-drawer-preview.php page <dir>` writes the synthetic page for screenshots; its `ajax`, `edit` and `card` modes run the real handlers.
