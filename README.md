# Gridhouse Admin Compliance Dashboard — beta 14

Current test release: `Gridhouse-admin-compliance-dashboard-1.7.3-beta.14-test-only.zip`. Dashboard → employee drawer → Administration → Edit Records now includes agency-delivered ISP/Communication confirmations, separately saved and reloaded for the selected Annual packet period. Saved status, confirmed hours, reviewer/time and retained history are visible. Current-cycle applicability is under Advanced review; the legacy detailed ISP editor stays collapsed in the administrator profile, with its records preserved. Profile writes now occur after successful WordPress profile validation, fixing the self-stale save-order bug. Settings remain agency-wide defaults; no automatic credit, cycle changes, orientation changes or migrations. Includes beta13 and prior updates. Development checks passed; installed staging-role/persistence and PDF acceptance remain required. See `docs/beta14-drawer-confirmations-handoff.md`.

## Previous beta 13 release

Current test release: `Gridhouse-admin-compliance-dashboard-1.7.3-beta.13-test-only.zip`. Fixes the settings menu/content layout at WordPress's actual `#wpcontent` / `#wpbody` boundary. Section links explicitly open, scroll and focus their targets, including repeated clicks; other admin-page links retain native navigation. Training calculations and saved settings are unchanged. Rendered regression checks reproduced the beta12 gap and passed the correction at desktop/mobile sizes, including all 12 generated menu links. These use real plugin assets in a synthetic WordPress structure, not authenticated staging persistence. See beta13 handoff and TESTING.md.

## Previous beta 12 release

Current test release: `Gridhouse-admin-compliance-dashboard-1.7.3-beta.12-test-only.zip`. Agency-delivered ISP and Communication Profile training have adjustable defaults (1 hour each) and explicit employee/period confirmations under Users → Edit. Settings alone award no hours. Confirmations retain reviewer, time, period and credited default; withdrawals retain history. Annual/custom dates must match exactly; orientation is unchanged. ISP counts under topic hours, Communication Profile under additional hours. Already credited ISP course hours are not increased by the confirmation. Packet headings/source labels are cleaned up. Settings now uses left navigation, expandable sections and sticky native save controls. Includes all prior updates. Browser/runtime/PDF pagination acceptance remains outstanding; see beta12 handoff and TESTING.md.

## Previous beta 11 release

Current test release: `Gridhouse-admin-compliance-dashboard-1.7.3-beta.11-test-only.zip`. First settings UI pass uses WordPress admin colors, grouped navigation and scoped form styling; existing forms/permissions and dashboard/drawer are preserved. Settings now offers **Agency-selected packet courses**, separately for annual/custom reports and orientation, from LearnDash and external catalog entries. Rows display already-included evidence beneath the ODP topics without adding credit. The requested custom-report explanation paragraph is removed; calculations and reporting-only semantics remain. Includes beta 10 and all prior updates. Browser/WordPress saves and final PDF pagination require staging acceptance; see TESTING.md and docs/beta11-settings-and-packet-courses-handoff.md.

## Previous beta 10 release

Current release: `Gridhouse-admin-compliance-dashboard-1.7.3-beta.10-test-only.zip`. Unchecking the drawer completion checkbox now marks the course incomplete on save, preserving correction history and reopening the last lesson when all lessons were complete. Quiz-only passed courses require LearnDash review instead of destructive attempt deletion. Includes beta 9 and earlier updates. No live records changed; see docs/beta10-incomplete-checkbox-handoff.md and TESTING.md for staging acceptance.

## Previous beta 9 release

Current release: `Gridhouse-admin-compliance-dashboard-1.7.3-beta.9-test-only.zip`. Fixes ODP Annual Training external course/certificate exclusion in orientation, annual and custom-period packets. Broad-category hours are additional hours, not specific-topic coverage. Includes all beta 8 updates below. No re-upload or reapproval required for already eligible records. Separate approved records remain separately counted; review duplicate evidence before relying on totals. Staging PDF acceptance remains required. See docs/beta9-external-category-handoff.md.

## Previous beta 8 behavior

Current package: `Gridhouse-admin-compliance-dashboard-1.7.3-beta.8-test-only.zip`. Includes beta 7 totals/wording revisions. External Training Review now offers **Also applies to orientation for this employee** during approval. Existing approved entries have **Applies to orientation for this employee**, a review-reason field and **Save orientation applicability**. This creates an attributed new revision, retaining previous snapshots and the same verified certificate files. Old entries default annual-only. Generate an Orientation packet to include marked approved training and its stored PDF (uploaded JPG/PNG is already normalized to PDF). No annual date filter applies to orientation; annual behavior remains unchanged. Zero-hour orientation supporting evidence can be included without awarding hours or asserting topic completion. No automatic waiver of outstanding LearnDash orientation requirements. No migration, site install or Jotform call was performed. Read TESTING.md before staging acceptance.

