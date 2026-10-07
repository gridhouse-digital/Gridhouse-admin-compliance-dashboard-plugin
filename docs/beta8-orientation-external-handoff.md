# Beta 8 external orientation evidence

User approved inclusion of approved external training explicitly marked applicable to orientation, with JPG/PDF certificates. Includes all beta 7 revisions. No live-site changes or integration requests.

## Changes

- `includes/jotform/class-jotform-ui.php`: optional checkbox during approval; existing approved entries expose checkbox/reason/save form. Existing HTTPS, reviewer capability and nonce gates retained. Strict checkbox input and expected evidence revision bind the update.
- `includes/jotform/class-jotform-repository.php`: append reviewed snapshot revision in a transaction, preserving old snapshot and file manifest, recording reviewer/time/reason and event. Row locks, expected revision and conditional updates prevent stale overwrites. Roll back on event/storage/commit failure. Local evidence is re-verified without external calls. No schema migration.
- `includes/class-audit-calculator.php`: orientation consumes only approved snapshots explicitly marked boolean true. Existing packet manifest/materialization/merge handles stored certificates (including JPG normalized by existing upload code). Annual filters unchanged; orientation has no annual window. Valid zero-hour supporting records retain attachments but do not establish topic completion or timing. Missing dates fail closed. Positive-hour approved evidence contributes category hours/coverage and orientation completion date; outstanding mapped LearnDash requirements are not silently waived.
- Plugin release version beta 8; README/TESTING updated.

## Verification and limitations

`test-orientation-external.php`: 248 cumulative checks, 17 new orientation checks. `test-orientation-review.php`: 18 synthetic transactional checks including history, files/hours unchanged, opt-out, stale form, digest/file failures, missing reviewer/reason and rollback on each write/commit failure. Existing manual external-evidence test verifies encryption and exact packet materialization. JPG conversion code is unchanged, not newly runtime-rendered here.

Existing `test-jotform-external-evidence.php` fails its old hardcoded version 1.7.1 assertion; all other assertions pass. Do not change historical tests just to hide unrelated failures. UI rendering, actual WordPress authorization, DB concurrency and real PDF image conversion/merge/pagination remain staging checks. No claim that the previously reported site-specific missing-image issue is diagnosed or fixed by this feature.

Install package, review applicability in External Training Review, regenerate Orientation packet and inspect certificates. Previous ZIPs retained for rollback. This directory has no Git metadata; package hashes provide scoped parity evidence.
