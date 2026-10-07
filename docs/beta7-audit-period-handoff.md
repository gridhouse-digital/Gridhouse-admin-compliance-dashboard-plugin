# Beta 7 — selectable audit reporting period, totals revision 1

Totals revision 1: same three-row hours table for custom-date and default annual-cycle PDFs, using existing total_annual_hrs, additional_hrs and total_hrs values. Labels match user request; recorded total has no 24-hour suffix. Default annual status logic/heading unchanged; custom reports remain reporting-only. Earlier wording changes retained. Only PDF presentation, tests and documentation changed. Reporting suite passes 231 cumulative checks (including decimal/zero values in both modes and orientation regression); PDF PHP syntax passes. Package: `Gridhouse-admin-compliance-dashboard-1.7.3-beta.7-totals-1-test-only.zip`. Real PDF pagination still requires staging inspection.

Wording revision 1 (2026-09-18): user requested removal of the custom-report topic-coverage paragraph and shortening of the manual ISP delivery note to the agency-records sentence. Implemented only in PDF presentation; calculations, totals, statuses, CSV, dates and evidence untouched. Includes original beta 7 changes. `tests/test-audit-reporting-period.php` passes 213 cumulative synthetic checks; changed PHP file syntax passes. Real PDF pagination remains staging verification. Use `Gridhouse-admin-compliance-dashboard-1.7.3-beta.7-wording-1-test-only.zip` and adjacent manifest; original beta 7 ZIP retained. Regenerate packets to see changes.

Implemented 2026-09-18 in the isolated Phase 1 working copy. User authorized a drawer date-range selector for client reporting September 1, 2025–August 31, 2026. Those dates are not hardcoded or saved as policy.

## Changed

- `includes/class-audit-calculator.php`: optional reporting period, strict real-date validation, inclusive local-day boundaries, existing completion filters reused for LearnDash and approved local external snapshots. Reporting-only topic/status semantics; current-period applicability reviews excluded.
- `includes/class-audit-pdf.php`: protected init accepts annual-only dates; summary and certificate manifest derive from the same calculation. Merge uses server-bound dates, compares the current calculation to the stored snapshot, and aborts if changed. Range labelled in cover/filename; no annual verdict or 24-hour target.
- `includes/class-audit-pdf-jobs.php`: optional short-lived server-calculated report snapshot in existing owner-bound manifest. Existing auth, ownership, TTL, private storage and locking retained.
- `includes/class-ajax-handlers.php`, `assets/dashboard.js`, `assets/dashboard.css`: native selector/date inputs in existing packet panel, client validation and annual-only serialization. No dashboard redesign.
- `gridhouse-admin-compliance-dashboard.php`: beta 7 version/cache key. README, TESTING and vault plan/control updated; earlier ZIPs unchanged.

## Decisions and limits

Default current annual cycle unchanged; CSV, orientation, OLTL, hire dates and saved policy unaffected. Reporting dates are per generation and reset on drawer reload. Current agency manual ISP-delivery disclosure still applies as description, never verified completion. No annual compliance determination is inferred for arbitrary ranges. Available current completion records, mappings and certificate sources are used; this is not historical snapshot recovery or the dual-layer archive. External date-only completions use the site calendar for custom ranges; legacy default behavior retained. Job snapshot is transient consistency protection, not permanent audit history. Existing 40-certificate/size/page limits remain. No site install, employee data changes, outbound messaging or Jotform operations.

## Verified

- 18 targeted scripts passed, including `php tests/test-audit-reporting-period.php` (212 cumulative checks, 37 new): inclusive boundaries/July, invalid and reversed dates, missing hire/policy, invalid completion evidence, external date inclusion, unchanged defaults, cover HTML semantics, API nonce/role/scope, manifest owner and period binding, changed-record merge rejection, replacement client dates ignored.
- PHP 8.3.30 syntax checks and Node dashboard JavaScript syntax check passed.
- Release uses prior manifest's runtime paths, with source-to-ZIP hash verification. No vendor/library changes or new dependencies.

## Remaining

Browser automation blocked by approval review usage limit, not a test failure. Real drawer layout/interaction, mobile viewport, WordPress integration and actual TCPDF pagination/certificate merge remain staging acceptance. Synthetic cover tests capture HTML only. Follow TESTING.md before using client packets; existing certificate retrieval failures are a separate issue. No claim of regulatory acceptance or production readiness. This checkout has no Git metadata; package manifests are used for scoped parity checks.
