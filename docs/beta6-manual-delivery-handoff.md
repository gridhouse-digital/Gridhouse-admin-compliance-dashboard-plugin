# Beta 6 manual-delivery revision 1

2026-09-17. Replaces the temporary per-employee setup recommendation with the user-approved simple agency-level reporting option. Existing review records/workflows are retained, not deleted.

## Changed

- `includes/class-settings.php`: opt-in `ghca_acd_isp_manual_delivery` checkbox in existing protected `ghca_acd_settings` Settings API group, default off, strict 0/1 sanitization, no REST exposure. Uses existing options.php capability/nonce boundary and add/update cache invalidation hooks.
- `includes/class-audit-calculator.php`: annual-only display flag and shared PDF/CSV delivery wording. Does not alter any calculated hours, topic states, completion decisions or period inputs. No employee review/date prerequisite for the flag. Explicit reviewed Not applicable remains visible.
- `includes/class-audit-pdf.php`: Handled manually / in person row and short agency-records note take precedence over the earlier long external-evidence disclosure when enabled. Overall status and hours remain as calculated.
- README and TESTING give the one-time Settings route. Version stays 1.7.3-beta.6; distinguish this revision by filename and manifest. No JS/CSS edits, migration, automatic opt-in, integration changes or site deployment.

## Verified

16 targeted PHP scripts passed and 147 development PHP files linted on PHP 8.3.30. `tests/test-isp-manual-delivery.php`: 152 cumulative checks (24 new), covering default/explicit on/off, strict sanitizer, protected Settings registration, no employee review record, missing date/cycle, unchanged calculation output, orientation exclusion, N/A preservation, PDF and CSV output. Synthetic browser inspection of actual cover HTML confirmed readable matrix label and short note; uses stubs/preview CSS, not TCPDF. Preview tab/server stopped.

## Remaining

On isolated staging install the revised ZIP, check Gridhouse Compliance → Settings → ISP training delivery, save/reload and regenerate an annual packet. Actual Settings persistence/authorization runtime and TCPDF pagination/merge remain unverified. Existing cycle stays in place; date/cycle evidence can still affect calculations, but does not block this delivery label. Overall Needs review may remain. Supporting records are supplied separately, and delivery method is not verified employee completion or audit acceptance.

Rollback: uncheck the option to restore existing report wording without deleting evidence. Earlier code ignores the new option; prior ZIPs are retained. No Git status/diff available because the isolated working copy is not a Git repository; package manifests are used for comparison.
