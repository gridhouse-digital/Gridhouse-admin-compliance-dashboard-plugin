# Dashboard redesign, phase 2, slice 8: drawer header, Employees table, packet notice, inline report, Manage users, announcements

Opt-in, behind `ghca_acd_ui_redesign`, like every slice. This continues from `94b612d`, which the site owner installed on Akansoh staging on November 7 at 8:50 PM Toronto time, replacing `826d342`. All Slice 1–7 behaviour is preserved, and every earlier test suite still passes; three tests were updated where this slice deliberately changed the layout (see Tests).

With the redesign off, the legacy screens are unchanged. This was checked on the real disposable site: the legacy announcement modal publishes and deletes, and legacy Manage users keeps its table and modal. Unchanged in this slice:
- the outbound guard;
- bulletin publishing;
- site fonts and theme settings;
- training records.

**Design source:** "Gridhouse Compliance — Phase 2 Dashboards", canvas `1794092638-1fc0` (the same version as Slice 7). Boards used: `Admin-UserReport`, `ManageUsers`, `Modal-Announcement`, `Admin-Reports`, `Admin-Employees` and `T-Admin-Employees`.

**Screenshots:** the screenshots attached to the request were not visible in this session. Items 1 and 5 were therefore fixed from the description, the code, the boards and measurements in the browser.

## 1. Drawer header spacing

- The employee identity block in the drawer header has 18px below it before the tabs on desktop and tablet, and 14px on phones.
- When the tabs are hidden (Edit Records, error and loading states) it has 20px before the header's bottom edge, and 16px on phones.
- The close control keeps its own corner and does not overlap the identity at any width.
- This changes the employee header only; the packet card's header is unchanged.

## 2. Employees table

- **No inner scroll box:** the Employees and Inactive tables no longer sit in a 560px-high scroll box (`gh-table-wrap--tall`, `overflow: auto`). The table grows with the page, with no inner scrollbars. Column headers are no longer sticky, because a sticky header without a scroll box would stick under a theme's fixed header.
- **1024px and wider:** the table fits the width. Between 1024 and 1365px:
  - text wraps at word boundaries;
  - status chips and dates may take two lines;
  - person and progress columns get smaller minimum widths.
  
  Nothing is clipped and every row action stays visible.
- **Below 1024px:** the existing responsive cards replace the table. **Deviation:** the tablet board (`T-Admin-Employees`) says the table scrolls sideways inside its box at 834px. That is exactly what this request removes, so tablets get the cards.
- **Wider page (requested):** the dashboard content now uses up to 1600px instead of the board's 1320px, with 24px side gutters between 720 and 1365px (16px on phones, as before). Measured, with no page or inner scrolling at any width:

  | Viewport | Employees table width |
  | --- | --- |
  | 1920 | 1534px |
  | 1440 | 1374px |
  | 1280 | 1230px |
  | 1024 | 974px |

## 3. Inline User training report (Admin-UserReport board)

- **Open report** on the Reports tab now shows the report inside the tab, in place of the report cards. It no longer goes to `/user-report/`, so the "Select an employee from the compliance dashboard…" message does not appear. Without scripts, the link still goes to the legacy page, which is unchanged.
- **Employee picker:** an accessible combobox.
  - Type to filter; use the arrow keys, Home/End and Enter; Escape closes it.
  - It lists the people the Employees tab lists (Inactive too, for user managers), each checked with `GHCA_ACD_User_Report::can_view_user()`.
  - Before anyone is chosen, the board's empty state shows.
- **Server endpoint:** `ghca_acd_user_report` checks the dashboard nonce, dashboard access and `can_view_user()` on every request. An out-of-scope or unknown employee is refused and gets no report.
  - Responses are bound to the most recent choice: a slower answer for an earlier choice is discarded.
  - An expired session is reported as such.
- **Report content**, built from the same employee record and drawer model as the drawer, so statuses and counts match:
  - avatar, name, role, group, and the email masked as on the board;
  - status chip with the due note;
  - new-hire onboarding alert with the real deadline;
  - Progress, Courses completed, Due date and Last activity;
  - Required courses: title, hours, due or completed date, status, progress bar, last activity, View course, and Certificate where one exists.
