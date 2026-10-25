# Beta 14 — drawer confirmations and profile save order

Run `php tests/test-agency-drawer.php` (345 cumulative synthetic checks), `php tests/test-course-incomplete.php`, `php tests/test-annual-cycle-settings.php`, `php tests/test-agency-profile.php`, `php tests/test-settings-console.php` and both console JavaScript tests. PHP syntax checks cover all 160 PHP files. Actual PHP-rendered forms and dashboard/agency JavaScript passed an intercepted-AJAX browser regression at 1440×1000 and 390×844: independent save/reload, withdrawal status, reporting-date switching, advanced review save, visible error/recovery, no script errors or mobile overflow. This is not an installed WordPress or live-role verification.

Staging only, using fictional employees:

1. Replace the previous plugin with beta14. Open an employee drawer → Administration → Edit Records. Agency-delivered training loads automatically, independently of course records. Confirm the displayed dates match the Packet Generator period; select custom dates if reviewing a closed period.
2. Select Confirm completed in person for the applicable topic(s), check the declaration, then Save confirmations. Expect an explicit saved status with hours/reviewer/time. Refresh saved confirmations and reopen the drawer with the same dates: saved status must remain even though the action dropdown returns to No change.
3. Change reporting dates: another period must show its own status, not borrowed credit. Generate a fresh Annual/custom report with the confirmed exact dates. Verify existing no-double-counting, three totals and certificate pages. Orientation remains unchanged.
4. Withdraw one confirmation: history must remain and only its applicable credit disappear. Reconfirm after correcting defaults if needed. Changing defaults alone must not rewrite past confirmations.
5. Open Advanced review. It must identify the current annual cycle independently of custom report dates. Save a justified applicability change. Confirm it does not change training confirmations or prior detailed ISP evidence. Routine drawer must not show the old detailed ISP editor.
6. Test an authorized scoped compliance editor without wp-admin access; then test a read-only user and out-of-scope employee: load/save must be denied. Test stale revisions, changed defaults and expired nonces. A failed save must not claim success; refresh to retrieve persisted state.
7. In WordPress Users → Edit, confirm legacy profile saves no longer produce their own stale-revision error. Invalid profile input must not append training/review history. Check native Settings and new-menu navigation regressions too.

Do not enable messaging or call/submit Jotform. No migrations, live installation or real employee changes were performed in development.

# Beta 13 — WordPress layout and navigation correction

Run `node tests/test-settings-navigation.cjs`, `node tests/test-settings-console.cjs` and `php tests/test-settings-console.php`. Browser regression reproduced beta12 content below the menu and verified the grid fix using the actual generated menu/CSS/JS at 1600×1000 and 390×844. All 12 menu links clicked successfully on synthetic routes; repeated section clicks reopen collapsed sections, focus and current state update, and mobile has no horizontal overflow. No browser script errors. This does not establish live endpoint authorization or save/reload on staging.

After replacing beta12 on staging, check that content starts beside the menu at wide widths. Click every new-menu item, then Branding → collapse its section → Branding again; it must reopen and scroll into view. Check Performance, Packet courses, Training settings, browser back/forward, keyboard activation and narrow widths. Confirm unchanged native WordPress menu, notices, save/reload and unsaved-change warnings. No training data or calculation changes are part of this correction.

# Beta 12 — agency-confirmed training and settings cleanup

Run `php tests/test-agency-training.php`, `php tests/test-settings-console.php`, and `node tests/test-settings-console.cjs`. Install on staging only. Settings → Training periods & agency delivery: enable ISP/Communication delivery and review default hours. Save/reload. Users → Edit → Agency-delivered training: enter the exact inclusive annual/custom-report dates; select completion for each applicable training and check the declaration; Update User. Generate a fresh packet with matching dates. Expect 1 ISP topic hour and 1 additional Communication Profile hour when neither has other credited evidence; no credit before confirmation, no duplicate ISP course credit, no certificate fetch for agency-held records. Existing annual-cycle configuration and orientation behavior remain unchanged.

