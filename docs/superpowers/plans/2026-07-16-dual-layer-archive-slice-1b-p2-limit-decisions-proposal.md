# Slice 1B-P2 Side-Record Limit Decision Proposal

**Status:** Approved — 2026-07-17
**Date:** 2026-07-16
**Approval evidence:** The repository owner approved the proposal as written on 2026-07-17, including the five ceilings, stable rejection reasons, deterministic pre-encoding side-count validation, and the retained requirement to measure sanitized evidence before runtime activation.
**Runtime effect:** None

## Confirmed decisions

- Data remains employment/training PII only. PHI and PCI are prohibited and require a separate privacy/security design.
- P2 persists descriptors only. It performs no filesystem or network access.
- Database rows contain an approved adapter key and opaque relative immutable storage key only. Absolute paths, public URLs, and uploads-directory production fallbacks are prohibited. Production local storage ultimately requires `GHCA_ACD_ARCHIVE_PRIVATE_DIR` outside the public document root.

These policies are already approved by DLA-003 and DLA-005 in `2026-07-13-dual-layer-archive-implementation-decisions.md`.

## Approved limits

These approved dark-mode P2 ceilings reuse the already approved canonical-document bounds instead of introducing a second limit system:

| Limit | Approved value | Stable rejection reason |
|---|---:|---|
| Canonical snapshot bytes | 1,048,576 | `side_snapshot_bytes_exceeded` |
| Ledger items per ledger artifact | 10,000 | `side_ledger_item_count_exceeded` |
| Evidence assets per snapshot | 10,000 | `side_evidence_asset_count_exceeded` |
| Nested canonical depth | 32 | `side_snapshot_depth_exceeded` |
| Individual canonical string bytes | 262,144 | `side_snapshot_string_bytes_exceeded` |

Narrower frozen schema and database-column limits continue to win. The 10,000-total-value canonical ceiling also remains in force, so a nested snapshot can reach a lower effective item/asset count before either list ceiling.

## Repository evidence

- DLA-004 approved canonical ceilings of 1 MiB, depth 32, 10,000 total values, and 262,144 UTF-8 bytes per string, while requiring explicit equal-or-lower P2 limits before persistence.
- `GHCA_ACD_Archive_Canonical_JSON` enforces those exact constants on encode, decode, detach, and retained canonical reads.
- `GHCA_ACD_Archive_Event_Catalog` already bounds snapshot `byte_count`, `item_count`, and certificate-manifest lists with those canonical ceilings.
- The frozen schema already provides immutable snapshot, artifact, and ledger-item rows; this proposal requires no schema change.
- Representative production evidence is not available without prohibited current-site capture/database access. These are conservative dark-mode ceilings; representative sanitized fixtures must be measured before archive capture is enabled, and any lower production limits require a new approved decision.

## Affected implementation and tests

- Future snapshot, artifact-descriptor, and ledger-item repository validation and retained-row verification.
- `RecordEvidenceSnapshot`, `RecordMaterializedArtifact`, and `VerifyAndFinalize` Unit-of-Work side-record handling.
- Required tests: `SIDE-SNAPSHOT-LIMIT-BYTES`, `SIDE-SNAPSHOT-LIMIT-ITEMS`, `SIDE-SNAPSHOT-LIMIT-DEPTH`.
- Add exact boundary coverage: `SIDE-SNAPSHOT-LIMIT-ASSETS` and `SIDE-SNAPSHOT-LIMIT-STRING-BYTES`.
- Every rejection must assert the exact persistence exception, exact reason above, and zero database residue.

## Owner approval

Approval of this proposal authorizes P2 dark-mode side-record persistence only. It does not authorize capture, workers, blob storage, runtime wiring, feature activation, current-site database access, deployment, commit, or push.