- **Actions** keep the legacy report's checks:

  | Action | Behaviour |
  | --- | --- |
  | Email reminder | Opens the existing single-recipient composer with its review step; without reminder permission, the legacy mailto |
  | Log reminder | Shown with record-edit permission |
  | Contact employee | mailto |
  | Certificates (N) | Certificates page |
  | CRM contact | Shown when FluentCRM has the contact |
  | User profile | Shown with `edit_users` |

- **Print report:** prints only the report.
- **Back to reports:** returns to the cards and puts focus back on Open report.
- **Phones:** each course becomes a stacked card.
- **Not built:** the board card's description mentions "quiz results". The plugin's report has no quiz data, so no quiz column was added.

## 4. Manage users (ManageUsers board)

When the redesign is on, the Manage users page uses the board.

- **Page:** themed wrapper, "Back to reports", page heading, search by name or email, and Active/Inactive toggles with counts (from the existing suspended state).
- **Table (cards below 1024px):** name with avatar, email (with the phone underneath when there is one), role, group badges, status, and Edit.
- **Add/Edit drawer (520px; full screen on phones):**
  - first and last name, email, mobile phone ("Needed for SMS reminders."), role (no default), employment type;
  - LearnDash groups, with a filter when there are more than six and course counts when LearnDash provides them.
- **Same contract as before:** the form keeps the legacy field names, ids, nonce (`ghca_save_employee`) and action (`ghca_acd_save_employee`). Server checks are unchanged:
  - role and group scope;
  - per-row edit permission (`delegated_target_is_allowed`);
  - retired roles and employment types offered only to the employee who holds them.
- **Email check:** the email is checked inline with the same rule as the server's `is_email()`, which needs a dot in the domain.
- **Keyboard:** Escape closes the drawer and focus returns.
- **Bug fixed while testing:** an employee with no group used to show the page's own title ("Manage Users") as their group.
- **Not built:** the board's **"Send welcome email with sign-in link"** switch. The existing handler sends no welcome email, so this needs new functionality. It is left out rather than shown as a switch that does nothing.

## 5. Packet-ready notice visibility

**Causes found:**
1. The "downloaded" state changed the notice to a white surface while its message and close button kept the toast's white text. The result was white on white, visible only when selected.
2. The notice relied on inherited colours, so theme rules on `p`, `span`, `a` or `button` (for example BuddyBoss-style body colours or filled buttons) could repaint text and the close control.

**Fix:**
- Every state uses the board's dark toast surface, with the state shown on the left edge and in the icon.
- Every text, link, icon, close and "Show details" control has an explicit colour and opacity that theme rules cannot override.
- Colours are named tokens taken from the board's toast.

**Measured contrast**, as painted (including ancestor opacity, over the composited background), in every state:
- **States:** ready, compact, expanded, tray, network error, downloaded, refused, and expired by time.
- **Where:** in the card and in the page tray, under a plain and a hostile stand-in theme at 1440, 834 and 390px, and under Twenty Twenty-Four and Twenty Twenty-Two with a real packet.
- **Result:** the lowest ratio was 8.7:1. Text is held to at least 4.5:1 and icons to at least 3:1.

**Also new:**
- A compact notice has **Show details** (`aria-expanded`).
- After download, the notice reads "Annual packet downloaded. Link used. The PDF was saved and the private copy on the server was deleted, so this link no longer works. To download again, generate a new packet." The stale expiry time is removed.
- Expired and refused notices say "link no longer available".
- While the tray holds a notice, the page gets room at the bottom so its last content can scroll clear of the tray.

**Preserved:**
- the explicit Download PDF link, with no automatic download;
- notices persist after the drawer closes;
- unsaved Edit Records values are kept;
- single use, expiry and replacement;
- private storage and authorization.

## 6. Announcements (Modal-Announcement board)