Verify wrong dates do not reuse credit, different employees remain isolated, changing defaults does not rewrite confirmations, stale forms are rejected, withdrawal retains attributed history and removes credit, and unauthorized users cannot read/save confirmations. Verify save/reload with real WordPress, final PDF pagination/certificate pages, desktop/mobile navigation, deep links into collapsed sections, keyboard access, invalid inputs revealed before validation focus, all native settings forms and dirty-change prompts. No Jotform calls or messaging tests. Browser execution was blocked by npm-cache EPERM; synthetic UI tests are not visual acceptance. The layout addresses sidebar/cards/save controls, not a full replacement of every review workflow or a production-readiness claim.

# Beta 11 — settings UI and selected packet rows

Run `php tests/test-packet-course-selection.php`, `php tests/test-settings-console.php` and `node tests/test-settings-console.cjs`. On staging verify native admin scheme colors, every existing form's save behavior, permission-specific navigation, mobile/keyboard layout and unsaved changes across the two Settings forms. Select separate annual/orientation LearnDash and external courses; save/reload/clear; generate fresh annual, custom-date and orientation packets. Confirm selected rows follow ODP topics and do not increase totals. No included evidence must not imply regulatory failure. Check long course names, many rows, actual PDF page breaks and certificate pages. The requested explanation paragraph is absent, but reviewed dates/report-only semantics remain. See beta11 handoff for precise implementation scope and browser blocker; this is not a production-approved release.

# Beta 10 — unchecked completion

Run `php tests/test-course-incomplete.php`. On staging, reopen the drawer after installing beta 10, uncheck a completed synthetic course, leave its prefilled date, and save. Confirm incomplete after reopening drawer and refreshing course page; generate a new packet to confirm LearnDash course/hours/certificate exclusion. Inspect correction history attribution, preserved quiz attempts and unrelated courses. Check rechecking with a valid date, permission denials and omitted fields. Test the explicit quiz-only review message. Independent approved external training is not revoked by this checkbox. See docs/beta10-incomplete-checkbox-handoff.md; synthetic handler checks are not installed-site/browser acceptance.

# Beta 9 — external annual category regression

Run `php tests/test-external-annual-category.php`: 265 cumulative assertions. Five synthetic approved ODP Annual Training entries must reach details and five unique certificate references in orientation, annual and custom-date modes; 5.5 hours are additional, not specific-topic hours. Existing opt-in and date filters remain enforced.

Install beta 9 on isolated staging and regenerate affected packets. Verify every external course row and all five readable certificate pages (including uploaded JPG converted to PDF). Check orientation timing against actual dates, not the previous incomplete packet. Review whether external evidence duplicates an existing LearnDash completion before relying on aggregate hours. Full rendered PDF merging remains a staging acceptance check, not established by the calculation harness.

# Beta 8 — external orientation evidence acceptance

Install `Gridhouse-admin-compliance-dashboard-1.7.3-beta.8-test-only.zip` on isolated staging, replacing the existing plugin rather than activating a duplicate. Use synthetic employee/certificate records only; do not call or submit Jotform or enable messaging.

1. Upload a synthetic JPG/PDF through the existing manual External Training flow. During approval, select **Also applies to orientation for this employee**. Generate Orientation: confirm topic/hour/date and attached certificate, including a completion outside the current annual window. The JPG is normalized into a PDF page by the existing storage flow.
2. In External Training Review → Active approved evidence, mark an existing approved record, enter an orientation review reason and save applicability. Generate a NEW Orientation packet; no re-upload is needed. Unmark and regenerate to confirm exclusion. Existing downloaded packets do not change.
3. Confirm missing flag, pending, revoked and unapproved entries stay excluded; stale forms fail. Existing certificate files, hours and previous snapshots must not be rewritten. Verify nonce/role protection with an unauthorized account. No current-cycle applicability exemption is implied.
4. Check annual/default and custom-period packets remain unchanged, including beta 7's three-row totals and short ISP note. Orientation has no annual-window filter. Zero-hour marked supporting records do not award hours or assert topic completion; the existing new-training approval rules are unchanged.
5. Check real PDF pagination, image readability and merging on staging. Development checks exercise synthetic calculation/manifest selection, repository transactions and encrypted-file materialization, not the installed WordPress runtime. Browser/runtime acceptance remains pending; no automatic production approval.

