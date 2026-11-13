# Dashboard redesign, phase 2, slice 9: certificate viewer, certificate selection and ZIP, course listbox, Title Case, routing, Overview

Opt-in, behind `ghca_acd_ui_redesign`, like every slice. This continues from `dea0da8`, which the site owner installed on Akansoh staging on November 7, 2026 at approximately 10:48 PM Toronto time, replacing `94b612d`. All Slice 1–8 behaviour is preserved, and every earlier test suite still passes; a few older tests were updated where this slice deliberately changed the markup (see Tests).

With the redesign off, the legacy screens are unchanged. This was checked on the real disposable site: no viewer, no selection bar and no listbox are rendered, the legacy dashboard loads, and there are no script errors. Unchanged in this slice:
- the outbound guard;
- bulletin publishing;
- site fonts and theme settings;
- training records (nothing writes to them);
- certificate generation (no certificate is generated or changed);
- the employee `/cert-download/` page (left for the later employee-dashboard redesign).

**Design source:** the "Certificate viewer states + phone" design, and the Certificates, Reports and Overview boards used in earlier slices.

**Screenshots:** the screenshots attached to the request, including the annotated Overview screenshot, were **not visible** in this session. The Overview proportions and the picker fix were therefore made from the written description, the boards and browser measurements. Visual agreement with those screenshots is not claimed.

## 1. User training report picker

- The search icon sits at the start of the field and the chevron at the end (`gh-i--end`). The input has padding on both sides, so text never runs under either icon.
- Measured in the browser at 1440, 834 and 390, and on the real themes: the icons are separate (more than 40px apart) and inside the field.
- Open, selected, loading, empty and error states, keyboard operation (arrows, Enter, Escape) and the full-width phone layout are covered by `browser-slice9`.
- The employee scope checks and the stale-response protection from Slice 8 are unchanged, and are re-tested.

## 2. Certificate viewer

One viewer per board page, opened from:
- the Certificates tab **PDF** button;
- the drawer's completed-course **Certificate** buttons;
- the inline report's course **Certificate** buttons;
- **Status** on Missing rows (the pending state).

How it works:
- **Fetching:** the PDF is fetched through the existing authorized endpoint (`GHCA_ACD_Certificate_Download`: nonce, document permission, employee scope, LearnDash broker). It is shown from a `blob:` URL in an iframe. No public URL is created and no third-party viewer is used.
- **Download:** a separate, explicit button. Previewing never downloads.
- **States:**

  | State | When | What the viewer offers |
  | --- | --- | --- |
  | Loading | While fetching | Spinner |
  | Ready | A verified PDF arrived | The PDF, with page, zoom, fit, print, Download and Open in new tab |
  | Preview not supported | `navigator.pdfViewerEnabled === false` | Explains and offers Download. Nothing downloads on its own. |
  | Unavailable | 404 | No file exists |
  | Not allowed | 403 or 400 | Not available to this viewer |
  | Error | Network or server error | Try again |
  | Pending issuance | Missing rows | Explained, with Open employee. Nothing is fetched. |

