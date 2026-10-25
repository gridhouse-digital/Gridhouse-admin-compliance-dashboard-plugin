# Beta 5 — topic coverage and review states

Date: 2026-09-17. Test prerelease only; no deployment or production approval.

## Current addition: ISP review 1 (same beta 5)

User authorized combining agency-managed ISP evidence handling with beta 5. Keep the ISP requirement row; no exemption follows from manual handling. Extend the existing user-profile review rather than adapting the positive-credit certificate-upload workflow (`ajax_manual_upload` requires an active catalog entry with hours > 0). No Jotform code or integration behavior changed.

- Added `includes/class-isp-evidence-review.php`: bounded review fields and states; secure opaque evidence/register references instead of storing/uploading participant forms. Training source/content/date/duration and reviewer explanation plus explicit confirmation are required for new or changed manual verification. References must point to an agency-held scope register identifying relevant individuals and plan versions; the plugin does not independently verify that register or discover assignment changes.
- Extended `includes/class-odp-applicability.php`: reuse existing per-employee/current-period nonce/capability gates and append-only revision storage. Store `isp_evidence` within the existing decisions record. Protect manual submissions with a hash of the current review to reject stale-tab saves; preserve existing manual evidence when older submissions omit the field. For verification, ISP applicability must explicitly be Applies; manual handling cannot be combined with Not applicable. Reviewer/time are server-owned and preserved on unchanged saves.
- Extended `includes/class-audit-calculator.php`: agency-managed mode controls the ISP topic state even if generic course evidence exists. Verified manual evidence participates in the annual topic check but adds no credit, raw course or certificate. PDF/CSV automatically reuse shared category status/notes; they do not emit secure scope IDs or underlying documents. Course-based mode remains the default and can be explicitly restored without deleting historical revisions.
- `tests/test-isp-evidence-review.php`: 118 total synthetic checks including prior suites (29 new), covering all transitions, stale tabs, invalid evidence, immutable prior revisions, no hours, exports and captured form HTML. No actual form was inspected and no site data was changed. No new dependency, migration or document-upload endpoint.
- README and TESTING identify this build as ISP review 1 while retaining plugin version 1.7.3-beta.5. The original 111-entry beta 5 ZIP/manifest below remain immutable historical artifacts. Revised package verification follows after rebuilding.

**Revised package verified:** `../packages/Gridhouse-admin-compliance-dashboard-1.7.3-beta.5-isp-review-1-test-only.zip` (relative to plugin root), 392,411 bytes, 112 entries. SHA-256 `A530C42A3E1DB2083FC6267A49C842E4BD107079277EF16301AFD13AA305E526`. Adjacent manifest: `1.7.3-beta.5-isp-review-1-manifest.csv`. All entries match source; original beta 5 SHA-256 remains unchanged. Plugin version stays 1.7.3-beta.5.

**Final revised checks:** 14 targeted scripts passed on PHP 8.3.30 (the original 13 plus ISP evidence review); all 145 development PHP files passed lint. Synthetic in-app browser smoke at `http://127.0.0.1:8767/` confirmed meaningful form rendering, pending-to-verified selector change, duration entry and checkbox state; screenshot inspected. This used `tmp/isp-review-preview/index.php`, platform stubs and simple preview CSS, not WordPress styles, persistence or actual forms. PHP served the fixture successfully; its missing favicon returned 404. Temporary browser tab and PHP server were closed. Real WordPress save/reload, role checks, responsive/theme integration and PDF-engine/certificate acceptance remain pending.

**Limits:** reference-only manual review is implemented, not file submission/attachment, legal approval of the client's acknowledgment template, automatic per-person training tracking or the dual-layer archive. Re-review when assignments/plans change. Existing positive-credit manual external training remains separate; do not duplicate hours. Rollback to the original beta 5 ignores the new manual-review field and may resume course-only ISP status, so revalidate reports after rollback. Stored revisions are not deleted.

## Goal and scope

Implement the approved immediate corrections after review of the supplied QA&I C3Y2 training workbook: separate topic coverage from unique hours, replace ambiguous No / 0-of-0 displays, and expose course-description sources for review. Preserve the existing annual-cycle setting and hire-date/current-date/completion-date window logic. Historical packet selection stays with archive work unless separately needed earlier.

## Implemented

