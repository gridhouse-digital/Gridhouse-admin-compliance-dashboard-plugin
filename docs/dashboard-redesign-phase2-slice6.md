# Dashboard redesign, phase 2, slice 6: bulk selection and messaging, drawer review, Administration access

Opt-in, behind `ghca_acd_ui_redesign`, like every slice. Builds on Slice 5 (`ce36016`); its External training, Audit data, Reports and Courses changes, including the Yes/No certificate column, are unchanged. The employee dashboard, the outbound guard, bulletin publishing and the legacy screens were not touched; the legacy drawer's goldens still match.

Design source: "Gridhouse Compliance — Phase 2 Dashboards", canvas version `1794077292-4607`. Between the Slice 5 version and this one, only `Drawer-Administration` (and the canvas index) changed. The screenshots mentioned with the request were not visible in this session; the investigation below is from the code and from synthetic reproduction, not from those images or from staging.

## 1. Administration access inconsistency

### What was traced

| Path | Check | Result for a signed-in administrator |
| --- | --- | --- |
| Drawer tab and panel (`drawer_model()` → `can_admin`) | `GHCA_ACD_Roles::user_can_edit_records()` | Always true: `manage_options` short-circuits it |
| Drawer request (`ajax_get_employee_drawer`) | dashboard nonce, `user_can_view()`, `GHCA_ACD_User_Report::can_view_user()` | Always passes: `manage_options` short-circuits scope |
| Edit Records form, Save, Mark reviewed | nonce, `user_can_edit_records()`, `can_view_user()` | Same two checks as the tab, on every action |
| Agency training (drawer) | `user_can_edit_records()` and `can_view_user()` | Same |

Every path uses the same capability and scope functions; none trusts client state. Nothing is cached server-side per request (the drawer is a POST to admin-ajax, built fresh each time). A PHP test confirms two requests for the same administrator and employee produce byte-identical markup.

### Root cause

"Employment records. Restricted to authorized personnel." was **fixed copy, not a check**. The redesigned Administration panel printed it as a neutral alert at the top of the panel, above the employment data, and the panel only renders when access has already been granted. A denied user never sees the tab, the panel or that sentence. So the screenshot showing the notice alongside employment information is the granted state. The notice read like a denial because of its wording and its alert styling, not because anything was denied.

Why it looked inconsistent, in the code (all presentation, none authorization):

1. **Two drawers carry different wording.** The redesigned drawer said "Employment records. Restricted to authorized personnel."; the legacy drawer (used wherever the redesign wrapper is not rendered: the redesign switched off, pages built from the individual shortcodes, or a cached page saved while the redesign was off) says "Administrative Overview — … This information is restricted to authorized personnel." under a heading. Which one appears depends on the page's drawer host (`data-ghca-ui="board"`), not on the user.
2. **The drawer remembers the last tab per employee.** Reopening an employee whose last tab was Administration lands on it (with the notice); others open on Training.
3. **The Edit Records sub-page hides the overview**, including the notice.
4. **An expired dashboard nonce or session** (for example a cached page older than the nonce lifetime) made admin-ajax answer `-1`, which the drawer reported as "Network error", a third apparent state.

Two related client-side weaknesses were found while tracing reused drawer state (neither caused a denial or a leak in testing):

- Edit Records saved to a shared "current employee" variable rather than to the employee the form was rendered for.
- An Edit Records request still in flight was not cancelled when the drawer closed or another employee opened (its late response wrote into detached markup, so nothing leaked, but it was wasted and fragile).

A separate, real scope inconsistency exists for **delegated (non-administrator) roles**, and is not changed here: the employee list includes anyone in any visible group, but `can_view_user()` checks only the employee's *primary* group (the new-hire group when the employee is in new-hire tracking). A group leader can therefore see a new hire in the list and be refused in the drawer ("Invalid employee or permission denied.") when the new-hire group is not in their visible groups. That is an error, not the notice, and it never affects administrators. **Decision needed:** align `can_view_user()` with the list's scope (membership in any visible group) or leave it.