Development verification: orientation calculation suite passes 248 cumulative checks; orientation revision suite passes 18 transaction/rollback/history checks. The older external-evidence suite has a pre-existing hardcoded 1.7.1 release assertion incompatible with these beta versions; its other assertions pass. See docs/beta8-orientation-external-handoff.md.

Install `Gridhouse-admin-compliance-dashboard-1.7.3-beta.7-totals-1-test-only.zip`. Generate both a custom-date packet and a default-cycle annual packet. Each must show 6100 topic training hours, Additional training hours and Total recorded training hours with the existing calculated values, including zero values. Total is the sum of the first two, with no `/ 24 Required` suffix. Default-cycle annual assessment remains above the matrix; custom reports still do not assert annual compliance. Previous shortened ISP note and paragraph removal remain. Orientation/OLTL are unchanged. Synthetic cover/API regression and PHP syntax checks apply; real PDF pagination remains staging acceptance. Older package instructions below are historical.

Install `Gridhouse-admin-compliance-dashboard-1.7.3-beta.7-wording-1-test-only.zip` and regenerate a custom-period packet. Confirm the Topic coverage reflects mapped evidence paragraph is absent, and the manual ISP note reads exactly: ISP training delivery: Supporting records are maintained by the agency and provided separately for audit. Confirm dates, hours, statuses and certificates are unchanged. Standard annual packets also use the shortened ISP note; their existing topic-status explanation remains. The report still identifies the actual selected dates, so later completions are not represented as inside an earlier audit window. Earlier acceptance instructions below remain applicable.

Install `Gridhouse-admin-compliance-dashboard-1.7.3-beta.7-test-only.zip` over the existing plugin on isolated staging; do not activate a duplicate copy. Retain beta 6 as rollback. No production install or integration testing is authorized by this document.

1. Open an employee drawer → Administration → Edit Records & Packet Generator. Under Annual packet period, choose **Choose audit reporting dates**. Enter **September 1, 2025** and **August 31, 2026**; click **Annual**. The browser may display dates using its locale.
2. Confirm the newly generated PDF is labelled **Audit Reporting Period — Training Evidence**, shows both dates, and includes available July 2026 mapped completions and their corresponding certificates. Cross-check totals against the actual saved completions, including August 31 and excluding September 1, 2026. Do not change dates to force a result.
3. Confirm no Overall Annual Status or `/ 24 Required` is applied to the custom range. Review missing/invalid evidence warnings, course details, certificate dates, wrapping, pagination and merge. Supply agency-held manual ISP records separately.
4. Check blank/invalid/reversed ranges cannot start a job. Switch back to Current annual training cycle: fields disappear/disable and the ordinary anniversary packet remains unchanged. Orientation and OLTL must not receive custom dates. Dates reset when drawer content reloads; they are not saved agency policy.
5. Permissions, cancellation, changed-record abort, and zero-certificate reporting should remain safe. Do not call or submit Jotform, enable messaging or use real records as fixtures. Existing locally approved external evidence is read only.

Development verification: 18 targeted PHP scripts pass; new reporting suite has 212 cumulative checks, including actual AJAX init/merge guard execution with synthetic dependencies. PHP lint and JavaScript syntax pass. Captured cover HTML is tested, not actual TCPDF pagination. Browser verification was attempted but blocked by the browser tool's approval/usage limit; drawer layout, real WordPress integration, certificate retrieval/merging and mobile acceptance remain **unverified**. This package does not repair the separate intermittent certificate retrieval problem or provide frozen historical evidence.