Current package: `Gridhouse-admin-compliance-dashboard-1.7.3-beta.7-totals-1-test-only.zip`. Both custom-date and default annual-cycle PDFs show the same three recorded totals: 6100 topic training hours, Additional training hours, Total recorded training hours. No threshold suffix on the recorded total; the default-cycle annual assessment remains unchanged. Includes wording revision 1. Calculations, date filters and certificate selection are unchanged. Earlier package notes below are historical.

Use `Gridhouse-admin-compliance-dashboard-1.7.3-beta.7-wording-1-test-only.zip`. Removes the custom-period PDF topic-coverage explanatory paragraph and shortens the annual/custom ISP delivery note to the agency-held-records sentence. No calculation, evidence, status, date-range, totals, CSV or UI changes. Regenerate PDFs after installing; existing downloaded PDFs do not change.

**Current release: 1.7.3-beta.7 (staging test only).** Includes beta 6 updates and an optional audit date range in Drawer → Administration → Edit Records & Packet Generator. Choose audit reporting dates, enter both dates, then click Annual. Reports available mapped completions and certificates within the inclusive dates, without changing the saved annual cycle or asserting annual compliance. Current records/mappings are not frozen historical evidence. Dates and calculated evidence are job-bound; changed records abort generation. No employee-record or integration changes. See TESTING.md for September 1, 2025–August 31, 2026 acceptance steps. Development tests pass; browser verification was blocked and real WordPress/PDF acceptance is pending. All release notes below are historical.

**Current package: beta 6 manual-delivery revision 2.** Use `Gridhouse-admin-compliance-dashboard-1.7.3-beta.6-manual-delivery-2-test-only.zip`. Fixes the annual PDF's generic topic-review banner when manual ISP delivery is enabled, sufficient hours are recorded, all five other required topic statuses are Completed/Not applicable and there are no evidence issues. It now says **Annual Training Summary: Recorded hours and other required topics met — ISP handled manually / in person**. This is a split-scope report, not verified ISP completion or overall compliance; the agency-records note remains. Other gaps retain existing warnings. Calculator/CSV semantics, hours and evidence are unchanged. Earlier release sections below are retained history.

**Current package: beta 6 manual-delivery revision 1.** Use `Gridhouse-admin-compliance-dashboard-1.7.3-beta.6-manual-delivery-1-test-only.zip`. In Gridhouse Compliance → Settings → ISP training delivery, check **ISP implementation is handled manually / in person by this agency**, then save once. Annual PDF/CSV display Handled manually / in person with a short agency-records note. No employee setup, date prerequisite or training-detail entry is required for this delivery label. Hours, underlying topic/completion decisions, overall status and annual cycle are unchanged. This does not verify completion or attach evidence. Existing reviewed Not applicable remains visible. Option defaults off; unchecking restores prior reporting. Previous per-employee workflows and evidence are retained but are not needed for this route. Earlier release notes below are historical.

**Version:** 1.7.3-beta.6 — staging test only; not production-approved

**Beta 6 — temporary external ISP evidence disclosure.** Includes all beta 5 updates. In Users → Edit → ODP annual applicability review, select **External evidence — agency review required** and save. Training-detail fields may remain blank; existing applicability decisions are not exemptions and still require their existing reasons. The annual PDF places a separate disclosure below the matrix; CSV carries the same note. The agency must supply and review supporting records separately. This selection does not assert that records were supplied, attach documents, add hours or complete ISP coverage. Overall unresolved status stays Needs review. No automatic selection for other agencies/employees, no integration calls, no cycle changes. Existing beta 5 agency-managed selections use the new wording without a migration. Use `Gridhouse-admin-compliance-dashboard-1.7.3-beta.6-test-only.zip`.

**Current beta 5 build: ISP review 1.** Includes all original beta 5 changes plus agency-held ISP evidence review in WordPress Users → Edit. The ISP matrix row remains. Administrators can choose agency-managed, submitted/pending or manually verified evidence. Verification requires secure record and assigned-plan register/version references, training date/source/content/duration, review explanation and an explicit confirmation covering the employee and full scope. No automatic hours, file uploads, file attachments or Jotform calls. References and reviewer attribution are period-bound and retained as revisions. Generic course completion does not override a pending agency-managed review. Use the `1.7.3-beta.5-isp-review-1-test-only.zip` package; the original beta 5 archive is retained unchanged.

**Beta 5:** includes beta 1–4. LearnDash Audit Mapping retains a primary credit category and adds additional topic coverage with a required course-content reference. Each completion contributes hours and a certificate once. PDF/CSV topic states distinguish Completed, Missing training, Needs review and administrator-reviewed Not applicable. Conditional annual applicability is reviewed in WordPress Users → Edit for the current employee period, with reason, actor/time and append-only revisions. Unresolved core topics cannot become overall Compliant solely by reaching 24 hours. No annual-window, dashboard-design, OLTL, archive or integration changes. External training remains single-category; immutable approved snapshots are not rewritten. Course descriptions remain LearnDash-owned; mapping now exposes a source-edit link and description preview. Actual source-content corrections were not performed on any site. WordPress/browser and rendered-PDF acceptance remain pending.