- **Phone:** full-screen sheet with the details below the page and 48px Download and Open in new tab buttons. Tablet: one column.
- **Keyboard and focus:** focus is trapped, Escape closes, and the close button is visible. Focus returns to the button that opened the viewer. The blob URL is revoked on close.
- **Toolbar:** page and zoom work through PDF-viewer URL fragments (`#page=`, `zoom=`, `view=FitH`). These work in Chromium and Firefox and only partly in Safari. Print uses the frame's print, with a message if the browser blocks it.
- **Fit to width (corrected after `70e1d6b`):** these findings come from Chromium's real PDF viewer (see Tests). Firefox, Safari and Edge were not tested.
  - **Opens in Fit.** The viewer used to open at 100%, which shows only the left part of a landscape certificate. It now opens in Fit to width.
  - **Chrome's own panels are hidden** (`navpanes=0&toolbar=0`). Chrome's thumbnail sidebar took about half of the frame's width, so even Fit showed a small page. Its toolbar is hidden too, because this viewer has its own page, zoom, Fit and print controls.
  - **Fit uses two parameters:** `view=FitH` for Chromium and `zoom=page-width` for Firefox's viewer. Chromium was checked to still fit with both present.
  - **Every change loads a fresh frame.** Chromium ignores a change to only the fragment of a PDF that is already loaded, so the Fit and zoom buttons previously did nothing there.
  - **The frame takes the page's shape.** In Fit the frame is as tall as the page at the frame's width (from the PDF's MediaBox; rotation is respected), up to the stage's maximum, so a landscape certificate is visible whole. Taller pages scroll vertically, never sideways. The height is set after the loading panel has gone, and kept right when the stage changes width.
  - **Zoom steps start from Fit.** Zooming in or out from Fit steps in 25% increments from the size the page is currently shown at.
- **Not built** from the design: Rotate and the Certificate ID line. There is no stored certificate ID to show.

## 3. Certificates tab

- **Source column:** filled from the actual data. Every row in this list comes from LearnDash, so Source reads "LearnDash" and no source filter is offered. See Decisions.
- **Selection:**
  - a checkbox per row (and per card below 1024px);
  - a header checkbox that selects the current page only;
  - Clear selection;
  - a visible count.
- **Selections outside the current view:** a selection survives paging and filtering. When some selected certificates are not in the current view, the bar names them ("2 not in the current view: …"), and **Keep only those shown** drops them. Nothing hidden is included without being listed.
- **Download selected as ZIP** (`ghca_acd_cert_zip`):
  - the bar shows preparation, success (the browser downloads the archive) and failure messages;
  - every item is re-authorized on the server: document permission and `can_view_user` for that employee;
  - if any item is outside the viewer's scope, the whole request is refused with a count only. No names are leaked and nothing is fetched;
  - each PDF is fetched through the same broker as the viewer, and only verified PDFs are added;
  - if any certificate cannot be fetched, no archive is made, and the failed items are named. A partial archive is never produced;
  - limits: 20 certificates per archive and 100 MB in total.
- **Archive writing (corrected build):** every `addFromString()` result and the `close()` result are checked. The finished archive is then reopened with ZipArchive's consistency check; its entry count must match, and every entry must hold exactly the bytes that were added. On any failure the incomplete archive and any temporary file are deleted, no download record is stored, and the user gets an error ("could not be written" or "failed its check… try again").
- **Archive storage:** stored in the plugin's private folder (`cert_zips`), outside the web root: folder 0700, file 0600.
- **Download claim (corrected build):** single use, owner only, and nonce-checked.
  - **Where the record lives:** each ZIP has its own row in the options table, not autoloaded. It is read and written with `$wpdb` directly, not as a transient: a transient never reaches the database when a persistent object cache is installed, and neither offers an atomic claim.
  - **Claiming:** the download claims the row with a compare-and-swap `UPDATE … WHERE option_value = <the unclaimed value>`. The database lets exactly one simultaneous request change it; every other request gets "no longer available" (410).
  - **Owner binding:** the claim happens only after checking that the requester built the ZIP. A request by anyone else is answered like a missing ZIP and leaves it unclaimed for its owner. The claimed record stores who claimed it and when.
  - **Database errors:** if the database cannot answer the lookup or the claim, the user gets "try the download again" (503) and the ZIP is not consumed. A failed lookup is kept distinct from "missing" and "expired", which stay 410 (corrected again after `84e9985`, where a failed lookup looked like a missing ZIP).
- **Failed reads (corrected build):** after the claim, the file must be read completely: the length must equal the file size and the content must start as a ZIP. Otherwise the answer is 500 with a JSON message ("could not be read… prepare it again"), never an empty or partial 200. The claimed file and record are deleted. The page shows that message.
- **Expiry and cleanup (corrected build):** access expiry and physical deletion are separate.
  - **Access expiry:** a ZIP can be downloaded for 15 minutes after it is built. This is checked at download time, whatever cron has or has not done.
  - **Physical deletion:**
    - immediately after a download, or a failed read;
    - by a WP-Cron single event per ZIP, due 5 minutes after its access expiry;
    - by an hourly WP-Cron backstop, scheduled while any ZIP exists and removed when none remain;
    - by a fallback at the end of any request (`shutdown`) once a cleanup is more than 10 minutes overdue. This covers failed scheduling (logged to the PHP error log), `DISABLE_WP_CRON` without a system cron, and late cron;
    - before every new ZIP is built;
    - when the plugin is deactivated (everything).
  - **What cleanup removes:** expired records and their files, and claims that never finished after 5 minutes. It also removes ZIP and ZipArchive temporary files with no record once they are older than 20 minutes, judged by age, so a ZIP being written is never touched. It never touches the folder's deny files or anything else.
  - **Deletion outcomes (corrected again after `84e9985`):**
    - **Confirming deletions:** every file deletion is confirmed afterwards (the file must be gone), and every record deletion must be accepted by the database. A file or record that is already gone counts as success. Only confirmed removals are counted.
    - **Keeping the record:** if a ZIP's file cannot be deleted, its record is kept, so the next run retries through it. If a record cannot be deleted, it stays.
    - **Records or folder unreadable:** the run fails. If the records cannot be read, no file is deleted by age, because it is unknown which files belong to a live ZIP.
    - **Retry state:** after any failure, the due marker is kept (set for a retry in 5 minutes) and the hourly backstop is scheduled again if it was missing. Both are cleared only by a run that completes with no failures and finds nothing left.
    - **After a download or an expired request:** the file is removed first, then the record. If either fails, the claimed record stays (it can no longer be downloaded) and a retry is kept due.
  - **WP-Cron timing:** WP-Cron is not a clock. Events run only when someone loads a page, or when a system cron calls `wp-cron.php`, so "due at" means "no earlier than". On a quiet site, or with `DISABLE_WP_CRON` and no system cron, files can stay on disk longer, until the next request runs the fallback. They are unreachable meanwhile: the private folder's deny rules block them, and the download refuses expired ZIPs. For predictable deletion, staging should call `wp-cron.php` from a system cron (every 5 minutes is plenty).
- **File names:** readable and distinct, `employee_course.pdf`, with `-2` and so on for clashes. The archive is named `certificates-YYYY-MM-DD-HHMMSS-N.zip`.
- **PHP zip extension:** if it is missing, selection is disabled and a note says ZIP downloads are not available on this site, so there are no decorative controls.
- **Row actions:** PDF opens the viewer; the download icon next to it downloads (unchanged endpoint).

## 4. Course dropdowns

Every course `select` on the redesigned dashboard is enhanced into an accessible, searchable listbox. This covers:
- Certificates;
- Employees;
- the drawer's lesson catalog (with a "Filter lessons" search);

How it behaves:
- **Layout:** the popup is constrained to the viewport (280–480px wide, never wider than the screen minus 32px), and long titles wrap.
- **Keyboard:** type to filter, arrows, Home, End, Enter, Escape. `aria-activedescendant` points to the active option.
- **Native select kept:** the original `select` stays in the form, hidden, and still holds the value and fires `change`. Filtering, form posts, chips and required-field validation behave as before. A chip that clears the value updates the button label.
- **Without scripts:** the native select is used.

## 5. Title Case course names

- `GHCA_ACD_Course_Title::format()` is a deliberate formatter, not CSS. It is used for course names in admin lists, selectors, the drawer, Edit Records and the inline report, with the redesign on only.
- **Kept as written:**
  - acronyms and codes: a built-in list, filterable with `ghca_acd_course_title_acronyms`;
  - words with digits;
  - Roman numerals;
  - mixed-case words such as "eLearning".
- Short words (a, and, of, the, …) are lowercased except at the start of the title or a clause.
- It is presentation only: stored titles are not changed. The legacy drawer keeps its old display.

## 6. Routing

- **Reports → View certificates** (Certificate records card) opens the dashboard Certificates tab (`#ghca-tab-certificates`), not `/cert-download/`.
- **Employee-specific actions** open the same tab filtered to that employee, with an "Employee: Name" chip. This covers the report's **Certificates (N)**.
  - The filter is the `ghca_cert_user` field. The server ignores an employee outside the viewer's scope.
  - A direct link (`?ghca_cert_user=ID#ghca-tab-certificates`) works without scripts.
- **General entry points** show the full list with no stale employee filter. These are Overview "View all", the Reports card, and the Certificates tab itself, chosen by click (also when it is already showing one employee's certificates) or with the arrow keys. Removing the chip also clears the filter.
- The employee `/cert-download/` page was not implemented or changed.

## 7. Overview and container

- **Card sizes and proportions are the reference board's again (corrected after `3ce13be`, requested).** The Slice 9 widening of Announcements was reverted. The grid is the board's `minmax(280px, 320px) minmax(0, 1fr) minmax(300px, 360px)`, with the board's stacking at 1240px and 900px.
  - Measured on the real site: 320 / 704 / 360 px at 1920, 320 / 664 / 360 at 1440, and 320 / 520 / 360 at 1280.
  - Two columns at 1100 (Announcements full width below), one column at 834 and 390.
  - The stacked group-row layout added for the narrow column is gone. Rows use the board's one-line layout.
- **List caps (requested):**
  - **What is visible:** Compliance by group shows 6 rows and Announcements shows 3 notes. Longer lists scroll inside the card body; the card headings (title, subtitle, Course detail, Add note) stay fixed.
  - **How the cap is set:** `dashboard.js` measures the items and sets the body's height, so exactly 6 rows or 3 notes are visible even when names or messages wrap.
  - **When it is re-measured:** when the width changes, the layout switches or the Overview tab becomes visible, and when the announcements list is re-rendered after an add, edit or delete.
  - **Short lists** are not capped and are not scroll regions.
  - **Nothing is dropped:** all groups and up to 10 announcements are still rendered (unchanged).
  - **Scrollbars:** thin and semi-transparent in the agency colour (`--gh-agency`, falling back to the theme primary): 45% opacity, 70% on hover. `scrollbar-color` covers Chromium and Firefox; `::-webkit-scrollbar` covers Safari.
  - **Keyboard and screen readers:** a capped list is a keyboard-focusable region with a label ("Compliance by group, scrollable list"), with a visible focus outline.
  - **The ⋯ menu** of an announcement opens upward when it would be cut off at the bottom of the list.
  - The phone layout keeps the same caps.
- **KPI strip (requested: "a little height"):** on desktop and tablet the tiles have 22px top and 24px bottom padding and an 8px gap, up from 16px, 18px and 4px. At 1440 they are 133px tall. The phone keeps its compact 12px tiles.
- **Equal card heights on desktop (corrected after `74a0a3d`, requested):** the Announcements card is again exactly as tall as Compliance by group.
  - **The shrink-to-content rule from `74a0a3d` is removed,** so the card stretches with the row as the board intends.
  - **How the caps and equal heights work together:** each list first takes its base cap (6 group rows, 3 notes) and the row height follows from that. Where the row then makes a card taller than its list, the list shows more **whole** items, as many as fit. It never goes past the room the card already has, so the row does not grow.
  - **The trade-off:** nothing is clipped, nothing of a next item shows, and no padding is added. When a list overflows, the space left under it is always smaller than the next item, because a taller next note does not fit.
    - On the test site at 1440, with 8 groups and 6 notes, both cards are 520 px. They show 7 whole group rows (11 px left over) and 3 whole notes (109 px left over: the fourth note is taller than that).
    - Equal heights with exactly whole items in both lists is not possible in general, because items differ in height. This is the version without clipping.
  - **Stacked tablet and phone layouts** do not stretch cards, so they keep exactly 6 rows and 3 notes. Short lists are shown whole and are not scroll regions.
  - **Unchanged:** headings stay fixed, only the list bodies scroll, and the agency-coloured scrollbars and the keyboard regions are as before.
- **Long group names:** names wrap in full instead of being cut off with an ellipsis. Long unbroken words break only as a last resort. In the reference layout, a 112-character name takes 3 lines at 1920, 4 at 1440 and 5 at 1280. Bar, % and status stay inside the card.
- **Long announcements** wrap inside the card, and the ⋯ control stays usable.
- **Container:** 1480px wide, as requested mid-slice; unchanged.
- **Certificate viewer:** unchanged by this adjustment.

## 8. Drawer Documents tab: PDF previews in the redesigned viewer (after `10161f1`, requested)

- **Which previews:** in the redesigned dashboard, Preview on a PDF opens the certificate viewer. This covers both a Jotform submission and an outside certificate entered by hand, from the drawer's Documents tab or its "View all documents" list.
- **Access is unchanged.** It uses the same short-lived, single-use grants from the same endpoints, with the server's checks and audit events unchanged (`ghca_acd_jotform_document_token`, `ghca_acd_jotform_manual_token`).
  - **Preview:** the viewer asks for one preview grant and fetches the file once, which the server audits as a preview. The file is shown only if it really is a PDF. Try again asks for a fresh preview grant, because a used grant is never reused.
  - **Download:** the viewer's Download asks for its own download grant when pressed, and the server sends the file as an attachment and audits it as a download. The preview bytes are not reused for it, so preview and download stay separate in the audit trail as before.
  - **The list's own Download** is unchanged.
- **What the viewer shows for a document:** the heading "Document · name", the review status as the chip, and "Document details" with employee, form, date, review status, source ("Jotform submission" or "Outside certificate (manual entry)") and file facts. The note says Download is a separate, recorded download.
- **Errors:** a refused grant or an unreadable file shows the viewer's error state, with Try again, instead of a browser alert.
- **Not changed:**
  - non-PDF files (images) keep their existing preview;
  - the legacy dashboard (redesign off) has no redesigned viewer, so its previews are unchanged too.
- **Stacking:** the viewer now uses the certificate overlay's level in the dashboard's overlay ladder (100006: above the documents list at 100005, below toasts), so it opens above "View all documents". Escape closes only the viewer.
- **Escaping:** values placed in attributes of the client-rendered list are attribute-escaped, quotes included.

## 9. ODP ticker for an empty feed (after `74a0a3d`, requested)

- **The problem on Isoke:** Isoke's cached feed holds zero bulletins, and `GHCA_ODP_UI::ticker()` returned no HTML for that "empty" state, so the ticker disappeared.
- **The fix:** it now stays on screen with "No ODP bulletins right now." as a status message, with the "ODP Bulletin" label and the "All bulletins" link, and no track or pause control. A feed with no current items and no other state is treated the same way.
- **Unchanged:**
  - **Other states:** unavailable, cached and offline keep their own messages, and a populated feed renders exactly as before.
  - **Content:** no example bulletins are added and nothing is published.
  - **The feed and access:** the feed endpoint and the access checks are untouched.
- `data-gh-ticker-state` (`items`, `empty`, `unavailable`, `cached`, `offline`) marks the state for tests.

## 10. Review follow-ups (after `3628587`)

Found in a review of the announcements code:
- **Removed the dormant BuddyBoss notification code.** `process_buddyboss_notifications()` and `format_buddypress_notifications()`, with their commented-out hooks and `do_action`, are gone.
  - **Why it mattered:** it was switched off, but it would have notified every user with the `caregiver` or `nurse` role about every new announcement, whatever its audience ("Admins only" and group announcements included). Its link also ignored the configured employee-dashboard page.
  - **No behaviour change:** nothing called it.
- **The confirmation after a legacy-form edit reports the real audience.** The legacy form sends no audience, and the stored audience was correctly kept. But the success message ("Who sees it: …") was built from the default, so editing an "Admins only" or group announcement from that form said "Employees and admins". It now reports the stored audience.
- **Docs:** the employee plugin's repository is no longer described as unavailable. Its redesign was reported on `feature/dashboard-redesign` (not checked from here). The plan's "Slice 9 — Employee plugin" is renamed, because this repository's Slice 9 was the certificates work.
- **Tests:** `integration-slice8` grows from 36 to 40 checks:
  - a legacy-form edit of a group announcement reports "Group: …";
  - a legacy-form edit of an "Admins only" announcement stays Admins only and reports "Admins only";
  - a new legacy announcement reports "Employees and admins";
  - the notification code is gone.
  - Against the previous code, three of the new checks fail.

## 11. Audit packet print layout (after `9a92da1`, requested)

The Annual and Orientation packets now follow the design board "Print: Orientation audit packet". The OLTL packet is unchanged. The new layout is in `includes/class-audit-packet-layout.php`; `GHCA_Audit_PDF::render_cover()` hands Annual and Orientation to it.

**Page 1**
- **Header:** the agency logo, or a monogram in the agency colour when there is no logo; the agency name; "ODP orientation audit packet" or "ODP annual audit packet" ("… reporting period" for a custom period). On the right: when it was generated, by whom, and "page 1 of N". N counts the summary pages only; certificates are numbered separately.
- **Employee:** name, role and group. There is no employee ID in the data, so none is printed.
- **Summary box, Orientation:**
  - Verified start, with "Checked by {name}, {date}" from the employment record.
  - Orientation completed.
  - Days to complete: "21 of 30", or "Day N of 30, not complete".
  - 30-day verdict: Within 30 days / Over 30 days / Not completed within 30 days / Not complete / Missing evidence.
- **Summary box, Annual:**
  - Annual window, with the cycle rule.
  - Recorded hours "of 24".
  - 6100 topic hours.
  - Overall annual status. This includes the existing split ISP summary, which still never reads as compliant.
- **Summary box, custom reporting period:** the period, the recorded hours and the 6100 topic hours. The result reads "Evidence report only — no compliance verdict", with no threshold.
- **Areas table:** the same areas as before.
  - Status is a symbol plus its word, and any recorded note (e.g. a not-applicable reason) is printed under it.
  - The third column is the completion date (Orientation) or the hours counted (Annual).
  - Evidence names the courses for that area, with their attachment numbers.
  - Agency-mandated rows, the ISP notes, the evidence-review list and the existing disclaimers are kept word for word.
- **Key dates, Orientation:**
  - date of hire;
  - completion date;
  - the 30-day answer;
  - first service date;
  - worked-alone date, with "on/after" or "before orientation completion";
  - the before-working-alone answer;
  - "Account registered (not used for audit)".
  - The new-hire program notes are kept.
- **Hours and dates, Annual:** the existing hours lines, plus the date of hire and the first service date.
- **Status footnote:** "Every status prints as a symbol plus its word … Colour is not needed to read this document."

**Following pages**
- **Running header:** agency · employee, "page n of N".
- **Course records:** course, status, completed, time (Orientation) or hours (Annual), and certificate. Outside training is marked. Outstanding or undated new-hire program courses are listed as not complete or "No recorded date".
- **Gaps found:** every open item from page 1. That means evidence issues, areas that are not Completed or Not applicable, agency rows without evidence, the overall annual status, the new-hire program, and the 30-day and working-alone answers. "None. All orientation areas complete and dates verified." prints only when there is nothing open. Annual uses its own wording.
- **Attached certificates:** numbered A-1, A-2 … in the exact order the merge appends them. Each appended certificate page carries a small "Attachment A-n · {employee}" label in its top 6 mm.
- **Included Course Details:** when the existing setting is on.
- **Signature block:** "Reviewed by (name, title)" and "Date".

**Other details**
- **Paper:** Annual and Orientation summary pages are US Letter, as the board specifies. OLTL keeps the library default. Course detail pages (the existing per-course pages) are unchanged and still A4.
- **Status symbols:** use TCPDF's DejaVu Sans when it is installed (it is in LearnDash's TCPDF), otherwise an ASCII mark.
- **Fonts:** Helvetica, as before (the board's web fonts are not available to TCPDF).
- **Unchanged:** the calculator, the packet snapshot check, certificate fetching and validation, grants, downloads, limits and the OLTL packet.
- **Tests:**
  - New `tests/integration-packet-layout.php` (39 checks, real TCPDF/FPDI, synthetic data; see Tests).
  - Unit tests that matched the old cover's wording or markup were updated to the new layout, keeping the same facts: date format, "Overall annual status", the orientation completion and 30-day rows, and source checks for hours/time and the escaped employee name. They pass.

## Decisions and dependencies to report

1. **Outside certificates:** approved outside certificates live in the external-evidence store, not in this list. Showing them here (with Source "Outside" and a source filter) needs a decision on scope and on whether they belong in ZIPs. Until then Source is LearnDash only.
2. **Board extras not built:** "Download all (ZIP)", Rotate and Certificate ID. Download all needs a decision on limits; there is no certificate ID in the data.
3. **ZIP limit and dependency:** the 20-per-archive limit can be raised if needed. It needs the PHP `zip` extension on staging; if it is missing, the note appears instead of the controls.
4. **Overview proportions:** restored to the reference board on request (the earlier Slice 9 widening of Announcements was my judgment from a description; the annotated screenshot was not visible).

## Tests

### Synthetic: plugin preview pages with canned AJAX (not WordPress, not staging)

| Suite | Result | Covers |
| --- | --- | --- |
| `tests/browser-slice9.cjs` (new) | 112 checks | Certificates columns, Source and Title Case. Viewer: preview versus download, toolbar, focus trap and restore, Escape, phone sheet and tablet layout. States: blocked (nothing downloaded on its own), unavailable, denied, error with retry, pending. Selection across paging and filtering, hidden-selection notice, Keep only those shown, Clear. ZIP preparation, success and failure messages. Course listbox at 1440, 834 and 390: wrapping, viewport bounds, keyboard, filtering, chips. Report picker icons, states and keyboard. Title Case in the drawer and Edit Records. |
| Updated suites | lists 51, tables 54, drawer 159, slice8 182 | Markup changes: listbox button in tab order, viewer trigger, Source and select columns |
| Other browser suites | slice7 150, bulk 24, admin 20, drawer access 9 | Earlier slices |
| All `tests/test-*.php` and `tests/test-*.js` | 82 files, all pass | Includes the new `test-course-title.php` (23 checks) |

**Simulated, then real:**
- **The main viewer checks are simulated.** They run in Chromium's headless shell, which has no PDF viewer, so the frame's source is recorded instead of rendered.
- **Fit to width is checked with real rendering.** Since `70e1d6b`, a section of the suite runs full Chromium in its new headless mode, which includes the built-in PDF viewer.
  - It reads the pixels the viewer actually renders: the synthetic PDFs have a red border on the page edges, and the test finds those border lines.
  - **Landscape page:** opens in Fit with all four edges inside the frame at 1440, 834 and 390. Zooming in really re-renders it larger, and Fit shows the whole page again.
  - **Portrait page:** its full width is shown.
- **Not covered by real rendering:** other browsers, and LearnDash's own certificate PDFs.
- **Mocked endpoints:** the ZIP and certificate endpoints in this suite are mocked; the real ones are tested below.

### Real WordPress 6.6.2 + MariaDB 10.11, disposable `ghca_test` site (not the staging theme, not staging)

The site uses the LearnDash stand-ins in `tests/real-site/`. They provide synthetic groups and leaders, and no real certificates.

| Test | Result | Covers |
| --- | --- | --- |
| `tests/integration-packet-layout.php` (new) | 39 checks | Real TCPDF/FPDI on the test site: Annual and Orientation packets for a synthetic employee from synthetic audit data, plus one appended synthetic certificate, read back with `pdftotext`/`pdfinfo`. Covers: US Letter; the header (agency, packet name, generated time, the user, "page 1 of N"); running headers on later pages; the employee block with no invented ID; symbol-plus-word statuses; the summary figures (21 of 30, Within 30 days, Checked by …; 15.5 of 24, Overall annual status); missing and not-applicable symbols with the recorded reason; area evidence with attachment numbers; worked-alone and registration rows; course time in h/min; gaps listed, or "None" only when nothing is open; attachments numbered in merge order; the certificate page stamped "Attachment A-1"; the signature block. |
| `tests/integration-slice9.php` (new) | 90 checks | ZIP building and private storage. Duplicate selections. Distinct and clashing file names. Archive name. Download refused without document access, with a bad nonce, with a malformed token, and for another administrator. Single use, owner-only. A fetch failure means no archive, with the failed items named. A non-PDF answer is a failure. The 20-item limit. Malformed selections. A real `group_leader` is refused for a selection with an out-of-scope certificate before any fetch, with a count only and no names, and can ZIP their own group's certificate. The preview endpoint refuses out-of-scope employees (403) and answers 404, never a file, when no certificate exists. The Certificates employee filter ignores out-of-scope ids. **Corrected build adds:**
  - **Archive failures:** a refused entry write, a refused close (half-written file), an entry silently missing and an entry with different bytes. Each gives an error, leaves no file (temporary files included) and no record, and is simulated with ZipArchive subclasses injected through a test-only seam.
  - **Read failures:** the read returns false, a short read and an empty read. Each gives a 500 JSON answer, never a 200; the file and record are removed and a retry gets 410. Simulated with an injected reader; a real disk read error was not produced.
  - **Expiry and cleanup:**
    - access expiry is not deletion;
    - the scheduled cleanup event removes an expired ZIP with no later build or download;
    - old abandoned archives and ZipArchive temporary files go, while young files, deny files and unrelated files stay;
    - an unfinished claim is cleaned up;
    - if WP-Cron refuses both events, the ZIP is still offered and the request-end fallback deletes it once overdue (not before);
    - with nothing left, the due time and every event are removed.
  - **Concurrency:** separate forked processes, each with its own database connection, start at the same instant. In each of 5 rounds, 8 simultaneous downloads (4 by the owner, 4 by another administrator) give exactly one 200, from the owner and with the complete archive; the other 7 get 410, and nothing is left afterwards. Six simultaneous requests by another administrator all get 410 and leave the ZIP unclaimed, and the owner can download it afterwards.
  - **Negative control:** with the claim made non-atomic on the test site's copy only, the concurrency checks fail in every round (several 200s). The test therefore detects the race it is meant to.
  - **Deletion failures (second correction):** these are real failures, not simulated. The immutable file flag makes the filesystem refuse `unlink` even for root, and database statements are pointed at a missing table, so MariaDB returns a real error.
    - **Failed unlink of an expired ZIP:** 0 files and 0 records are reported, and the file and record are kept. The retry is due within 5 minutes and the backstop is rescheduled. A fallback retry that fails again keeps that state. Once deletion works, the next run removes and counts both and only then clears the state.
    - **Failed unlink of an abandoned file:** the same pattern.
    - **Failed record deletion:** the file, confirmed gone, is counted and the record is not; the retry state is kept. The next run treats the already-absent file as success, deletes the record and clears the state.
    - **Unreadable records:** the run fails and deletes nothing, not even a ZIP that would be due, and keeps the state.
    - **Download, failed lookup:** a 503 "try again", not a 410. The ZIP is untouched, and a retry downloads it. A token with no record is still a 410.
    - **Download served, file deletion refused:** the complete archive is still served. The claimed record stays, the retry is kept, and a second download is refused. Cleanup removes the ZIP after the grace period.
    - **Download served, record deletion refused:** the claimed record stays and the retry is kept. The retry deletes it.
  - **Second negative control:** with file deletion made to report success without checking, on the test site's copy only, 12 of these checks fail. |
| `tests/browser-real-site9.cjs` under **Twenty Twenty-Four** | 59 checks | **Equal heights and list caps (after `74a0a3d`):** Compliance by group and Announcements share one height on desktop. Every visible item is whole, with no clipped or peeking item, and the space under a list is smaller than the next item, at 1920–390. A short announcement list is shown whole and not as a scroll region, still at equal height. **ODP ticker:** shown for a cached zero-bulletin feed ("No ODP bulletins right now.") and for no feed (unavailable), at 1440, 834 and 390. **Overview caps** (with `tests/real-site/overview-overflow.php`, which adds 5 synthetic groups and 5 announcements and removes them afterwards), at 1920, 1440, 1280, 1100, 834 and 390: exactly 6 group rows and 3 announcements visible; only the list body scrolls, to the last item; headings stay fixed; thin themed scrollbars; keyboard region; no sideways scrolling; reference column widths; KPI tile padding. The ⋯ menu of the last visible note is fully visible. Short lists are not capped. **Long group name:** a temporarily renamed group with a 112-character name, at 1920, 1440, 1280, 1100, 834 and 390. At each width it is shown in full on 3–5 lines, with no ellipsis and no clipping, and bar, % and status stay inside the card with no sideways scrolling. The reference column widths hold. The name is restored afterwards; this needs `GHCA_REAL_WP`. Container width at 1920. Overview columns at 1440 and 1280, and stacking at 1100, 834 and 390, with a long announcement (created and deleted again). Certificates routing and the employee filter: report action, Overview "View all", chip removal, the Certificates tab by click (also while filtered) and by arrow key (corrected build), direct link. Report picker icons at 1440 and 390. |
| Same under **Twenty Twenty-Two** | 59 checks | Same |
| `browser-real-site.cjs` (Slice 8) under both themes | 67 checks each | Regression |
| Other `integration-*.php` (slice8 36, packet-ready 29, bulk-recovery 29, …) | pass | Regression |
| Redesign off (legacy) on the real site | pass | Legacy dashboard loads with no viewer, selection bar or listbox, and no script errors |

### Not verified

- **Audit packet layout:** checked with real TCPDF and synthetic data only. Real LearnDash certificates, real employees, very long course lists, long agency names and other PDF viewers' rendering of the status symbols were not checked. No printer test.
- **Staging:** nothing ran on Akansoh staging, under its BuddyBoss/Elementor theme, or with real people.
- **LearnDash:** real LearnDash certificates were not used. The stand-ins have none, so the 200 path of the real endpoint, and real PDFs inside a real ZIP, were tested only with synthetic PDFs through the same code.
- **Inline PDF display:** verified only in Chromium's built-in viewer, with synthetic PDFs (see Simulated, then real). Firefox, Safari and Edge, and real LearnDash certificates, were not checked.
- **Browsers:** Chromium only. Safari and Firefox page/zoom fragments, and print, were not checked.
- **PHP zip on staging:** the extension's presence on staging is unknown.
- **Cron on staging:** whether staging runs WP-Cron from traffic or from a system cron is unknown; the fallback covers both, but deletion times depend on it.
- **Object cache:** no persistent object cache was installed on the test site. The claim uses the database directly and never the object cache, so it should not depend on one, but no test ran with one.
- **Database engines:** the concurrency test ran on MariaDB 10.11 with InnoDB only.

## Corrections after `a67c01d`

`a67c01d` was prepared and never installed. Review found these problems, fixed in the corrected build:
- **ZIP downloads were not atomically single use.** The record was a transient: read, then deleted. Two simultaneous requests could both pass the read and both be served.
- **Cleanup depended on a later ZIP build.** Abandoned files stayed until someone built another ZIP, and access expiry and deletion were the same step.
- **Archive results were not checked.** `addFromString()` results were ignored, and the archive was not checked after closing.
- **A failed file read could become an empty 200 download.** `(string) file_get_contents()` turned `false` into `''`.
- **Clicking the Certificates tab itself did not clear an employee filter**, although this document said it did.

## Corrections after `84e9985`

`84e9985` was prepared and never installed. Review found these problems:
- **`discard()` hid deletion failures.** It suppressed them, and `cleanup()` counted them as removed. It could also clear the due marker and the scheduled backstop while files or records were still there.
- **Failed lookups looked like missing ZIPs.** A failed database lookup at download was answered like a missing or expired ZIP (410) instead of a retryable error.
- **Unreadable records led to age-based deletion.** If the records could not be read, the age-based scan could have treated every ZIP file as abandoned.

All three are fixed as described under "Deletion outcomes" and "Database errors".

## Package

| | |
| --- | --- |
| File | `Gridhouse-admin-compliance-dashboard-1.7.3-rc.8-9a92da1.zip` (root folder `Gridhouse-admin-compliance-dashboard-plugin-main/`) |
| Commit | `9a92da10db48386d570869cfa2ead7d576312537` |
| Size | 741,965 bytes |
| SHA-256 | `ac8c1dd719642cccc2f3f31ac68504db76f6af2a3f4046ab785f48f2e29945f1` |
| External BUILD.txt SHA-256 | `06cca0222140c0573600889682937c091061e1d2a149f83acabf30daa38b3844` |
| Manifest | `BUILD.txt`, inside the archive and alongside it: all 145 runtime files with their SHA-256 |
| Supersedes | `3628587` (prepared, not installed) |
| Deployed builds | Isoke: `74a0a3d`. Akansoh staging: `10161f1`. See `docs/staging-builds.md`. |

**Verification (counts measured before BUILD.txt was written):**
- `unzip -t` reports no errors.
- Every packaged file matches the commit byte for byte and the files tested on the disposable site. Every hash is in the manifest. The BUILD.txt inside matches the one alongside.
- `php -l` passes on every PHP file.
- No tests or test stubs are in the package.
- The disposable site, running these exact files, passed:
  - `integration-slice8` (40 checks) and `integration-slice9` (90 checks);
  - `integration-packet-ready` (29), `integration-bulk-recovery` (29) and the other integration suites;
  - `browser-real-site9` (59 checks) and `browser-real-site` (67 checks), each under Twenty Twenty-Four and under Twenty Twenty-Two.
- At this commit:
  - every synthetic browser suite passes;
  - all 83 unit test files pass.

**Not installed anywhere.**

## Rollback

Install `dea0da8`. The ZIP feature adds no schema or post meta. While ZIPs exist it uses:
- one options row per ZIP (`ghca_acd_cert_zip_<token>`);
- the autoloaded `ghca_acd_certzip_cleanup_due` option;
- the `ghca_acd_cert_zip_cleanup` cron events.

All of these are removed when no ZIP remains, and by deactivation. An older build leaves any remaining rows and files untouched. Its own code ignores the rows, and the files are unreachable behind the private folder's deny rules. To remove them, deactivate this build before installing the older one.