## Prior beta 6 acceptance history

## Current beta 6 manual-delivery revision 2 — annual summary correction

Install `Gridhouse-admin-compliance-dashboard-1.7.3-beta.6-manual-delivery-2-test-only.zip`, retaining the previous archive. Keep the existing ISP training delivery checkbox enabled and generate a NEW annual packet. With sufficient recorded hours, all five other required topic statuses resolved and no evidence issues, expect **Annual Training Summary: Recorded hours and other required topics met — ISP handled manually / in person** instead of the generic topic-review banner. The agency-records/non-verification note remains. Confirm other topic gaps, missing dates/evidence, insufficient hours and checkbox-off retain their old warnings. Orientation, CSV, hours and internal compliance status must not change. Test actual PDF wrapping and certificate merge on isolated staging without Jotform calls. `php tests/test-manual-isp-summary.php` passes 175 cumulative synthetic checks (23 new).

## Current beta 6 manual-delivery revision 1 — simple agency setting

Install `Gridhouse-admin-compliance-dashboard-1.7.3-beta.6-manual-delivery-1-test-only.zip` over the existing plugin on isolated staging. Do not activate duplicate copies.

1. Open Gridhouse Compliance → Settings → ISP training delivery. Check **ISP implementation is handled manually / in person by this agency** and Save Changes. Reload to verify persistence. Existing Annual Training Cycle stays unchanged.
2. Generate a new annual packet for a synthetic employee without a per-employee ISP review. The ISP row must read **Handled manually / in person**, followed by the short agency-records note below the matrix. No training-detail or verified-date entry is needed to enable this label; missing date evidence can still affect the underlying calculation.
3. Hours, courses, certificates, topic decisions and overall status must match the result with the checkbox off. Needs review may remain: delivery method is not a completion decision. Check CSV contains the delivery label and non-verification note.
4. Confirm orientation is unchanged; an explicitly reviewed employee Not applicable remains visible. Uncheck/save and confirm prior reporting returns with no evidence deletion.
5. Verify only Settings-authorized administrators can save, and the standard WordPress Settings nonce remains required. Supply actual supporting records separately via the agency's approved secure audit channel.

Run `php tests/test-isp-manual-delivery.php` for synthetic tests. Actual WordPress save/reload and real TCPDF pagination/merge require staging checks. Prior instructions below describe the optional per-employee workflows, not prerequisites for this agency setting.

Install `Gridhouse-admin-compliance-dashboard-1.7.3-beta.6-test-only.zip` on isolated staging, replacing the existing plugin rather than activating a second copy. Retain beta 5 for rollback. No automatic live installation was performed.

1. As an administrator, open Users → Edit → ODP annual applicability review → ISP implementation — agency-held evidence for a synthetic employee with a verified hire date and configured cycle.
2. Select **External evidence — agency review required**. Leave training details blank if not entered; no verification confirmation is needed. Save/reload. Do not select Not applicable to bypass evidence. Existing reviewed applicability choices still require their reason.
3. Generate the annual packet without Jotform calls. The ISP row must use the new label. A separate note must say evidence is unverified, not attached and must be supplied separately; overall status remains unresolved, not Compliant. Hours and included course evidence must be unchanged. Verify PDF wrapping/page flow in the actual PDF engine.
4. Check CSV includes the status and disclosure. Check ordinary course, pending/submitted and verified modes remain unchanged; unrelated employees and orientation must not get this note. Existing beta 5 managed selections should show the beta 6 wording.
5. Confirm authorization, nonce, stale-tab and period checks still reject invalid saves. Supply actual supporting records through the agency's approved secure audit channel outside the plugin; this release does not guarantee audit acceptance or establish that a receipt-only acknowledgment proves training.

Automated verification: `php tests/test-beta6-external-evidence.php` exercises 128 cumulative synthetic checks (10 new). Actual WordPress save/reload and TCPDF rendering/merging remain staging acceptance items.

