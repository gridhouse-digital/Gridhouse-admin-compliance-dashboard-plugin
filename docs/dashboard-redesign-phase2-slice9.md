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
- **Archive storage:**
  - stored in the plugin's private folder (`cert_zips`), outside the web root: folder 0700, file 0600;
  - the download (`ghca_acd_cert_zip_download`) is single use, bound to the user who built it, and needs a nonce;
  - archives expire after 15 minutes, and abandoned ones are swept the next time a ZIP is built.
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
- **General entry points** show the full list with no stale employee filter. These are Overview "View all", the Reports card and the tab itself. Removing the chip also clears the filter.
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
| `tests/integration-slice9.php` (new) | 32 checks | ZIP building and private storage. Duplicate selections. Distinct and clashing file names. Archive name. Download refused without document access, with a bad nonce, with a malformed token, and for another administrator. Single use, expiry and sweep. A fetch failure means no archive, with the failed items named. A non-PDF answer is a failure. The 20-item limit. Malformed selections. A real `group_leader` is refused for a selection with an out-of-scope certificate before any fetch, with a count only and no names, and can ZIP their own group's certificate. The preview endpoint refuses out-of-scope employees (403) and answers 404, never a file, when no certificate exists. The Certificates employee filter ignores out-of-scope ids. |
| `tests/browser-real-site9.cjs` under **Twenty Twenty-Four** | 20 checks | Container width at 1920. Overview columns at 1440 and 1280, and stacking at 1100, 834 and 390, with a long announcement (created and deleted again). Certificates routing and the employee filter (report action, Overview "View all", chip removal, direct link). Report picker icons at 1440 and 390. |
| Same under **Twenty Twenty-Two** | 20 checks | Same |
| `browser-real-site.cjs` (Slice 8) under both themes | 67 checks each | Regression |
| Other `integration-*.php` (slice8 36, packet-ready 29, bulk-recovery 29, …) | pass | Regression |
| Redesign off (legacy) on the real site | pass | Legacy dashboard loads with no viewer, selection bar or listbox, and no script errors |

### Not verified

- **Staging:** nothing ran on Akansoh staging, under its BuddyBoss/Elementor theme, or with real people.
- **LearnDash:** real LearnDash certificates were not used. The stand-ins have none, so the 200 path of the real endpoint, and real PDFs inside a real ZIP, were tested only with synthetic PDFs through the same code.
- **Inline PDF display:** not verified in any browser (see Simulated).
- **Browsers:** Chromium only. Safari and Firefox page/zoom fragments, and print, were not checked.
- **PHP zip on staging:** the extension's presence on staging is unknown.

## Package

| | |
| --- | --- |
| File | `Gridhouse-admin-compliance-dashboard-1.7.3-rc.8-a67c01d.zip` (root folder `Gridhouse-admin-compliance-dashboard-plugin-main/`) |
| Commit | `a67c01db737943f86f5c4df3d8758ddc25b9fc53` |
| Size | 730,422 bytes |
| SHA-256 | `d3cf59386a110e6532fe2c1b6b6768b76900b91a293fa84840e49c37061026fa` |
| Manifest | `BUILD.txt`, inside the archive and alongside it: all 145 runtime files with their SHA-256 |
| Previous staging | `dea0da8`, installed by the site owner on November 7, 2026 at approximately 10:48 PM Toronto time, replacing `94b612d` |

**Verification:**
- `unzip -t` reports no errors.
- Every packaged file matches the commit byte for byte, and its hash is in the manifest. The BUILD.txt inside matches the one alongside.
- `php -l` passes on every PHP file.
- No tests or test stubs are in the package.
- The extracted package was installed on the disposable site, where it passed:
  - `integration-slice9` (32 checks) and `integration-slice8` (36 checks);
  - `integration-packet-ready` (29), `integration-bulk-recovery` (29) and the other integration suites;
  - `browser-real-site9` (20 checks) and `browser-real-site` (67 checks), each under Twenty Twenty-Four and under Twenty Twenty-Two.
- `browser-slice9` (104), `browser-slice8` (182) and `browser-slice7` (150) pass at this commit.

**Not installed on staging.**

## Rollback

Install `dea0da8`. Slice 9 adds no options, post meta or schema. Temporary ZIPs live in the private folder and expire after 15 minutes; an older build simply leaves any remaining ones, which the private folder's deny rules keep unreachable.
