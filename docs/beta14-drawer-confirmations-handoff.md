# Beta14 — employee drawer confirmations

## Delivered

- `includes/class-agency-training.php`: existing validated, attributed, append-only confirmation path shared with a nonce-protected drawer endpoint. Dashboard edit permission AND existing employee scope are required on every load/save. Profile access remains manage_options plus edit_user. Display saved status separately from action commands; reviewer/time, confirmed hours and full history remain visible. Exact reporting dates/default/history revisions are checked before writing.
- `includes/class-odp-applicability.php`: independent drawer advanced-review save for the current annual cycle only; all decisions and revision required. Existing legacy evidence preserved, direct legacy-evidence writes rejected from this endpoint, existing cross-conflict checks retained. Legacy detailed profile editor collapsed. Both profile writers now use profile_update after successful validation, not the earlier edit_user_profile_update/personal_options_update hooks.
- `includes/class-ajax-handlers.php`, `assets/agency-training.js`, `assets/dashboard.css`: scoped panel under Administration → Edit Records. Automatic load, explicit refresh, separate confirmation/applicability saves, date-selector synchronization, disabled pending/stale controls, error feedback and stale-response protection. No nested course form and no foundational-record submission. A save is not aborted when dates change; afterward the newly selected period is reloaded.
- `gridhouse-admin-compliance-dashboard.php`: beta14 version/cache key and dashboard-dependent script enqueue. `includes/class-settings.php`: guidance points agency admins to the drawer.
- Tests/README/TESTING and the vault roadmap/control updated. No migration, current employee update, source mapping/certificate/calculator/PDF change or integration call. Prior ZIPs retained.

## Root cause and decisions

WordPress user-edit invokes edit_user_profile_update/personal_options_update before edit_user runs user_profile_update_errors. The old writer appended history before the validator checked the submitted history hash, making its own submission look stale. Both writers now wait for profile_update. Validation remains read-only; errors before WordPress's update prevent these writes.

No change is an action, not a saved completion state. The new saved-status display prevents confusion. Defaults stay in Settings, employee confirmations stay exact-period scoped, and current-cycle applicability stays separate from arbitrary reporting ranges. Confirmed hours are labeled as such rather than falsely promising additive credit when packet duplicate/exemption rules suppress it. Agency-held documents are not automatically uploaded or verified. The full legacy workflow is retained for Gridhouse support, not deleted or auto-converted.

## Verification

- `php tests/test-agency-drawer.php`: 345 cumulative checks, including earlier calculator/PDF-HTML/packet/external-evidence assertions and new hook-order, permission, scope, nonce, exact-date, withdrawal, stale-revision, retained-evidence, invalid-profile and storage-failure assertions.
- 160 PHP files linted successfully; agency JavaScript syntax passes. 64 cumulative incomplete-course checks, 27 annual-cycle settings checks, 47 profile checks, 21 settings-console checks, both settings JavaScript scripts pass. Initial command used nonexistent test-annual-cycle.php; reran the actual test-annual-cycle-settings.php successfully.
- Browser plugin skill absent; used already-installed Playwright/Chromium, no dependency installation. Temporary runner `C:/Users/oyiny/AppData/Local/Temp/ghca-agency-drawer-check.cjs` uses actual PHP-rendered forms and both dashboard/agency JavaScript assets with intercepted local fictional AJAX responses, not a live site. Page identity/nonblank, no framework overlay, independent save/reload/withdrawal, date switching, advanced save, failure/recovery, no console/page errors, desktop 1440×1000 and mobile 390×844 without overflow passed. Screenshots: `ghca-agency-drawer-desktop.png` and `ghca-agency-drawer-mobile.png` in the same temporary directory. Test nonce fixture now matches real hidden WordPress nonce fields.
- Workcopy has no Git metadata. Release manifest/hash comparison is used for packaging parity. The previous beta13 package is not overwritten.

## Remaining / acceptance

Package: `../packages/Gridhouse-admin-compliance-dashboard-1.7.3-beta.14-test-only.zip`, 118 entries, 421980 bytes; all archive entries hash/size verified against source and `1.7.3-beta.14-manifest.csv`. SHA-256: `07784CD4F1B7FD2C466084E3E86698A4CDDFACFA98E15013EE626A2A2B3720B7`. Beta13 comparison: new agency-training.js plus the six runtime files listed above and README/TESTING; no prior entry missing. Dashboard.js, calculator, PDF, certificate and integration sources are unchanged. Tests and handoff docs stay in the workcopy, following the prior package convention.

Install beta14 on isolated staging and run TESTING.md, especially delegated role scope, full native WordPress profile lifecycle, actual save/reopen, site theme/plugin interference and fresh PDF output. Synthetic verification is not live persistence or production acceptance. No Jotform interaction, outbound-message test or live installation was performed. Annual policy, orientation credit behavior and archive/API roadmap scope remain unchanged.
