# Beta 6 — temporary external ISP evidence disclosure

2026-09-17. Test release; no deployment or audit-acceptance certification.

## Implemented

- `includes/class-isp-evidence-review.php`: reuse persisted `managed` state, relabeled External evidence — agency review required. Explain no-detail temporary route in the existing user-profile controls. No new state, schema, dependency, automatic enrollment or migration.
- `includes/class-audit-calculator.php`: explicit valid managed state exposes external-evidence disclosure and a rendering flag. Hours, current cycle, completed courses and overall unresolved gate are unchanged. Unmapped ISP alone does not assert external handling. Invalid evidence remains review-required.
- `includes/class-audit-pdf.php`: short matrix status, full disclosure below matrix rather than inside narrow topic column. Shared category note also flows to CSV. Does not claim evidence was received, verified or attached.
- Entrypoint version/cache key: 1.7.3-beta.6. README and TESTING updated, duplicate beta 5 documentation removed. All earlier runtime changes retained.

## Verification

15 targeted PHP scripts passed; 146 development PHP files passed lint on PHP 8.3.30. New `tests/test-beta6-external-evidence.php` passes 128 cumulative checks (10 new), including blank detail save, no auto-opt-in, unresolved overall status, unchanged hours/evidence, PDF/CSV disclosure and opt-out. Existing tests cover authorization, nonces, period/revision guards and manual verification.

Synthetic HTML from the actual cover renderer was inspected in the in-app browser: matrix label wraps readably and separate disclosure is visible with no overlap. This uses platform/PDF stubs and preview CSS, not WordPress save/reload or TCPDF pagination. Preview server and browser tab closed. No real records, integrations, messages or site changes.

## Remaining / rollback

On staging, select the option under Users → Edit → ODP annual applicability review and save, then regenerate the packet and verify real PDF layout/page flow and CSV. Supply and review evidence separately through an approved secure audit channel. The platform cannot establish the sufficiency of an uninspected acknowledgment form. Source references and documents are not included automatically.

Beta 5 ISP review 1 reads the same managed state with its older label; no record deletion is needed to roll back. Older beta 5 builds without ISP review ignore that evidence state, so revalidate reporting after rollback. Prior ZIPs remain unchanged. No dual-layer archive, document upload/merge, dashboard redesign or regulatory rule changes are included.