### Fix (targeted; no permission was widened, no check removed)

- Redesigned Administration copy now says who can see the records and why: "Core employee records and employment status details. Confidential: shown only to people with permission to edit employee records." The legacy drawer's wording is left as it was (legacy screens are not changed).
- Edit Records saves to the form's own `data-user-id`; a form that does not match the open employee refuses to save ("This form is out of date… Nothing was saved.").
- Opening or closing the drawer cancels any Edit Records load in flight.
- `-1`, `0` or a non-JSON 403 from admin-ajax is reported as "Your session has expired or this page is out of date. Reload the page and try again." JSON error replies keep their own message.

## 2. Bulk selection and bulk reminders (Admin-Employees board)

### Selection

- Row checkboxes on the active Employees table and on its phone cards; a header "Select all employees on this page" checkbox with the indeterminate state when some rows on the page are selected. Not on the Inactive tab.
- Select all adds **only the rows on the current page**. The selection survives paging, filtering and sorting (the bar sits outside the refreshed table). Whenever selected employees are not on screen the bar says how many and names them, and offers "Keep only those shown". Nothing hidden is ever added; nothing is selected implicitly.
- The bar (`gh-bulkbar`, agency colour, sticky): count (announced), names, the hidden note, Send reminder to N, Export selected, Keep only those shown, Clear selection.

### Export selected

Posts the selected ids to the existing export (`admin-post.php?action=ghca_acd_export_csv`, same nonce and capability). The server builds the CSV only from employees in the viewer's own current view, in selection order; **if any selected id is not in that view, nothing is exported**. Without a selection the export is the ordinary full export, unchanged.

### Send reminder (email only)

`GHCA_ACD_Bulk_Messaging` (new) wraps the existing `GHCA_ACD_Messaging_Service`; nothing bypasses it.

1. **Context**: the server checks every selected id with the service (send permission, agency scope, email readiness, inactive policy). Out-of-scope ids are reported by id only, with no name or address. Templates approved for email are offered unrendered.
2. **Compose**: template, urgency, subject, message, with the existing placeholders (`[employee_first_name]`, `[due_date]`, `[agency_name]`, `[portal_url]`). Delivery is Email; "SMS is not available for group reminders" is stated and SMS cannot be chosen. (SMS remains available for single reminders where Twilio is configured, as before.)
3. **Review** (required): every recipient with a masked address and "Will be sent" / "Won't be sent" plus the reason; a per-recipient preview rendered by the service (`preview_email()`, new, read-only); an explicit confirmation checkbox. The server refuses a send without the review flag.
4. **Send**: one `queue()` call per reviewed, ready recipient. Each person gets their own personalised email and their own Communication History record; no message is addressed to more than one person.
5. **Results**: per person, the record's real lifecycle state (see [Bulk retry fix](#bulk-retry-fix-after-63c3287)), and totals that count as queued only messages with a job. "Retry the N not queued" acts only on recipients saved but not scheduled, refused, or being scheduled by another request.

Duplicate prevention: one batch token is the idempotency token for every recipient; the service keys it with the actor and the employee and the database enforces it as a unique key. Resubmitting or retrying a batch therefore never creates a second message for anyone; an existing record is reported, or recovered if it was saved but never scheduled. The Send and Retry buttons are disabled while a request is in flight. If the connection drops mid-send, the dialog says some emails may have been queued and Retry is safe for the same reason.

