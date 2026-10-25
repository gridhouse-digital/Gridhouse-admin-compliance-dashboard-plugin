# Annual-cycle persistence correction — beta 4

## Goal and confirmed cause

The staging beta 3 database had no `ghca_acd_annual_cycle` row. Settings registered `employee_start_date` as its default, so WordPress's equal-value early return could skip the first anniversary save. The explicit calculator reader correctly returned empty, while Settings/Agency Profile and the PDF presented an anniversary policy. Read-only runtime inspection confirmed this mismatch; no save was submitted during diagnosis.

## Changes

- `includes/class-settings.php`: empty registration default, explicit selection in Settings, supported-value sanitizer preserving existing valid policy on invalid/omitted input, shared display label and truthful Agency Profile label.
- `includes/class-audit-pdf.php`: ODP/OLTL cycle labels distinguish empty/unsupported input. No calculator, certificate broker, permissions or integration logic changed.
- `tests/test-annual-cycle-settings.php`: Settings API model detects old failure and checks both first saves, repeated saves, policy changes, invalid/omitted input and labels.
- `tests/test-audit-date-evidence.php`, `tests/test-agency-profile.php`: captured HTML checks for missing-cycle labels.
- Entrypoint, README and TESTING: beta 4 version and acceptance instructions.

## Verification and limits

Nine selected standalone test scripts passed on PHP 8.3.30: annual-cycle settings, audit-date evidence, agency profile, audit calculator, asset version, employment type, verified employment, PDF jobs and OLTL readiness. Runtime lint passed for 102 PHP files; dashboard.js syntax check passed. The annual-cycle, audit-date and agency-profile tests also passed on PHP 8.5.7; existing CSV fputcsv escape deprecations remain in unchanged export code.

These are synthetic/model and captured-HTML checks, not browser or live WordPress save verification. No site deployment, policy backfill, packet regeneration, Jotform, email or SMS calls were performed. The developer copy has no Git repository; compare released runtime entries against beta 3 during packaging.

## Remaining acceptance

Install beta 4 only on an approved isolated test site. If policy was never persisted, explicitly choose the intended policy and save once. Verify its database value and a reload, then validate the annual window and included evidence with synthetic local certificates. Repeat both first-save cases on independent fixtures; do not temporarily switch an agency policy as a workaround. Existing valid policies must remain unchanged. Browser and actual PDF acceptance remain required before production approval.

The legacy `get_annual_cycle()` fallback is retained for the existing OLTL calculation path; broad dashboard/OLTL date-policy reconciliation is outside this correction. Upgrade does not infer or backfill a policy from prior screen defaults.