- **Add announcement / Edit announcement modal** inside the themed wrapper. The legacy footer modal is skipped on that page. Fields:
  - **Title** (required, with an inline error);
  - **Message**;
  - **Type** as a segmented control (Alert, Reminder, Update; an announcement saved as "System" keeps that type while edited);
  - **Who sees it** (Employees and admins, Admins only, One group…, with a group picker limited to the viewer's groups);
  - **Action link**, checked inline.
  
  Focus starts on Title. Escape, Cancel and the scrim close the modal and return focus.
- **⋯ menu** on each announcement with Edit and Delete… (`role="menu"`; Enter or click opens it, the arrow keys, Home and End move, Escape and Tab close it).
- **Delete confirmation:** an alert dialog naming the announcement, with focus on **Keep it** and **Delete announcement** as the alternative.
- **Results:** "Announcement published", "updated" and "deleted" toasts.
- **Expired session:** nothing is published, the toast says "Couldn't publish. Your session timed out. Nothing was published — sign in again and your text will still be here." The form stays open with the text. A new announcement's text is also kept in this browser and offered again, with a note, the next time Add is opened after signing in.
- **Group scope on edit and delete (correction before packaging):** every edit and every delete is checked against the *stored* announcement first, before any other validation or write.
  - A group announcement belongs to its group. A scoped manager (a group leader whose view is limited to their LearnDash groups) cannot edit it, move it to any audience including "everyone", "admins only" or their own group, edit it through the legacy form, or delete it, unless that group is in their view.
  - Out-of-scope, unknown and non-announcement ids all get the same answer ("Announcement not found."), so an id outside one's scope is not confirmed to exist.
  - Global admins (unscoped managers) keep full access.
  - Unchanged: a scoped manager may still edit announcements whose audience is "Employees and admins" or "Admins only", as before this slice; only group announcements are tied to a group.
- **Server:**
  - Audience is stored as `ghca_announce_audience` (`all`, `admins` or `group`), with `ghca_announce_group`. A group must be one of the saving user's visible groups.
  - Saves from the legacy form, which has no audience field, keep the stored audience; new ones default to employees and admins.
  - Group announcements are shown on this dashboard only to admins whose view includes that group; unscoped admins see all of them.
  - Audience badges show on the list.
- **Employee dashboard:** it is a separate plugin, for later work. This plugin shows announcements only on the admin compliance dashboard, so "Employees and admins" and "Admins only" currently look the same here. The setting is stored for the employee dashboard plugin to read. The modal says so in its help text.
- **Board details covered:** the board's results column (published, deleted and couldn't-publish toasts) and its ⋯ menu are both implemented.

## Tests

### Synthetic: plugin preview pages with canned AJAX (not WordPress, not staging)

| Suite | Result | Covers |
| --- | --- | --- |
| `tests/browser-slice8.cjs` (new) | 182 checks | Drawer header spacing (3 widths, with and without tabs). Employees table at 1920, 1440, 1280, 1024 (plain and hostile) and cards at 834 and 390: no inner scroll boxes, no page overflow, all actions visible. Notice contrast in every state, card and tray, plain and hostile theme, 3 widths; unsaved value kept; persistence after the drawer closes; one PDF only on explicit download; consumed-link message. Inline report at 4 width/theme combinations: no navigation, scoped picker, keyboard combobox, overview values, course table, actions in board order, composer for that employee, stale responses discarded, out-of-scope request refused, session expiry, Back; reduced-permission actions. |
| `tests/browser-slice7.cjs` | 150 | Updated: row actions below 1024px are on cards, and the new "Link used" wording |
| `tests/browser-redesign-bulk.cjs` | 24 | Updated: selection below 1024px is on cards |
| `tests/browser-redesign-tables.cjs` | 54 | Updated: tablet uses cards instead of the scroll box |
| Other browser suites | drawer 159, drawer access 9, lists 51, admin 20, corrections 21, ODP pages 24, themed ODP 34, page background 18 | Earlier slices |
| All `tests/test-*.php` and `tests/test-*.js` | 81 files, all pass | |

**Hostile stand-in theme:** this slice adds BuddyBoss-like rules to it: body colour on `p`, `span`, `li` and `small`; filled pill buttons; translucent toasts. The staging theme itself was not available.

### Real WordPress 6.6.2 + MariaDB 10.11, disposable `ghca_test` site, real themes (not the staging theme, not staging)

The site is prepared by `tests/real-site/setup.php`. Its LearnDash stand-ins (`tests/real-site/ghca-real-site-stubs.php`, tests only, not packaged) provide synthetic groups and no courses, and mail is off.