# 1.7.3-beta.5 — staging test package

## ISP review 1 addition — test together with original beta 5

Install `Gridhouse-admin-compliance-dashboard-1.7.3-beta.5-isp-review-1-test-only.zip`. Plugin version remains 1.7.3-beta.5 as requested; distinguish this build by the filename, manifest/hash and new ISP evidence section. It contains all prior beta changes. Keep the original ZIP; do not install a second active plugin copy.

1. Use a synthetic employee with an existing verified employment date and saved cycle. As an administrator, open WordPress Users → Edit → ODP annual applicability review → ISP implementation — agency-held evidence.
2. Select External evidence — agency review required (beta 6 label for agency-managed evidence). Save/reload and generate an annual packet/CSV. ISP must remain in the matrix and show the agency-managed review state, not N/A or an automatic failure. Use local synthetic certificates only; do not trigger Jotform to generate a packet.
3. Choose Evidence submitted — pending review. Enter fictional opaque evidence and assigned-plan register/version IDs, for example SYN-EVID-001 and SYN-REGISTER-v1. No file is uploaded by this form. Underlying signed forms and participant/plan details remain in the agency's secure records. Save/reload; submission must not imply verification or add hours.
4. Set Individual Plan applicability to Applies with a reason. Enter actual synthetic training date inside the current period, positive documented duration in minutes, trainer/source reference, training content and review explanation. Choose Completed — manually verified and explicitly confirm review. A signature alone must not pass. The referenced register must identify all relevant individuals/plan versions and the employee's training evidence; the software does not discover assignments or inspect the documents.
5. Confirm PDF and CSV show Completed — manually verified and a reference-only/no-automatic-hours note. No participant/register IDs or source documents should be emitted in those outputs. Existing course credit totals must remain unchanged; this workflow contributes no hours or course/certificate entry. Separately credit actual eligible training only through a reviewed existing training-record workflow, never by estimating signature time.
6. Reject absent fields/confirmation, invalid/future/out-of-period dates, zero duration, public URLs as reference IDs, unauthorized actors, invalid nonce, stale period and stale revision. Open two profile tabs: after one changes the review, the other's save must require reload. No failed save should append a review or erase earlier evidence.
7. Return verified evidence to pending when a gap/assignment/plan change needs review. Prior verified metadata must remain stored, but a generic completed course must not override the pending manual state. A new training period must not inherit prior verification. Unchanged verified saves must preserve reviewer/time without duplicate rows. To resume course-based evaluation, explicitly choose Use course evidence; prior revisions remain stored.
8. Confirm non-administrators cannot access or save these review controls, and that the existing annual cycle, orientation, OLTL, dashboard/drawer, external training and all original beta 5 tests remain unchanged.

The client's actual blank ISP acknowledgment form has not been reviewed; this feature does not certify its sufficiency. Real WordPress/browser/PDF acceptance remains pending. No participant information should be entered into synthetic tests.

## Beta 5 acceptance (synthetic staging only)