**Beta 4:** includes beta 1–3 plus the annual-cycle first-save fix. Settings and Agency Profile now distinguish an unconfigured cycle from an explicitly saved policy; PDF labels no longer turn an empty cycle into Employee Hire Date. No policy is backfilled on upgrade. Where no policy was persisted, select the intended policy in Settings and save once after upgrading. Invalid submissions preserve existing supported policy. Dashboard/OLTL date reconciliation remains pending.

**Beta 3:** includes all beta 1/2 changes plus verified employment-date entry and ODP calculation integration. ODP results require a verified date and an explicitly saved annual-cycle setting; missing inputs produce evidence gaps, not registration-based dates. Dashboard/OLTL date reconciliation and runtime acceptance remain pending. This is not a reconciled compliance release.

Beta 2 adds the release version to dashboard CSS/JavaScript cache keys, retaining file timestamps. No drawer design changes. Purge full-page/optimization/CDN caches during deployment if they retain old HTML or ignore version query parameters.

This prerelease contains Employment Type, descriptive Agency Profile intake and the earlier Phase 1A missing-training-date corrections. WordPress/browser acceptance is pending. Read `TESTING.md` before installing; this package does not establish outbound isolation or close Phase 0 gates.
**Author:** Gridhouse Digital
**Text Domain:** `ghca-acd`
**Requires:** WordPress 6.0+, PHP 7.4+, LearnDash 4.0+

---

## What is this plugin?

The **Gridhouse Admin Compliance Dashboard** is a WordPress plugin that gives HR managers, compliance officers, and team leaders a clean, frontend interface to monitor employee training.

Instead of forcing administrators to navigate the complex WordPress backend to manually pull LearnDash reports, this plugin provides a suite of shortcodes that generate a beautiful, unified dashboard on the frontend of your website (designed for use with Elementor).

## What does it do?
- **Frontend Command Center**: Renders data visualization KPI cards, an interactive employee roster table, and priority action alerts.
- **Tracks Healthcare Compliance**: Actively monitors required compliance courses (like HIPAA, Bloodborne Pathogens) for all staff members across the organization.
- **Manages Training Lifecycles**: Gives brand new employees a configurable grace period to complete their onboarding training. For existing employees, it tracks their annual renewals (e.g., highlighting an employee in yellow 30 days before their HIPAA training expires).
- **Highlights At-Risk Employees**: Managers can instantly see a list of "Overdue" or "Expiring Soon" employees, allowing them to download a CSV report or log notes directly to the employee's FluentCRM profile without leaving the page.

---

## Key Features

- **Centralized Command Center**: View all employee training metrics in one unified dashboard.
- **Dynamic KPI Tracking**: Monitor total employees, compliant staff, employees currently in onboarding, and overdue personnel at a glance.
- **Inactive Employee Access**: Exclude BuddyBoss-suspended users from active metrics while keeping their records and audit-packet actions available to authorized user managers.
- **Rolling Course Expirations**: Robust compliance logic evaluates "Compliant," "Expiring Soon," and "Expired" states based on configurable course lifespans (e.g., 365-day validity with a 30-day warning window).
- **New Hire Onboarding Tracking**: Tracks employees inside their configurable onboarding window and automatically escalates them to "Overdue" if they miss the deadline.
- **Advanced Filtering**: Filter the employee roster table by Compliance Status, LearnDash Group, Job Role, and Search Query.
- **Deep Integrations**: Seamlessly works with LearnDash for progress/certificates, FluentCRM for logging compliance notes/reminders, and BuddyBoss for profile navigation.
- **One-Click CSV Export**: Easily download compliance reports for external auditing.
- **Dashboard Branding**: Customize the dashboard with your organization's name, logo, colors, and support email.
- **Granular User Permissions**: Assign specific dashboard overrides (Edit Records, Manage Announcements, Unrestricted View) to individual users, independent of their WordPress role.
- **Employee Email and SMS Reminders**: Authorized staff can compose safe plain-text reminder content that is delivered through responsive agency-branded HTML email with a plain-text fallback, use channel-approved templates, choose urgency, and review a scoped, paginated communication history. SMS is an optional, fail-closed Twilio module.

---

## Compliance Rules Engine

The plugin uses a sophisticated rules engine to determine compliance status:

1. **New Hire Onboarding**: When a user registers, they are placed in a configurable onboarding window (default: 30 days). If they do not complete all required courses within that window, they become **New Hire Overdue**.
2. **Rolling Expirations**: Completed courses are tracked against a configurable lifespan (e.g., 365 days).
   - **Compliant (🟢)**: All courses are completed and valid.
   - **Expiring Soon (🟡)**: A completed course is entering its warning window (e.g., expires within 90 days).
   - **Expired (🔴)**: A completed course has passed its lifespan date and must be retaken.
3. **In Progress**: The user has started or completed some courses, but is not fully compliant yet.
4. **Not Started**: The user has not started any courses.

---

## Roles & Access

Access to the dashboard is tightly controlled via a custom capability: `view_compliance_admin_dashboard`.

**Auto-registered Custom Roles:**
- `hr_manager`
- `compliance_lead`
- `training_manager`

**Additional Allowed Roles:**
- `administrator`
- `editor`
- `group_leader`
- `ld_instructor`