- `includes/class-audit-mapping.php`: existing scalar primary category remains the sole recipient of hours. Additional allowlisted/deduplicated categories share coverage, with a required course-content reference and server-side reviewer/time. Legacy mappings still work. Omitted courses are preserved, unauthorized/malformed saves preserve existing configuration, and an end-of-form marker rejects truncated Settings submissions. Repeated sanitization preserves zero orientation flags. Mapping UI previews the actual description and links to the LearnDash source editor. No title-based automatic mapping.
- `includes/class-audit-calculator.php`: coverage/date propagation across categories without duplicating raw course evidence or credited totals. Adds explicit topic status and reason fields. Existing Yes/No alias fields are retained for compatibility, but PDF/CSV render the new statuses. An hours-sufficient annual result with unresolved core topics is Needs review, not Compliant. Annual period resolution and thresholds are unchanged.
- `includes/class-odp-applicability.php`: WordPress Users → Edit review for the two conditional annual topics (behavior supports and individual plan). Requires manage_options, edit_user for the target, a user-bound nonce, current period and reason/source. Server actor/time and period are stored in append-only user-meta revisions; unchanged saves do not append. No inferred exemptions from Employment Type or roles. A new period requires a new decision. This is an administrative review record, not the dual-layer evidence archive or a full service/duty rule engine.
- `includes/class-audit-pdf.php`, `includes/class-audit-export.php`: Completed / Missing training / Needs review / Not applicable, with recorded applicability reasons. Annual matrix shows Hours Counted Here instead of treating catalog-hour sums as statutory topic minima. Course detail lists covered topics; a single completion remains a single evidence entry. CSV column positions remain, but Yes/No category values become descriptive statuses; downstream consumers need acceptance checks.
- Entrypoint version and asset release key: 1.7.3-beta.5. README and TESTING updated. No dashboard CSS/JS/design change, integration change, migration, learner-record rewrite or training reset.

## Storage and rollback

- Existing `ghca_acd_audit_mapping` option gains per-course `odp_categories`, `odp_mapping_reference`, `odp_reviewed_by`, `odp_reviewed_at`. Primary `odp_category` and credit_hours retain their meaning. Review metadata is last-change attribution, not immutable mapping-version history.
- `ghca_acd_odp_applicability_review` stores separate user-meta rows with period, decisions, reviewed_by and reviewed_at. No previous row is deleted. Only decisions matching the active employee period are used. Duties changes require explicit human re-review.
- A code rollback to retained beta 4 does not delete either metadata set. Beta 4 ignores additional coverage and applicability reviews, so category displays can differ while unique course hours remain primary-based. Do not uninstall/delete the plugin or erase evidence to roll back.

## Verification

- `tests/test-beta5-topic-coverage.php`: 89 checks including the reused 59-check baseline, on PHP 8.3.30. Covers unique hours/evidence, zero hours, legacy mappings, invalid/out-of-window dates, reference enforcement, unauthorized/truncated/repeated settings saves, N/A reason and period binding, revision retention, rejected nonce/state/capability/stale period, overall review status, CSV baseline and captured PDF/form HTML.
- Existing audit-calculator test updated only for the intentional PDF header contract change.
- Targeted baseline scripts: audit calculator/date evidence, annual-cycle settings, agency profile, asset version, employment type, verified employment, PDF jobs and OLTL readiness passed on PHP 8.3.30 before packaging. Final package verification is recorded in Implementation Control.
- No WordPress installation/save/reload, browser rendering, real TCPDF rendering, certificate retrieval/merge, remote permission test or deployment performed. Captured HTML is not browser acceptance. The isolated source copy has no running WordPress frontend; test installation and isolation are prerequisites for those checks.

## Original beta 5 package and checks (retained; use revised build above)

- Package (relative to the plugin root): `../packages/Gridhouse-admin-compliance-dashboard-1.7.3-beta.5-test-only.zip` — 387,577 bytes, 111 entries, all hash-matched to source. SHA-256 `F91E87AA8F99F88A9779154A9E6D1F91096C185C563E08B0E4D27C7FDD5F1E14`. Manifest: `../packages/1.7.3-beta.5-manifest.csv`.
- Final 13 targeted scripts passed on PHP 8.3.30: calculator, audit date evidence, beta 5 coverage, annual-cycle settings, agency profile, asset version, employment type, verified employment, PDF jobs, OLTL readiness, role permissions, fail-closed scoping and user visibility. All 143 development PHP files passed lint; mapping JavaScript passed Node syntax check.
- Beta 4 comparison: no removed paths, seven modified entries (entrypoint, four audit classes, README, TESTING), one new applicability class. All other bundled paths unchanged. Prior ZIPs retained. No site deployment or browser acceptance.

## Remaining and boundaries

- The incorrect abuse-course description and missing incident-course description are site-owned LearnDash data. This package adds visibility/source links, not invented replacement text. Inspect the actual syllabus and source course before correcting them; no site data was edited.
- External training remains single-category, with approved immutable snapshots unchanged. Cross-source duplicate evidence, retakes, historical rule snapshots, shared dashboard/OLTL date reconciliation and role-dependent hour thresholds remain outstanding. Additional topic coverage is not proof of individual-specific ISP instruction.
- Current generic 24-hour threshold remains an existing limitation, not verified universal support for all staff/provider models. Full Phase 1 and Phase 0 operational acceptance remain open.
- No Jotform calls/submissions/sync, messages, credentials, personal records or live-course content are included in testing or packaging.
- Follow TESTING.md on an isolated synthetic site, including core Settings persistence and WordPress user-profile authorization tests, before operational use.