- Audit Mapping: retain an existing primary category and hours. Select an additional category and provide a syllabus/content reference. Save/reload. Without a reference, the previous course mapping must remain unchanged and a settings error must be shown. Confirm OLTL mappings remain unchanged.
- Complete a synthetic two-hour course mapped to two topics inside the existing annual window. Both topics should show Completed, the primary topic should receive two hours, the additional topic zero counted hours, and the total should be two. One course/certificate should be included, with both categories on its detail page. A zero-hour completion may cover a topic; an invalid or out-of-period date must not.
- An unmapped topic must show Needs review, not No or an invented exemption. A mapped, applicable topic without qualifying evidence must show Missing training. Reaching 24 hours with unresolved annual topics must not display overall Compliant.
- As an administrator, open WordPress Users → Edit → ODP annual applicability review. For the two conditional annual topics, record Applies or Not applicable with a reason/source. Save/reload; PDF and CSV must include the decision and reason. Earlier review metadata must remain stored. No automatic exemptions based on role or Employment Type.
- Reject missing reasons, invalid states, invalid nonce, non-administrator access and stale period submissions. A review from another period must not apply. An unchanged save must not append a duplicate review. Use synthetic accounts; do not change the actual agency cycle as a test.
- Review the LearnDash description preview and source link in Audit Mapping. Correct inaccurate source descriptions only after checking actual course content. The previously reported abuse/communication mismatch and incident description have NOT been corrected on a live site by this package.
- Confirm the existing cycle, hire-date window, dashboard/drawer layout, external single-category evidence and OLTL behavior remain unchanged. CSV topic values now contain descriptive statuses rather than Yes/No; check downstream import consumers.
- No Jotform, email or SMS calls, training resets, archive changes or production installation.

Standalone tests validate calculation, rejection paths and captured PDF HTML. Actual WordPress save/reload, browser rendering and real PDF/certificate acceptance remain required before production use.

Not a production release. Prepared for the designated local or explicitly approved staging test environment; product features are agency-independent. This package has not been installed or browser-tested by the assistant.

## Beta 4 annual-cycle acceptance

- On an isolated synthetic site without a saved cycle, Settings and Agency Profile must show Not configured. Upgrading alone must not select an agency policy.
- Select Employee Anniversary and save once. Reload, confirm the database option `ghca_acd_annual_cycle` is `employee_start_date`, and confirm the packet calculator reads that value. Repeat on a separate fresh fixture with Calendar Year (`calendar_year`).
- Repeated saves and unrelated settings saves must preserve the policy. Invalid or blank policy submissions must not erase a supported saved value. Existing valid settings must survive the upgrade unchanged.
- With no supported cycle, an ODP PDF must show Not configured rather than Employee Hire Date. Assessment must remain blocked, not silently defaulted. With a saved cycle and verified employment date, confirm the expected annual window and eligible training credit using synthetic local evidence only.
- Do not toggle a real agency to another policy as a workaround. The bug fix requires one explicit save of its intended policy where the prior version never persisted it.
- Standalone regression checks model WordPress default/equality behavior and capture PDF HTML. They do not replace a real Settings save/reload or browser/PDF acceptance test. No Jotform, email or SMS calls are authorized by this checklist.

## Before installation

- Keep a fresh local database/file backup and the verified 1.7.2 ZIP. Private-evidence and full application restoration remain unverified.
- Keep the test site's existing mail-blocking MU plugin in place. It is intentionally not bundled. This package does not disable external HTTP, direct SMTP, scheduled jobs or third-party integrations. Complete isolation before record-changing tests; do not rely on the nonfunctional Twilio account as a security control.
- Jotform is excluded from testing: no submissions, synchronization, API calls or configuration changes. Do not send email/SMS, run integration tests or reset training progress.
- Use only confirmed synthetic staff. Do not change the MCP connection account. Existing active/inactive access and role scope must remain unchanged.

## Install on the designated test site only

1. Confirm the browser is on `https://prolific-homecare.test` or the explicitly approved staging site, never production.
2. In WordPress Plugins → Add New → Upload Plugin, choose this ZIP. When WordPress identifies the existing Gridhouse Admin Compliance Dashboard, replace that plugin; do not install a second active copy. Stop if a different plugin is identified.
3. Confirm the installed version is **1.7.3-beta.5**. During deployment, purge any full-page, CSS optimization and CDN caches that retain old HTML or ignore asset version parameters. Clients should not need a hard refresh when current HTML is served and asset query parameters are respected.

## Verify the beta 2 cache correction