> **Note on Scoping:** If the user is a **Group Leader**, the dashboard automatically scopes the data. They will only see statistics and roster entries for employees within the LearnDash groups they manage. Users with the **Unrestricted View** permission override this behavior and see all employees company-wide.

---

## Granular User Permissions

Managed from **Settings → Compliance Permissions** in wp-admin. Each field accepts a comma-separated list of WordPress User IDs.

| Permission | What it controls |
|---|---|
| **Edit Training Records** | Allows the user to manually alter course completion dates and timers via the Edit Records form. Without this, dashboard viewers can only read data. |
| **Manage Announcements** | Allows the user to create, edit, and delete global compliance dashboard announcements. |
| **Unrestricted View** | Allows the user to see all employees company-wide, bypassing LearnDash group scoping constraints. |
| **Send Employee Reminders** | Allows the user to queue email reminders for employees already inside the user's dashboard scope. |
| **View Communication History** | Allows the user to view recorded reminder attempts for employees already inside the user's dashboard scope. |
| **Manage Reminder Templates** | Allows the user to create and revise reusable plain-text reminder templates. Administrators always retain this access. |

> WordPress administrators (`manage_options` capability) automatically have all permissions. Compliance Leads receive the dashboard reminder-send and history permissions, but do not receive WordPress settings administration. These settings grant specific overrides without promoting a user's WordPress role.

---

## Settings Pages

The plugin registers three separate pages under **Settings** in wp-admin:

### Settings → Compliance Admin
General dashboard configuration:
- **New Hire Compliance** — Select which LearnDash groups are new hire groups and set the completion window (days).
- **Dashboard Branding** — Customize primary/secondary/accent colors, organization name, logo URL, and support email.
- **Dashboard Performance** — Configure the at-risk window (days) and aggregate cache TTL (seconds).
- **Rolling Expirations & Traffic Light** — Set per-course lifespans (e.g., CPR = 730 days) and the warning window before expiry.