Limits: at most 25 recipients per send (the service's own sender limit is 30 per 10 minutes); 26 or more selected disables Send and says so; Export selected has no limit. Recipients over the sender rate limit are reported as not sent.

What Communication History records: every saved email and its delivery outcome (written by the existing worker). Recipients refused before saving (no valid email, out of scope, inactive, rate limit) are shown in the dialog's results and are **not** written to Communication History, because nothing was created or sent. A message saved but not scheduled shows there as a failed delivery ("The background email job could not be scheduled.") until a bulk retry or the existing per-delivery Retry schedules it. **Decision needed** if refusals must also be logged.

Permissions: selection and Export selected for anyone who can see the Employees tab; Send and the dialog only for `user_can_send_reminders()` with email reminders enabled. Every endpoint re-checks on the server.

## 3. Employee drawer against the design system

| Board | Implementation | Measured (Gridhouse, 1440 unless stated) | Notes |
| --- | --- | --- | --- |
| Drawer-Training | Training tab | 13 / 13 identical | |
| Drawer-Documents | Documents tab | 11 / 11 | |
| Drawer-Communications | Communications tab | 4 / 5 | Remaining pair is the board's message card matched against the plugin's empty-state card (explained in the audit doc). |
| Drawer-Administration (updated) | **Rebuilt this slice**: overview hero, verified-start warning with Enter date, Employee Training Record card, Compliance Review card, Employment status panel, System meta; 860px drawer on this tab | 7 / 7 | Hero copy changed as above. Agency colour replaces the theme primary in the hero icon, status panel, brand card and buttons. "Last Reminder" shows only with communication-history permission, as before. |
| Drawer-EditRecords | Edit Records sub-page (920px) | 16 / 19 | Remaining three are pairing artifacts already explained (verify box, title margin, body gap). |
| Modal-SendReminder | Reminder modal | 16 / 17 | Remaining pair is the board's focused input demo state. |
| M-Admin-Sheet | Drawer as phone sheet (390) | 10 / 10 | |

Behaviour checked in the browser suites: tab keyboard (arrows, Home, End), focus trap and return, Escape order (sub-page, then drawer), scrolling body with the fixed header and footer, More menu, loading skeleton, error and retry states, phone sheet, 44px targets at tablet and phone widths, no sideways scroll.

Not measured: hover and focus states beyond the suites' checks; the communication-history and document-viewer modals (no board for them); Akansoh and Purple themes.

Board elements still not built, because the plugin has no data or rule behind them (recorded in `docs/dashboard-redesign-phase2-slice3.md`, still true; none is faked or counted as done):

- Training: the 30-day verdict banner.
- Documents: "Required documents" per role, an Expires field, and an upload size limit.
- Communications: the All / Email / SMS / Logged sub-tabs (the timeline shows the latest three; the full history modal filters).
- Reminder modal: the due chip on the recipient strip; a merge-field hint.
- Packet card: "Includes" checkboxes and "Pre-check".

The bulk "Send to N people" confirmation listed there is built in this slice.

## Tests

All synthetic: stand-in WordPress, synthetic employees, Chromium 153; messaging over an in-memory repository with a mocked worker that only records what it would schedule (`wp_mail()` throws if called). Nothing was run on WordPress, staging or live; no message was sent; no training record was changed.

- `php tests/test-bulk-messaging.php` (51 at `63c3287`; 83 after the retry fix, see below): id boundaries (empty, malformed, duplicates, 25 / 26); nonce and permission on all three endpoints; email-disabled; per-recipient readiness and scope; masked addresses; email-only templates; personalised previews; review flag and token required; one record and one email delivery per recipient; partial failure; same-batch resubmit and retry create nothing; new batch is a deliberate new send; sender rate limit reported; Export selected exact, ordered, fail-closed.
- `php tests/test-drawer-access.php` (25): permitted and denied users in both drawers; no employment value reaches a denied user; out-of-scope refusal; every Administration action refuses without permission or scope; the two wordings traced; the script fixes present.
- `node tests/browser-redesign-bulk.cjs` (23 at `63c3287`; 24 after the retry fix): selection across pages and filters, indeterminate state, Keep only those shown, Clear, Export selected request, the 25 limit, full keyboard compose / review / send, one request despite repeated clicks, partial failure and retry with the same token, Escape and focus return, no-permission variant, 1023 / 834 / 390 and a hostile theme.
- `node tests/browser-drawer-access.cjs` (9): administrator at 1440 / 834 / 390; reopen; switching to a refused employee leaves nothing of the previous one; slow Edit Records load cancelled; denied user (no tab, forced request refused); expired session message.
- Updated: `test-redesign-drawer.php` (142), `test-redesign-tables.php` (120), `browser-redesign-drawer.cjs` (159).
- Full run: every PHP test file and every browser suite pass (see the build record for the exact run).

## Open items and decisions (none approved by this slice)

From Slice 5, still open:

1. External training: list rejected submissions (needs a new read-only query).
2. External training: required rejection reason and employee notification (changes the handler, storage, possibly outbound messaging).
3. Audit data: verdict, tiles, topic columns, Orientation / Annual subtabs (performance and mapping decisions).
4. Audit data: where verified-start editing lives (drawer, Audit data, or both).
5. Reports: Course report, new metrics (avg quiz, avg time), Tin Can / xAPI, Excel; and whether "View certificates" opens the Certificates tab.
6. Courses: course CSV export and per-course report.
7. Custom date picker (native popups cannot be branded consistently).

Carried forward:

8. **Compressed-reference PDF compatibility.** The bundled free FPDI parser cannot read PDFs with compressed cross-reference streams (PDF 1.5+ object streams) or encrypted PDFs. Such certificate uploads are refused at capture with "use Print to PDF" guidance (`GHCA_ACD_External_Evidence_Store::unreadable_pdf_message()`), so packets never break, but the uploader has to re-save the file. Investigation still open: a licensed parser add-on, a server-side rewrite (for example qpdf) where hosting allows, or a pure-PHP cross-reference stream reader. No change in this slice.

New in this slice:

9. Delegated roles: align `can_view_user()` with the list scope (see section 1).
10. Bulk: whether recipients refused before queueing should also be logged in Communication History.
11. Bulk: SMS for group reminders (not built; needs approval, consent and content review).
12. Legacy drawer wording "This information is restricted to authorized personnel." (left unchanged with the legacy screens).

Staging checks from earlier builds remain open; staging is not cleared.

## Bulk retry fix (after `63c3287`)

**Defect (verified in review).** `GHCA_ACD_Messaging_Service::queue()` saves the message, then schedules its background job. When scheduling failed it marked the delivery `failed` (`queue_schedule_failed`) and returned an error, but the record stayed. "Retry the N not sent" reused the batch token, so `queue()` found the existing record by its idempotency key and returned it as a duplicate. The bulk class reported that as "Already recorded", scheduled nothing, and the summary counted it as queued. The message was never sent and nothing said so.

**Fix.** The existing record is reused and recovered through the existing lifecycle; no second message is created and idempotency is untouched.

- `GHCA_ACD_Messaging_Service::recover_delivery()` (new) reads the delivery's real state and acts only when that is unambiguous:

  | Delivery state | Bulk result | Action |
  | --- | --- | --- |
  | `accepted`, `sent`, `delivered` | Already sent | none; never resent |
  | `processing` | Sending now | none; a worker holds it |
  | `queued`, job waiting | Already queued | none |
  | `queued`, no job (the saving request died before scheduling) | Queued now (recovered) | schedule its job |
  | `failed`, `queue_schedule_failed`, never attempted | Queued now (recovered), or Saved, not scheduled if scheduling fails again | `requeue_failed()` (compare-and-set failed → queued) then schedule; on failure `mark_schedule_failed()` again |
  | `failed` after an attempt, `undelivered`, `suppressed`, or an unknown outcome (`worker_interrupted`) | Delivery failed | none; left for review in Communication History, where the existing Retry rechecks it |

  Before acting it re-checks the send-reminders permission, agency scope, email readiness and the inactive policy, exactly as for a new message. Rate limits apply to new messages; recovery creates none.
- **Concurrency.** Scheduling one delivery is serialised by a short lock (`acquire_schedule_lock()`, the same insert-or-fail option pattern as WordPress core's upgrader lock, with a compare-and-set takeover of a stale lock). A second simultaneous request reports "Being scheduled elsewhere" and does nothing. `requeue_failed()` and the worker's `claim_delivery()` were already compare-and-set, so even two jobs for one delivery would send it once.
- `mark_schedule_failed()` is now compare-and-set on `queued`, so it can never overwrite a delivery a worker has claimed or finished.
- `GHCA_ACD_Messaging_Worker::schedule()` treats WordPress refusing an identical pending event (core refuses duplicates due within ten minutes) as "already scheduled", instead of as a failure that would mark the delivery failed.
- `queue()` attaches the saved record's ids to its scheduling error, and the bulk class reports it as **Saved, not scheduled (nothing sent)**.
- Totals: queued counts only Queued, Queued now (recovered) and Already queued, that is, messages with a job. Already sent, Sending now, Saved but not scheduled, Not sent, Delivery failed and Being scheduled elsewhere are reported separately.

**Regression tests** (synthetic recipients; the plugin's real service, bulk class and worker over an in-memory repository with the same compare-and-set rules and a fake WordPress cron that refuses duplicate events; jobs are recorded, never run; `wp_mail()` throws if called). `php tests/test-bulk-messaging.php`, 83 checks, each asserting the stored message and delivery records and the actual pending jobs, not only the response:

- persistence followed by scheduling failure (failed / `queue_schedule_failed` / 0 attempts / no job; not counted as queued);
- successful recovery (same delivery id back to queued, one job, no new record, message unchanged);
- a second retry and a resubmitted batch (already queued; no job or record added);
- repeated scheduling failure, three retries (still unscheduled, no job, no new record);
- simultaneous retries (lock held → busy, nothing scheduled; requeue compare-and-set refuses a second requeue; queued-without-job scheduled once, then seen as queued; a racing schedule call finds the existing job and is not marked failed);
- lost responses (after scheduling: already queued; after saving but before scheduling: recovered once);
- processing, sent and attempted-failure messages (never reset, requeued or resent; no job);
- one mixed retry with five different outcomes and exact totals;
- permission, agency scope and email readiness re-checked on recovery;
- source checks that the real repository SQL carries the same compare-and-set guards and the lock.

`node tests/browser-redesign-bulk.cjs` (24): the first scheduling attempt for one recipient fails, then a retry fails again, then a retry recovers it. The dialog's states and totals are checked at each step against the stored records and jobs.

**Limits.** The tests run against an in-memory stand-in for the database; the real SQL is checked by inspection and source assertions, not executed against MySQL here. WordPress cron itself is not transactional: if a scheduling call succeeds but its option write is lost, the delivery stays queued without a job until the next bulk retry or the hourly maintenance job schedules it; the worker's compare-and-set claim keeps that from ever sending twice. A message whose worker was interrupted mid-send is deliberately not resent automatically.

## Rollback

Uncheck the redesign option: the legacy screens return unchanged (the export handler behaves as before when no selection is posted). Or reinstall the build Akansoh staging runs, `Gridhouse-admin-compliance-dashboard-1.7.3-rc.8-51d8679.zip` (installed and checked November 7; see `docs/staging-builds.md`). `ce36016` (Slice 5) was never installed. No schema change; bulk reminders write the same communication records a single reminder writes.

## Package

| | |
| --- | --- |
| File | `Gridhouse-admin-compliance-dashboard-1.7.3-rc.8-63c3287.zip` |
| Source commit | `63c3287` (branch `gridhouse-overview-odp-bulletins-af45`); full hash in `BUILD.txt` |
| Byte size | 675,164 |
| SHA-256 | `0943cb0c7fe18fca81a2069ec340077123cced600d224f642cce54bfbed3915f` |
| Root folder / entry file | `Gridhouse-admin-compliance-dashboard-plugin-main/gridhouse-admin-compliance-dashboard.php` (same as staging; updates in place) |
| Contents | Cumulative Slices 1-6 on top of 1.7.3-rc.8. 142 runtime files, each byte-identical to the commit; `BUILD.txt` inside lists every file's SHA-256 |
| Checks on the archive | `unzip -t` clean; every file compared with `git show 63c3287:<path>`; `php -l` on every PHP file |

Downloaded and integrity-verified by the reviewer; not installed. Superseded by the bulk-retry fix build below, which should be installed instead. Older builds are kept unchanged.