- Open the dashboard with a normal reload after upgrading. In browser Network, dashboard.css and dashboard.js should each have a `ver=1.7.3-beta.5-<timestamp>` URL.
- Open the employee drawer and confirm normal icon sizes, spacing and layout. There are no CSS/design changes in beta 2.
- Test with a browser that previously loaded beta 1. If old URLs are still served, inspect cached HTML/optimization output and purge it at deployment; do not ask every client to hard-refresh.
- This change covers the shared dashboard CSS and JavaScript only. It cannot force a cache that ignores query parameters or refresh an already-open page without navigation/reload.

## Test Employment Type

1. Open Manage Users → Edit for a synthetic employee. An unclassified record should show Not set.
2. Save Full-Time, reopen and confirm it persists.
3. Open the employee drawer → Administration → Edit Records. Change to Part-Time, save and confirm the Employment Status card/badge shows Part-Time alongside Active or Inactive.
4. Repeat with Contractor and then Not set. Refresh/reopen both editing paths; values should agree.
5. Confirm account activity, permissions, group assignments and existing training records were not changed. Adding a new synthetic employee with a type is a separate test, only after outbound isolation is established.
6. Test with an appropriately restricted account: no unauthorized or out-of-scope editing should be possible.

## Test Agency Profile

1. As an administrator, open the existing compliance admin menu → Agency Profile.
2. Enter fictional agency services, provider model, job duties and policy references. Save and reload.
3. Confirm the profile remains Needs review. These are descriptive intake fields, not executable rule mappings; they do not change calculations or establish regulatory approval.
4. The displayed existing annual-cycle setting is reused, not replaced. Do not change it as part of this basic smoke test.

## Included changes and limits

### New in beta 3: verified employment dates

1. For a synthetic employee, open the drawer → Administration → Edit Records. Account Registration Date is separate from Verified Employment Start Date; do not change registration for this test.
2. Enter the actual synthetic employment start date, a synthetic source reference, and check the verification confirmation. Save and reopen; date/source should persist. Verifier/time are recorded server-side.
3. Change date/source without checking verification: saving should be rejected. Future/invalid dates and a missing source should also be rejected. Blank inputs must not erase an existing verified date.
4. Confirm the ODP Audit Data table and ODP CSV/packet hire date use the verified date rather than account registration. Use only synthetic local evidence; do not call Jotform to populate a packet.
5. For a synthetic employee with no verified date, ODP annual dates should remain blank with missing evidence rather than inventing a window. An absent or unsupported saved cycle also blocks annual assessment. Existing staff are not automatically backfilled.
6. Dashboard deadlines and OLTL still use earlier date paths, so results may differ from ODP. Do not use this prerelease for operational compliance decisions. Actual WordPress/browser acceptance remains pending.

- Employment Type: Full-Time / Part-Time / Contractor, shared editing and display; last editor/time stored with the value. No automatic classification of existing staff.
- Agency Profile intake: bounded text, policy effective date, administrator-only saving and validation.
- Phase 1A: missing/invalid training completion dates are no longer fabricated; affected CSV/PDF results report missing evidence and exclude undated certificates. Do not interpret this beta's packets as verified audit conclusions.
- ODP verified hire-date integration is included. Dashboard/OLTL date reconciliation, structured applicability rules, browser acceptance and Phase 0 closeout are not complete.
- Known existing PHP 8.5 warning: CSV fputcsv implicit escape deprecation.
- Baseline Jotform/Twilio modules remain unchanged in the runtime bundle; they were not called during packaging. Merely installing this ZIP does not isolate them.

## Rollback

Replace this beta with the retained, verified 1.7.2 ZIP. Do not uninstall/delete the plugin or reset learner records. A code rollback does not revert database edits. New Employment Type user meta and Agency Profile options remain stored but unused by 1.7.2; restore data separately only through a reviewed backup-recovery procedure if needed.

Verified employment-date metadata also remains after code rollback. Earlier betas/1.7.2 ignore it and return to their earlier date calculations. Retain the beta 2 ZIP as a code rollback option; do not delete date or training evidence to roll back.

Report the tested page, action, expected result, actual result and a redacted screenshot. Do not include passwords or personal records.
