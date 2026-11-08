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

- At 1241px and wider the Overview grid is `minmax(280px, 320px) minmax(360px, 1fr) minmax(360px, 1.15fr)`. The left column is unchanged; Compliance by group is narrower and Announcements gets the width.
  - Measured: 320 / 476 / 548 px at 1440, and 320 / 409 / 471 px at 1280.
- Stacking is unchanged: two columns with Announcements full width below at 1100, and one column at 834 and 390. Long announcements wrap inside the card, and the ⋯ control stays usable.
- **Container:** reduced from 1600px to 1480px, as requested mid-slice ("too wide, reduce it a bit"). It is still wider than the board's 1320px.

## Decisions and dependencies to report

1. **Outside certificates:** approved outside certificates live in the external-evidence store, not in this list. Showing them here (with Source "Outside" and a source filter) needs a decision on scope and on whether they belong in ZIPs. Until then Source is LearnDash only.
2. **Board extras not built:** "Download all (ZIP)", Rotate and Certificate ID. Download all needs a decision on limits; there is no certificate ID in the data.
3. **ZIP limit and dependency:** the 20-per-archive limit can be raised if needed. It needs the PHP `zip` extension on staging; if it is missing, the note appears instead of the controls.
4. **Overview proportions:** these are my judgment from the description, because the annotated screenshot was not visible.

## Tests

### Synthetic: plugin preview pages with canned AJAX (not WordPress, not staging)

| Suite | Result | Covers |
| --- | --- | --- |
| `tests/browser-slice9.cjs` (new) | 104 checks | Certificates columns, Source and Title Case. Viewer: preview versus download, toolbar, focus trap and restore, Escape, phone sheet and tablet layout. States: blocked (nothing downloaded on its own), unavailable, denied, error with retry, pending. Selection across paging and filtering, hidden-selection notice, Keep only those shown, Clear. ZIP preparation, success and failure messages. Course listbox at 1440, 834 and 390: wrapping, viewport bounds, keyboard, filtering, chips. Report picker icons, states and keyboard. Title Case in the drawer and Edit Records. |
| Updated suites | lists 51, tables 54, drawer 159, slice8 182 | Markup changes: listbox button in tab order, viewer trigger, Source and select columns |
| Other browser suites | slice7 150, bulk 24, admin 20, drawer access 9 | Earlier slices |
| All `tests/test-*.php` and `tests/test-*.js` | 82 files, all pass | Includes the new `test-course-title.php` (23 checks) |

**Simulated in the browser suite:** headless Chromium has no PDF viewer, so it downloads a PDF placed in an iframe instead of showing it. The "ready" display is therefore simulated: the test replaces the frame's source after the real fetch and state change. **Real inline PDF rendering was not verified in any browser.** The ZIP and certificate endpoints in this suite are mocked; the real ones are tested below.

### Real WordPress 6.6.2 + MariaDB 10.11, disposable `ghca_test` site (not the staging theme, not staging)

The site uses the LearnDash stand-ins in `tests/real-site/`. They provide synthetic groups and leaders, and no real certificates.

| Test | Result | Covers |
| --- | --- | --- |
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
| `tests/browser-real-site9.cjs` under **Twenty Twenty-Four** | 23 checks | Container width at 1920. Overview columns at 1440 and 1280, and stacking at 1100, 834 and 390, with a long announcement (created and deleted again). Certificates routing and the employee filter: report action, Overview "View all", chip removal, the Certificates tab by click (also while filtered) and by arrow key (corrected build), direct link. Report picker icons at 1440 and 390. |
| Same under **Twenty Twenty-Two** | 23 checks | Same |
| `browser-real-site.cjs` (Slice 8) under both themes | 67 checks each | Regression |
| Other `integration-*.php` (slice8 36, packet-ready 29, bulk-recovery 29, …) | pass | Regression |
| Redesign off (legacy) on the real site | pass | Legacy dashboard loads with no viewer, selection bar or listbox, and no script errors |

### Not verified

- **Staging:** nothing ran on Akansoh staging, under its BuddyBoss/Elementor theme, or with real people.
- **LearnDash:** real LearnDash certificates were not used. The stand-ins have none, so the 200 path of the real endpoint, and real PDFs inside a real ZIP, were tested only with synthetic PDFs through the same code.
- **Inline PDF display:** not verified in any browser (see Simulated).
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
| File | `Gridhouse-admin-compliance-dashboard-1.7.3-rc.8-70e1d6b.zip` (root folder `Gridhouse-admin-compliance-dashboard-plugin-main/`) |
| Commit | `70e1d6b28db0c5749ca34a8788e2aca8212a317b` |
| Size | 736,962 bytes |
| SHA-256 | `35e23f2838517b1d2b399e572cb294928a490acd36a0ca3e103b27080bf174ff` |
| External BUILD.txt SHA-256 | `ebf2c012c2db9180e93291e705bb97d0f17049c1cfdad5d5968cd7d0a48a3741` |
| Manifest | `BUILD.txt`, inside the archive and alongside it: all 145 runtime files with their SHA-256 |
| Supersedes | `84e9985` and `a67c01d` (both prepared, never installed). Do not install either. |
| Previous staging | `dea0da8`, installed by the site owner on November 7, 2026 at approximately 10:48 PM Toronto time, replacing `94b612d` |

**Verification:**
- `unzip -t` reports no errors.
- Every packaged file matches the commit byte for byte, and its hash is in the manifest. The BUILD.txt inside matches the one alongside.
- `php -l` passes on every PHP file.
- No tests or test stubs are in the package.
- The extracted package was installed on the disposable site, where it passed:
  - `integration-slice9` (90 checks) and `integration-slice8` (36 checks);
  - `integration-packet-ready` (29), `integration-bulk-recovery` (29) and the other integration suites;
  - `browser-real-site9` (23 checks) and `browser-real-site` (67 checks), each under Twenty Twenty-Four and under Twenty Twenty-Two.
- Before packaging, at the working tree that became this commit:
  - all 82 unit test files pass;
  - `browser-slice9` (104), `browser-redesign-lists` (51) and `browser-slice8` (182) pass.
  - The page script is unchanged since `84e9985`, where every synthetic browser suite passed.
- `GHCA_ACD_Redesign_Certs::deactivate()` has no test of its own.

**Not installed on staging.**

## Rollback

Install `dea0da8`. The ZIP feature adds no schema or post meta. While ZIPs exist it uses:
- one options row per ZIP (`ghca_acd_cert_zip_<token>`);
- the autoloaded `ghca_acd_certzip_cleanup_due` option;
- the `ghca_acd_cert_zip_cleanup` cron events.

All of these are removed when no ZIP remains, and by deactivation. An older build leaves any remaining rows and files untouched. Its own code ignores the rows, and the files are unreachable behind the private folder's deny rules. To remove them, deactivate this build before installing the older one.
