# Beta 12 — agency-confirmed training and settings cleanup

## Implemented

- `includes/class-agency-training.php`: native Settings fields for ISP/Communication Profile default hours (1 each), Communication Profile delivery opt-in, and administrator-only Users → Edit confirmation controls. Training applies to an exact inclusive annual/custom reporting period. No cycle changes and no orientation credit. No uploads or integration calls.
- Each save validates capability plus employee-specific nonce, actions, dates, declaration, history revision and settings revision. Confirmations snapshot hours/reviewer/time. Meta history is append-only; latest action per topic/period governs credit. Same-state resaves do not duplicate credit or rewrite hours. Withdraw then reconfirm to correct hours. Disabling a delivery setting prevents new confirmations but does not erase existing ones.
- `class-audit-calculator.php`: ISP credit counts under topic hours; Communication Profile counts under additional hours, without satisfying another required topic. Manual ISP hours are not added over existing primary ISP course hours or a current Not applicable decision. Other unresolved topics/evidence still block overall compliance. Raw courses/certificate requests remain unchanged. Exact period matching means an expanded reporting range requires its own confirmation.
- `class-audit-pdf.php` and packet selection: requested `Agency Mandated Training/compliance` heading, no source prefix in selected packet course titles, blank status instead of `Evidence included`. Missing evidence still visible. Communication delivery adds a separate row; confirmed rows show agency confirmation and hours. Supporting documents remain agency-held, not fabricated attachments.
- Settings UI: scoped left console navigation on wide screens, responsive layout, main settings grouped in native expandable sections, sticky native save controls, hash links reveal target sections, invalid fields reveal their section before focus. Existing option groups/forms, WordPress admin colors, notices and permissions retained. Dashboard/drawer unchanged. Source labels remain in admin course selection to distinguish otherwise identical entries.

## Verification

- `php tests/test-agency-training.php`: 314 cumulative synthetic checks, including validation, stale defaults/forms, attribution, withdrawal, period isolation, idempotence, no automatic credit, source labels, PDF HTML and totals.
- Settings console: 21 scope/navigation checks; profile: 47; cycle: 27; course correction: 64 cumulative; calculator and PDF jobs pass.
- JavaScript syntax and DOM-double tests pass, including dirty-state safeguards and revealing collapsed sections. PHP lint: 159 files passed before documentation/version finalization; modified entrypoint checked again for packaging.
- Browser validation blocked: Playwright CLI initialization hit `EPERM` in the npm cache. No live WordPress save/role, rendered desktop/mobile/keyboard, actual TCPDF pagination or certificate merge acceptance claimed. Full prototype overview/every operational editor is not ported; layout work addresses the reported shell/form mismatch.

## Acceptance / boundaries

Packaged `../packages/Gridhouse-admin-compliance-dashboard-1.7.3-beta.12-test-only.zip` (relative to plugin root), with `1.7.3-beta.12-manifest.csv`. All 117 ZIP entries match source hashes and byte counts. ZIP SHA-256: `83B87F844A29F142D6A3877E16F717B6B2E7B24CD56BBF3AFDDBECD4325711CE`. Entrypoint syntax passed after version finalization. Compared with beta11, runtime changes are restricted to settings CSS/JS, entrypoint, new agency-training class, calculator, PDF, packet selection, settings, README and TESTING. Previous release ZIPs are retained unchanged.

Use TESTING.md beta12 steps on isolated staging. Confirm default hours, employee/period declarations, matching packets, unchanged orientation, existing course credit deduplication, history/withdrawals and native settings save/reload. Review the layout on desktop/mobile before accepting visual fidelity. No real employee edits, live installation, Jotform operations, messaging operations or production writes performed. Beta11 and older ZIPs remain unchanged. No Git metadata exists in this workcopy; release manifests provide file-level parity.