### Settings → Compliance Permissions
Per-user permission overrides (see [Granular User Permissions](#granular-user-permissions) above).

### Settings → Compliance Messaging
Email and optional Twilio SMS configuration:
- Email sending is disabled by default after upgrade and must be enabled by an administrator.
- Configure the sender display name, optional reply-to address, branded-HTML toggle, portal button label, and footer text, then send the real branded template as a transport test to the current administrator.
- Branded email reuses the agency name, public HTTPS logo, colors, and support email from **Settings → Compliance Admin → Dashboard Branding**. Images are optional and the email remains readable when an email client blocks them.
- Optionally permit reminders to inactive employees; this is disabled by default.
- Template placeholders are allowlisted and rendered as plain text. Templates explicitly declare their allowed delivery methods.
- SMS remains unavailable until all provider, campaign, Advanced Opt-Out, connection-test, E.164 phone, and phone-specific consent gates pass.
- Define `GHCA_ACD_TWILIO_ENCRYPTION_KEY` in `wp-config.php` or an earlier host configuration file as at least 32 random characters. The key must not be stored in the WordPress database or committed with the plugin.
- Use a restricted API key with only Messaging Service read and Message create access. Webhook signing requires the agency account or agency subaccount Auth Token; never store a Gridhouse parent-account token on an agency site.
- Configure the generated public HTTPS status callback and inbound callback URLs in Twilio, enable Advanced Opt-Out, and run the connection test after every credential or campaign-setting change.
- Add `[ghca_sms_consent]` to an authenticated employee portal page to provide the unchecked web opt-in and self-service opt-out workflow. Privacy Policy and Terms URLs are required before opt-in is offered.

Reminder delivery uses WP-Cron. If `DISABLE_WP_CRON` is enabled, configure the server to request `wp-cron.php` regularly. A status of **Accepted by email transport** means WordPress accepted the message for sending; it is not proof that the recipient received it.

---

## Shortcode Reference

The dashboard is built entirely on shortcodes, allowing you to design the layout precisely as you want in Elementor.

| Shortcode | Purpose |
|---|---|
| `[admin_compliance_dashboard]` | Outputs the full predefined dashboard layout. |
| `[ghca_sms_consent]` | Shows the signed-in employee’s phone-specific SMS opt-in or opt-out form after Twilio and disclosure settings are ready. |
| `[admin_compliance_login_gate]` | Access gate (prompts login or denies access based on role). |
| `[admin_compliance_scope_banner]` | Displays the current data scope (e.g., showing which groups a Group Leader is viewing). |
| `[admin_compliance_header]` | The command header with the title and export button. |
| `[admin_compliance_kpis]` | The 4-column KPI metric cards. |
| `[admin_compliance_group_summary]` | Group comparison progress bars. |
| `[admin_overdue_employees]` | Priority table highlighting employees needing immediate attention. |
| `[admin_course_completion_overview]` | Module-by-module course completion statistics. |
| `[admin_employee_compliance_table]` | The primary, filterable employee compliance roster. |
| `[admin_certificate_tracking]` | Quick certificate download metrics. |
| `[admin_compliance_export_button]` | A standalone CSV download button. |
| `[admin_compliance_announcements]` | Admin-authored announcements panel (create/edit/delete controlled by the Manage Announcements permission). |
| `[admin_compliance_quick_links]` | Actionable quick links. |
| `[admin_compliance_support]` | Support contact box. |
| `[admin_compliance_user_report]` | Individual employee detail view with course-by-course breakdown and Edit Records form. |

---

## Integrations

- **LearnDash**: Core engine for groups, courses, enrollment, progress calculations, and certificates.
- **FluentCRM**: Clicking the "Log Note" action in the employee table opens a modal to instantly log a note on the user's FluentCRM profile.
- **BuddyBoss**: Injects the admin dashboard tab directly into the BuddyBoss profile navigation for seamless user experience.
- **Elementor**: All shortcodes are tested and optimized for Elementor rendering.
- **Jotform (optional)**: Indexes administrator-allowlisted form submissions only after an exact hidden WordPress `user_id` is authenticated by a server-issued, form-bound ownership claim. It supports Standard and EU API regions and retains approved external certificates in encrypted private evidence storage. Direct HIPAA-account file downloads are not supported because Jotform requires an authorized account session.

### Signed Jotform employee ownership

Each allowed external-training form needs a second hidden field for the ownership claim. Map that question ID as **Ownership claim QID** in **Settings → Jotform Documents**. In 1.6.1, configured Jotform HTTPS course iframes automatically receive both mapped hidden values once; no duplicate site-specific embed code is needed. The logged-in course page also exposes an authenticated browser helper for optional legacy integration:

```javascript
const identity = await window.ghcaAcdJotformOwnership.getClaim('123456');
// Prefill the mapped hidden fields with identity.userId and identity.claim.
```

Optional site-specific Jotform embed code may use that helper only for a legacy integration, and must not duplicate the bundled automatic binding. The claim is signed by the WordPress site, bound to the employee, allowed form, site and external-training purpose, and is accepted only when the Jotform submission was created during its validity period. Missing, expired, tampered or form-mismatched claims are stored unassigned and cannot appear in an employee drawer. An Administrator or Compliance Lead may explicitly bind a quarantined pending revision to the correct employee during review; that operation binds the exact training revision and its documents together.

---

## Developer API & Filters

Developers can easily extend or modify the dashboard's behavior using the provided WordPress filters:

| Filter | Purpose |
|---|---|
| `ghca_compliance_group_ids` | Define the specific LearnDash Group IDs that are tracked for compliance. |
| `ghca_compliance_employee_roles` | Define which WordPress user roles are considered "Employees" (tracked on the dashboard). |
| `ghca_admin_dashboard_roles` | Modify the list of roles allowed to view the dashboard. |
| `ghca_course_lifespans` | Modify the rolling expiration dates and warning windows for specific LearnDash Course IDs. |
| `ghca_admin_quick_links` | Modify the Quick Link cards. |
| `ghca_admin_announcements_items` | Modify the Admin announcements panel. |
| `ghca_admin_support_email` | Change the support email address. |
| `ghca_employee_support_email` | Change the employee-facing support email address. |
| `ghca_user_report_back_label` | Customize the "Back to Dashboard" link text on user report pages. |

---

## Export API

The plugin provides a secure, nonce-protected endpoint for CSV generation:

```text
admin-post.php?action=ghca_acd_export_csv
```

This generates a full roster report including First Name, Last Name, Email, Status, Onboarding State, Roles, Groups, and Lifespan Data.

---

## Changelog

### 1.7.2
- **Orientation matrix corrected:** 55 Pa. Code 6100.142 sets required orientation content areas and timing rules but no credit-hour minimum. The matrix previously printed a "Credit Hrs Required / Achieved" figure whose "required" side was only the sum of whatever courses the agency had mapped into each area, so enlarging the catalog silently raised every employee's apparent obligation against a threshold the regulation does not contain. Orientation now reports Completed, Completed On and Time Spent, with a note that recorded time is informational. The annual matrix is unchanged and keeps required/achieved, because 6100.143 does set an hours floor.
- **Employee records:** The drawer's Indexed Records list and its tab counter now include manual entries alongside indexed Jotform documents, merged at the display layer so synchronization and its scoped queries stay untouched. Approved and pending certificates open in the existing certificate modal rather than a new browser tab.
- **Fixed:** Certificate preview and download actions shared one grid cell so the row keeps its four columns, and the status dot can no longer wrap above its label.
- **Note:** Builds before this release also reported 1.7.1, so version alone could not distinguish them. Check the plugin version before reporting packet behaviour.

### 1.7.1
- **Added:** Administrator manual entry for external certificates obtained outside Jotform (email, paper scan, shared drive). Reviewers upload one PDF/JPEG/PNG from the employee drawer's Documents tab with a mandatory reason; the file is normalized, SHA-256-bound and encrypted into the existing private evidence store and lands in External Training Review as a pending record.
- **Approval boundary:** Manual entries use the identical review, PDF preflight, approve, reject and revoke transitions and the same immutable snapshot/manifest persistence as Jotform evidence. Approval snapshots record the entry method, entering user and reason; annual packets label the lesson detail page and OLTL packets label the source column accordingly.
- **Schema:** Version 6 adds `entered_by`, `entry_reason` and `manual_manifest` to the external-training table; the `form_id` value `manual` is reserved and never matches a configured Jotform form. Identical certificate bytes cannot be entered twice for one employee; a same-lesson, same-date record is flagged for the reviewer. Rejecting a pending manual entry removes its stored certificate.
- **Legacy Jotform submissions:** No code change. Historical backfill indexes them as unsigned pending records for reviewer binding.
- **Admin menu:** The eight settings screens previously spread across Settings and Tools now sit under one top-level `Gridhouse Compliance` menu. Page slugs are unchanged, so only the parent moves from `options-general.php`/`tools.php` to `admin.php`; saved links to the old locations are redirected. The menu is hidden from users with no permitted screen, and its capability follows its most permissive child so messaging-only users keep access.

### 1.7.0
- **Added:** Disabled-by-default OLTL Chapter 52 training-readiness profile with explicit role/employee assignment, six verified recurring topics and a separate participant/service-plan manual review.
- **Evidence:** Internal completions require a trustworthy current-cycle date and documented course content. Active approved external revisions retain immutable OLTL mappings. Legacy OLTL categories remain stored for rollback but are ignored by the evaluator.
- **Statuses:** Readiness uses Satisfied, Due, Expired, Missing Evidence, Manual Review and Not Applicable, with Evidence Ready, Action Needed or Manual Review Required overall. It does not issue legal compliance conclusions or hour/percentage scores.
- **Security:** Optional manual PDF/JPEG/PNG support is normalized, SHA-256-bound and encrypted in existing private evidence storage. Packets show only redacted manual references and never append participant-specific support bytes.
- **Access and packets:** Administrators configure; Administrators and Compliance Leads review and generate a distinct scoped `oltl_training` packet. HR Managers receive no new OLTL access. Existing ODP calculations and packets are unchanged.
- **Employee workspace:** Rebuilt the employee drawer from the corrected Clinical Precision Stitch handoff with a fixed identity/tab header, independently scrollable tab workspace and fixed action footer. Training, Documents, Communications and Administration reuse their existing data, permissions and action hooks.
- **UI boundary:** The redesign remains scoped custom CSS with the existing configured dashboard colors and system fonts. Prototype-only upload/request actions, record hashes, invented identifiers, Tailwind and new dependencies are excluded.
- **UI parity:** Drawer controls now inherit the dashboard typography, use underline-only tab selection, compact borderless cards and a two-action footer. Communications show method and date/time before each message; Administration shows real employment standing and stored review/reminder metadata.
- **Release boundary:** The corrected Clinical Precision source remains unpackaged until controlled HTTPS acceptance. Pre-existing 1.7.0 ZIPs predate this corrected drawer implementation; retain the verified 1.6.6 package for rollback.

### 1.6.4
- **Fixed:** Employee-facing Jotform document lists, counts, searches and pages show all files only when their fingerprint is the newest indexed training-source revision for the form/submission. Certificate-free or changed-owner revisions therefore hide older employee metadata without deleting it; historical rows, encrypted approved evidence and exact training review remain retained.
- **Security:** Stale source-revision or verified-owner document grants are denied with an audited, no-cache HTTP 409 page that directs authorized users back to the current employee/review item without exposing source details.
- **Security:** Synchronization holds a bounded, connection-scoped MySQL/MariaDB advisory lock from remote fetch through revision and cursor persistence; a competing run fails closed before it can fetch or persist an older snapshot.
- **Drawer:** Employee details now presents profile/KPIs and quick actions first, with native responsive Training, Documents, Communication, and authorized Review & administration groups. Existing permissions, action hooks, packet tracker and report links are unchanged.
- **Verification boundary:** Local PHP/JavaScript checks cover the release candidate. Controlled HTTPS staging must still validate real Jotform/Drive access, role scope, keyboard/mobile browsers, packets and private-storage operations.

### 1.6.3
- **Fixed:** Google Drive evidence reconciles a unique safe canonical filename key, including sanitize-file-name punctuation/space changes and a missing extension only where the Drive MIME safely supplies it. Collisions, MIME/extension conflicts, extras and ambiguity still fail closed.
- **Review:** A reviewer can run a fresh PDF preflight before approval; FPDI incompatibility, unreadable or password-protected PDFs are replacement-required. Re-export or Print to PDF and retry. Approval revalidates again and never stores a failed file.
- **Operations:** Administrators receive redacted Drive root readiness results, readable document-retrieval errors with a request ID, paginated pending review, unsigned-pending bulk rejection with a reason/event trail, resumable historical backfill reset, and last-sync read/indexed/failed counters.
- **Mapping:** A selected catalog row that is not the exact submitted title/code or configured alias requires an explicit recorded override reason; prior approved snapshots are unchanged.
- **Hosted configuration:** Define `GHCA_ACD_PRIVATE_DIR` to a pre-existing private directory, for example `dirname(__DIR__) . '/private'` when the hosting layout places that directory beside WordPress. It must resolve outside WordPress/uploads. Missing means the constant is absent; unavailable means the path does not exist or cannot be opened (including permissions/open_basedir); unsafe means it resolves under WordPress/uploads; a Drive credential error means its configured JSON path is missing, unreadable, outside that private directory, oversized or invalid. None of these messages disclose the actual private path or JSON.
- **Scope:** No shell converter, PDF rewrite dependency, automatic repair, Dual-Layer runtime change, or legacy custom Jotform snippet change is included.

### 1.6.2
- **Fixed:** Compliance Leads can edit active and inactive employee accounts using the Subscriber, Caregiver or Nurse roles.
- **Security:** Administrator, staff, self, out-of-scope and mixed privileged-role accounts remain blocked; Compliance Leads still do not receive WordPress user-administration capabilities.

### 1.6.1
- **Added:** Per-form private Google Drive certificate-byte source for login-gated Jotform uploads. Jotform remains the signed ownership and submission-metadata source; Drive content is validated and then encrypted into the existing private evidence archive.
- **Added:** Authorized Compliance Leads can use the existing External Training Review workflow in the frontend dashboard; administrators retain a wp-admin emergency page.
- **Added:** Configured HTTPS Jotform course iframes receive `user_id` and the existing signed `ghca_ownership_claim` once, including embeds inserted after page load.
- **Setup:** Define `GHCA_ACD_GOOGLE_DRIVE_CREDENTIALS_FILE` for one service-account JSON inside the validated `GHCA_ACD_PRIVATE_DIR`; configure an exact `Evidence-<reference>` child folder for each submission. No public Drive URL, persistent token, SDK, or bulk migration UI is included.
- **Limitations:** This release does not claim live Google/Jotform validation, HIPAA compliance, or hardening of earlier custom Jotform snippets. Roll back to 1.6.0 after changing affected forms back to the default Jotform source.

### 1.6.0
- **Added:** Disabled-by-default Jotform document indexing with administrator-configured form/question mappings, resumable backfill, hourly incremental synchronization, a 100-call daily quota safety floor, and an administrator-only connection test.
- **Added:** The three newest employee-linked documents in active and inactive employee drawers plus a permission-controlled, searchable, paginated View All Documents modal.
- **Added:** An administrator-managed external lesson catalog with stable training codes, aliases, provider, full description, ODP category, credit hours, and active status.
- **Added:** Administrator and Compliance Lead external-training review with exact-revision certificate preview/download, employee/catalog/date confirmation, approval, rejection, required-reason revocation, duplicate warnings, and supersession history.
- **Compliance:** Only active approved external evidence within the employee's annual hire-date window contributes snapshotted hours; it never satisfies orientation requirements.
- **Packets:** Approved external lessons join the combined course table, receive a full information page, and append every certificate immediately afterward. Jobs bind exact evidence revisions and SHA-256 digests and abort completely on missing, changed, corrupt, oversized or unreadable evidence.
- **Security:** The shared `JOTFORM_API_KEY` is read only from `wp-config.php` and sent in the server-side `APIKEY` header. Approved evidence uses a separate stable `GHCA_ACD_EVIDENCE_ENCRYPTION_KEY`, AES-256-GCM, and durable `GHCA_ACD_PRIVATE_DIR` storage outside WordPress/uploads.
- **Security remediation:** Automatic employee ownership now requires a server-issued signed claim, synchronization uses a submission-ID keyset inside a bounded update window instead of offsets over mutable ordering, and rejection is enforced as an atomic pending-only transition while approved removal remains reasoned revocation only.
- **Migration:** Schema version 2 quarantines legacy pending/rejected unsigned associations and unreviewed ordinary document links. Previously approved/revoked/superseded evidence is retained, while potentially inconsistent legacy workflow rows are counted for administrator review rather than silently rewritten.
- **Compatibility:** Existing prefilling and `get_user_jotform_submission` snippets are not registered, modified or replaced by this release. Their separate hardening risks remain outside the plugin package.

### 1.5.0
- **Added:** Responsive, email-client-compatible HTML reminder presentation using the configured agency name, HTTPS logo, colors, support email, urgency banner, employee greeting, portal call-to-action, and footer.
- **Accessibility:** Every HTML reminder includes an isolated plain-text alternative and remains understandable when remote images are blocked.
- **Changed:** The employee drawer shows only the three newest logical communications and a total count instead of growing indefinitely.
- **Added:** An authorized full-history modal with server-side 20-record pagination, channel/status/date/search filters, grouped Email + SMS deliveries, expandable complete messages, bounded retry, and permission-checked Use Again drafts.
- **Security:** Administrator message content remains plain text and is escaped into the fixed HTML shell; history filters are allowlisted and prepared, and full-history/draft endpoints repeat nonce, permission, employee-scope, and schema checks.

### 1.4.0
- **Added:** Optional Twilio SMS and Email + SMS delivery through agency-owned accounts or agency-isolated Gridhouse-managed subaccounts.
- **Added:** Encrypted write-only provider secrets, restricted-key connection testing, fixed Twilio endpoints, signed status callbacks, delivery-state updates, and test SMS controls.
- **Added:** Append-only phone-specific consent events, authenticated employee opt-in/opt-out shortcode, documented paper/web consent recording, Advanced Opt-Out STOP/START/HELP handling, and opt-in confirmation SMS.
- **Security:** SMS is disabled by default and fails closed on missing host encryption key, provider identity, current connection test, campaign attestation, Advanced Opt-Out attestation, E.164 phone, consent, employee scope, or inactive-account policy.
- **Privacy:** Full phone numbers, provider secrets, message bodies, and webhook payloads are excluded from operational logs; long-term delivery history stores a masked phone and keyed destination hash.

### 1.3.0
- **Added:** A controlled Send Reminder popup with Email, SMS, and Email + SMS choices; only Email is enabled in Phase 1.
- **Added:** Plain-text subject/message composition, Normal/Important/Urgent handling, reusable permission-controlled templates, and allowlisted placeholders.
- **Added:** Scoped communication history with masked destinations, transport status, failure summaries, and bounded manual retry.
- **Added:** Additive communication, delivery, and template tables installed with WordPress `dbDelta`, including a durable idempotency constraint.
- **Security:** Sending, history, and template management have separate permissions; every request rechecks nonce, permission, employee scope, channel readiness, and schema readiness server-side.
- **Security:** Email is disabled by default, inactive-employee reminders are disabled by default, SMS fails closed, transport errors are redacted, and retries are capped at three attempts.
- **Operations:** Email jobs use WP-Cron with interrupted-worker and orphaned-queue recovery. WordPress mail acceptance is recorded as transport acceptance, not confirmed delivery.

### 1.2.9
- **Added:** Each completed course now has a dedicated full-description page immediately before its certificate while retaining the existing combined course table.
- **Changed:** Employee name now appears inside the cover-page details box with role and employment dates.
- **Changed:** The employee-anniversary packet label now reads `Annual Cycle Rule: Employee Hire Date`.

### 1.2.8
- **Fixed:** Unassigned orientation courses no longer make an employee's otherwise timely orientation completion appear outside the 30-day deadline.
- **Fixed:** Compliance Lead and HR packet certificates now use LearnDash Certificate Builder's full renderer instead of its blank legacy fallback.

### 1.2.7
- **Fixed:** Delegated Edit Training Records access no longer causes recursive employee-record rendering and PHP memory exhaustion.
- **Fixed:** Authorized non-LearnDash-admin dashboard roles can retrieve another employee's certificate through a one-time, job-bound internal broker without receiving administrator capabilities.
- **Access:** Compliance Leads now receive full compliance-dashboard access, including inactive employees, training-record edits, reviews, reminders, announcements, employee management, unrestricted dashboard scope, and packets.
- **Security:** Compliance Lead employee management remains restricted to in-scope subscriber accounts; WordPress administration, privileged roles, plugins, themes, and site settings are not granted.

### 1.2.6
- **Security:** Restricted delegated user managers to in-scope subscriber employee accounts and blocked privileged role assignment.
- **Security:** Kept globally configured new-hire groups inside each group leader's LearnDash scope.
- **Security:** Required edit permission for review markers and FluentCRM reminder notes.
- **Security:** Restricted certificate URLs to the site origin, restored TLS verification, and limited forwarded authentication cookies.
- **Security:** Added certificate count, byte, page, and concurrent-job limits; completed packet files are deleted after download.
- **Security:** Revalidated certificate URLs before browser preview and download actions.
- **Security:** Group-leader scope now fails closed if the LearnDash scope helper is unavailable, and edit-form AJAX requires edit permission.
- **Security:** Announcement posts reject generic WordPress post capabilities; PDF jobs use private temp storage, serialized one-shot phases, minimized cookies, bounded decompression, cancellation, and scheduled cleanup.
- **Deployment:** Windows hosts must define `GHCA_ACD_PRIVATE_DIR` as a pre-existing ACL-protected directory outside WordPress/uploads and set `GHCA_ACD_PRIVATE_DIR_ACL_VERIFIED` to `true` after verifying its owner-only ACL; packet generation fails closed otherwise.

### 1.1.0
- **Added:** Granular User Permissions system (Edit Training Records, Manage Announcements, Unrestricted View) managed via a dedicated **Settings → Compliance Permissions** page.
- **Added:** Rolling course expirations with configurable per-course lifespans and a traffic-light status system (🟢 🟡 🔴).
- **Added:** Dashboard Branding settings (primary/secondary/accent colors, organization name, logo, support email).
- **Added:** Individual user report shortcode (`[admin_compliance_user_report]`).
- **Fixed:** Dashboard viewers could previously modify training records without explicit edit permission (privilege escalation).
- **Fixed:** Announcement management was accessible to all dashboard viewers instead of only authorized users.
- **Fixed:** Non-admin users with unrestricted view now see consistent data across KPI cards, scope banners, and roster tables.

### 1.0.0
- Initial release with KPI dashboard, employee roster, CSV export, FluentCRM integration, and BuddyBoss navigation.