| Test | Result | Covers |
| --- | --- | --- |
| `tests/browser-real-site.cjs` under **Twenty Twenty-Four** | 67 checks | Real pages, AJAX and database. Employees table and cards; drawer header. The real packet built with TCPDF/FPDI, with notice contrast in card, tray and used states at 1440 and 390; download saves a real PDF; the same link is then refused by the server. Inline report (real handler). Announcements: validation, publish, stored after reload, keyboard ⋯ menu, edit with group audience, delete confirmation, Escape keeps; session loss keeps the text and it is restored after signing in again (never published). Manage users at 1440/834/390: table or cards, search, Inactive empty state, inline email check, 520px drawer, Escape and focus return, edit and save through the real handler. |
| Same under **Twenty Twenty-Two** | 67 checks | Same |
| `tests/integration-slice8.php` (new) | 36 checks | Report permission boundaries (admin, unknown id, bad nonce, subscriber), masked email. Announcement audience validation (no group, a group outside scope), storage, legacy saves keeping the audience, Admins only dropping the group, subscriber cannot publish or delete. **Stored group scope:** a real `group_leader` managing group A only cannot see group B's announcement, and cannot, by id, edit its text, move it to everyone, to admins only or into group A, edit it from the legacy form, or delete it; each attempt is refused with the same answer as an unknown id and leaves title, message, type, audience and group unchanged. The leader can edit their own group's announcement but cannot move it into group B or create one for group B. A global admin then edits, moves to everyone and deletes the same group-B announcement. Manage users form contract, no welcome-email switch, subscriber refusal. |
| `integration-packet-ready` 29, `integration-bulk-recovery` 29, other `integration-*` | pass | Earlier slices |
| Redesign off (legacy) on the real site | pass | Legacy announcement modal publishes and deletes; legacy Manage users table and modal |

### Not verified

- **Staging theme:** nothing here ran under the Akansoh staging theme (BuddyBoss or Elementor), with real LearnDash or with real people. The real-theme runs used Twenty Twenty-Four and Twenty Twenty-Two; BuddyBoss-like rules were exercised only by the stand-in theme.
- **Browsers:** Chromium only.
- **Printing:** Print report was not printed to paper or PDF in a browser.
- **Employee dashboard:** the announcement audience has no visible effect there yet (separate plugin).

## Package

| | |
| --- | --- |
| File | `Gridhouse-admin-compliance-dashboard-1.7.3-rc.8-dea0da8.zip` (root folder `Gridhouse-admin-compliance-dashboard-plugin-main/`) |
| Commit | `dea0da8` (full id in `BUILD.txt`) |
| Size | 708,636 bytes |
| SHA-256 | `ddcb4716413c8072ce9da5a7e8bebc12eb99abe4a2c3fafd0c5da9b5bf46a153` |
| Manifest | `BUILD.txt`, inside the archive and alongside it: all 143 runtime files with their SHA-256 |
| Supersedes | `1cde2ca` (prepared, never installed). Its BUILD.txt wrongly said "Synthetic only… not verified with real WordPress" and gave the wrong staging history. |

**BUILD.txt:** it now states what was actually verified, and the correct staging history: `94b612d` was installed on November 7 at 8:50 PM Toronto time, replacing `826d342`.

**Verification:**
- `unzip -t` reports no errors.
- Every packaged file matches the commit byte for byte, and its hash is in the manifest.
- `php -l` passes on every PHP file.
- No tests or test stubs are in the package.
- The extracted package was installed on the disposable site, where it passed:
  - `integration-slice8` (36 checks);
  - `integration-packet-ready` (29 checks);
  - `integration-bulk-recovery` (29 checks);
  - `browser-real-site` under Twenty Twenty-Four and under Twenty Twenty-Two (67 checks each).
- `browser-slice8` (182 checks) and `browser-slice7` (150 checks) pass at this commit.

**Not installed on staging.**

## Rollback

Install `94b612d`. Slice 8 adds two post-meta keys on announcements (`ghca_announce_audience`, `ghca_announce_group`); older builds ignore them. There are no schema or option changes.
