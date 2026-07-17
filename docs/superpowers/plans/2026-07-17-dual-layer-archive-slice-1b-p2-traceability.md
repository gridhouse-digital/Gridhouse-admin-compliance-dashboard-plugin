# Slice 1B-P2 Side-Record Persistence Traceability

**Status:** formal-review remediation complete; ready for re-review
**Date:** 2026-07-17
**Runtime effect:** none

## Scope delivered

- Immutable evidence-snapshot insertion with the complete owner-approved Section 10 Snapshot v1 contract and strict retained-row validation.
- Immutable certificate, ledger, and packet descriptor insertion and strict retained-row validation.
- Ordered immutable ledger-item insertion, canonical item documents, item digests, and manifest verification.
- Atomic side-record participation in the existing receipt-first Unit of Work for `RecordEvidenceSnapshot`, `RecordMaterializedArtifact`, and `VerifyAndFinalize`.
- Exact ledger-to-snapshot derivation checks plus finalization checks across the authoritative snapshot-capture/materialization events, snapshot, certificate descriptors, ledger descriptor/items, packet descriptor, build attempts, manifests, identities, and digests.
- Snapshot capture enqueues the two materializers; only the second matching materialization enqueues verification.
- No schema change, runtime wiring, source capture, worker, filesystem/blob operation, network call, current-site database access, feature activation, stage, commit, or push.

## Approved limits

The owner approved the proposal exactly as written on 2026-07-17. These are hard ceilings, not promised capacities; the 10,000-total-value canonical ceiling may lower the effective document capacity.

| Limit | Approved ceiling | Stable rejection reason |
|---|---:|---|
| Canonical snapshot bytes | 1,048,576 | `side_snapshot_bytes_exceeded` |
| Ledger items per ledger artifact | 10,000 | `side_ledger_item_count_exceeded` |
| Evidence assets per snapshot | 10,000 | `side_evidence_asset_count_exceeded` |
| Canonical depth | 32 | `side_snapshot_depth_exceeded` |
| Individual canonical string bytes | 262,144 | `side_snapshot_string_bytes_exceeded` |

Side-specific counts are checked before canonical encoding where required for deterministic reasons. Every individual canonical snapshot or ledger-item document also passes the shared canonical JSON byte, depth, value-count, string-byte, numeric, UTF-8, and structural limits. Narrower frozen column limits continue to win.

## Requirement to implementation to executable evidence

| Requirement | Implementation boundary | Named regression evidence |
|---|---|---|
| Snapshot insert-once and event atomicity | `GHCA_ACD_WPDB_Archive_Snapshot_Store`; Unit of Work snapshot transaction | `SIDE-SNAPSHOT-INSERT-ATOMIC`, `SIDE-RECEIPT-FIRST-REPLAY` |
| Snapshot contradictory duplicate rejection | Snapshot unique-identity lookup and exact retained-row comparison | `SIDE-SNAPSHOT-CONTRADICTORY-DUPLICATE` |
| Snapshot schema/canonical/digest/identity validation | Closed Section 10 v1 validation for every required section and nested evidence contract, cycle-bounded course/quiz timelines, completion-state consistency, calculated-total reconciliation, canonical decode/re-encode, digest, and row binding | `SIDE-SNAPSHOT-UNKNOWN-SCHEMA`, `SIDE-SNAPSHOT-CANONICAL-TAMPER`, `SIDE-SNAPSHOT-DIGEST-TAMPER`, `SIDE-SNAPSHOT-IDENTITY-BINDING`, `SIDE-SNAPSHOT-V1-TOP-LEVEL/REVIEW/SUBJECT/ORGANIZATION/POLICY/SOURCE-ASSET/COURSE/CALCULATED/COMPLETENESS/COURSE-ORDER/CYCLE-BOUNDARY/COMPLETION-STATE/TRAINING-TOTAL/REQUEST-BINDING` |
| Approved deterministic limits | Snapshot preflight plus shared canonical encoder; ledger batch pre-count | `SIDE-SNAPSHOT-LIMIT-BYTES`, `SIDE-SNAPSHOT-LIMIT-ITEMS`, `SIDE-SNAPSHOT-LIMIT-DEPTH`, `SIDE-SNAPSHOT-LIMIT-ASSETS`, `SIDE-SNAPSHOT-LIMIT-STRING-BYTES` |
| Artifact descriptor insert-once and exact event binding | `GHCA_ACD_WPDB_Archive_Artifact_Repository`; Unit of Work event/descriptor comparison | `SIDE-ARTIFACT-INSERT-ATOMIC`, `SIDE-ARTIFACT-CONTRADICTORY-DUPLICATE`, `SIDE-ARTIFACT-SNAPSHOT-BINDING`, `SIDE-ARTIFACT-DIGEST-TAMPER` |
| Artifact schema and private-storage policy | Closed descriptor fields, kind/role/media validation, `private_local` plus opaque relative key | `SIDE-ARTIFACT-UNKNOWN-SCHEMA`, `SIDE-ARTIFACT-STORAGE-KEY-REJECTION` |
| Ordered immutable ledger items | Batch prevalidation, contiguous zero-based ordinals, item canonical JSON/digest, ordered manifest digest | `SIDE-LEDGER-ORDERED-INSERT`, `SIDE-LEDGER-GAP-REJECTION`, `SIDE-LEDGER-DUPLICATE-REJECTION` |
| Ledger identity/schema/range binding | Exact stream/archive/snapshot/artifact fields, retained schema check, unsigned-BIGINT ceiling, and equality to sealed snapshot employee/program/cycle/course/certificate/evidence facts | `SIDE-LEDGER-SNAPSHOT-BINDING`, `SIDE-LEDGER-UNKNOWN-SCHEMA`, `SIDE-LEDGER-UNSIGNED-RANGE`, `SIDE-LEDGER-SNAPSHOT-EMPLOYEE/PROGRAM/CYCLE/COURSE/CERTIFICATE/EVIDENCE` |
| Frozen P2 digest formats | Literal domain-prefixed canonical bytes and independently calculated SHA-256 constants | `GOLDEN-ARTIFACT-DEDUPE-INDEPENDENT/PRODUCTION`, `GOLDEN-LEDGER-MANIFEST-INDEPENDENT/PRODUCTION` |
| Complete finalization fail-closed verification | Unit of Work rebinds retained rows to authoritative capture/materialization events and event history | `SIDE-FINALIZATION-MISSING-SNAPSHOT`, `SIDE-FINALIZATION-MISSING-ARTIFACT`, `SIDE-FINALIZATION-DIGEST-MISMATCH`, `SIDE-FINALIZATION-CAUSAL-BINDING`, `SIDE-FINALIZATION-BUILD-ATTEMPT-BINDING`, `SIDE-FINALIZATION-EXACT-COMPLETE` |
| Transactional rollback of every participant | One shared `$wpdb` transaction and existing receipt-first lock order | `SIDE-RECORD-EVENT-ROLLBACK`, `SIDE-RECORD-PROJECTION-ROLLBACK`, `SIDE-RECORD-TASK-ROLLBACK`, `SIDE-RECORD-STREAM-HEAD-ROLLBACK`, `SIDE-RECORD-RECEIPT-ROLLBACK` |
| Required task sequence | Existing durable task store, snapshot and second-materialization event handling | `SIDE-TASK-SEQUENCE` |
| Dark mode and append-only surface | No entrypoint load/hook; repositories expose insert/read only | `SIDE-NO-RUNTIME-WIRING`, `SIDE-NO-UPDATE-DELETE-SURFACE` |

Every negative persistence test asserts the exact exception class, exact stable reason, and an unchanged full database fingerprint. Each injected rollback point is followed by a clean retry that commits the immutable snapshot exactly once.

## Atomicity and append-only proof

`RecordEvidenceSnapshot` assigns the authoritative event ID, inserts the canonical snapshot and all certificate descriptors, appends the event, projects, enqueues both materializers, advances the stream head, writes the receipt, and commits on the same `$wpdb` connection. `RecordMaterializedArtifact` performs the equivalent descriptor/item/event transaction. `VerifyAndFinalize` verifies the complete retained side-record set before its two-event batch can append.

The new repositories expose only insert and read operations. They contain no update, delete, replace, filesystem, or network operation. The existing event store inserts event rows and never updates or deletes them; its guarded `UPDATE` statements target only the mutable stream-head row. Static inspection and injected failures prove that a later event, projection, task, stream-head, or receipt failure removes all earlier uncommitted side rows with the transaction.

## Frozen P2 digest inputs

- `ghca-artifact-dedupe-v1` hashes the UTF-8 bytes `ghca-artifact-dedupe-v1\n` followed by one `ghca-cjson-1` object containing exactly `archive_id`, `artifact_kind`, `build_attempt_id`, and `role_key`.
- `ghca-ledger-manifest-v1` hashes the UTF-8 bytes `ghca-ledger-manifest-v1\n` followed by one `ghca-cjson-1` object containing exactly the ordered `item_digests` list.

Independent literal-byte vectors freeze both formats; production helpers must match those constants on every supported PHP runtime.

## Definitive 3 x 3 database matrix

Final uninterrupted post-remediation run: exit code `0`; 9/9 cells pass. Each cell ran 55 schema checks, 358 P1 persistence checks, and 263 P2 side-record checks.

| PHP | MySQL 8.0.46 | MySQL 8.4.10 | MariaDB 10.6.27 |
|---|---:|---:|---:|
| 7.4.33 | 55 + 358 + 263 PASS | 55 + 358 + 263 PASS | 55 + 358 + 263 PASS |
| 8.3.30 | 55 + 358 + 263 PASS | 55 + 358 + 263 PASS | 55 + 358 + 263 PASS |
| 8.5.7 | 55 + 358 + 263 PASS | 55 + 358 + 263 PASS | 55 + 358 + 263 PASS |

- Schema assertions: 495.
- P1 persistence assertions: 3,222.
- P2 side-record assertions: 2,367.
- Total matrix assertions: 6,084.
- All databases were disposable loopback targets with the `ghca_acd_archive_test_` safety prefix.
- The test bootstrap does not load `wp-config.php`, `wp-load.php`, or current-site credentials.

## Independent regression and compatibility evidence

| Verification | PHP 7.4.33 | PHP 8.3.30 | PHP 8.5.7 |
|---|---:|---:|---:|
| Slice 1A kernel plus P2 digest vectors | 1,246 checks / 14 suites PASS | 1,246 checks / 14 suites PASS | 1,246 checks / 14 suites PASS |
| Legacy baseline | 25 checks / 2 suites PASS | 25 checks / 2 suites PASS | 25 checks / 2 suites PASS |
| Archive production/test lint | 58 files PASS | 58 files PASS | 58 files PASS |

PHP 8.4 remains unavailable because the configured Laragon PHP 8.4 directory has no `php.exe`; no PHP 8.4 result is claimed.

## Static and repository-boundary evidence

| Check | Result |
|---|---|
| Plugin entrypoint archive references | zero |
| Archive runtime hooks, REST registration, activation, cron, or scheduling | zero |
| Archive production filesystem/network calls | zero |
| Executed current-site bootstrap | zero; the sole textual occurrence is the bootstrap prohibition comment |
| Hardcoded database credential/API usage | zero |
| Snapshot/artifact/ledger/event-row update, delete, or replace surface | zero |
| Schema/manifest diff | zero |
| PHP-8-only syntax | zero; PHP 7.4 lint and suites pass |
| Debug stop gates | zero |
| `git diff --check` | PASS |
| Feature flags | remain forced off by the preserved migrator contract; no runtime invokes it in this slice |

## Files added

- `docs/superpowers/plans/2026-07-16-dual-layer-archive-slice-1b-p2-limit-decisions-proposal.md`
- `docs/superpowers/plans/2026-07-17-dual-layer-archive-slice-1b-p2-traceability.md`
- `includes/archive/infrastructure/class-wpdb-archive-artifact-repository.php`
- `includes/archive/infrastructure/class-wpdb-archive-snapshot-store.php`
- `tests/archive/test-side-record-persistence.php`

## Files modified

- `includes/archive/application/class-archive-unit-of-work.php`
- `includes/archive/infrastructure/class-archive-digester.php`
- `tests/archive/persistence-bootstrap.php`
- `tests/archive/persistence-fixtures.php`
- `tests/archive/test-all.ps1`
- `tests/archive/test-helpers.php`
- `tests/archive/test-digests.php`
- `tests/archive/test-persistence.php`

## Frozen-schema limitations for formal review

No schema change was made. Two requested identities cannot be stored as independent artifact/snapshot columns in the frozen 13-table manifest:

1. The artifact table has no causal task ID, causal event ID, canonical descriptor JSON, or descriptor digest column. P2 therefore atomically accepts descriptors only beside their recorded event, recomputes the frozen dedupe identity, and exact-compares all available event/descriptor identities and content digests on finalization. It cannot persist or independently recompute a nonexistent causal-task/event field or descriptor-document digest.
2. The snapshot table has no `build_attempt_id`. P2 reconstructs the capture attempt from the authoritative event history and verifies every certificate descriptor against it, but the snapshot row itself cannot carry an independent build-attempt constituent.

Those are frozen-schema representation limits, not silently invented fields. A future requirement for independently stored causal IDs or a descriptor digest requires a separately approved schema decision.

## Activation gate and handoff

- Data classification remains employment/training PII only. PHI and PCI remain prohibited.
- Representative sanitized evidence must be measured before capture or runtime activation. Lower production limits require another approved owner decision.
- Production storage must ultimately use `GHCA_ACD_ARCHIVE_PRIVATE_DIR` outside the public document root; this slice persists descriptors only and performs no blob operation.
- Branch: `feature/dual-layer-archive-slice-1b-p2-side-records`.
- Starting HEAD: `98050a75b4b7de74798e431d3fed06b3d98b1c7a`.
- Ending HEAD: `98050a75b4b7de74798e431d3fed06b3d98b1c7a`.
- Nothing was staged, committed, pushed, deployed, or activated.
- The unrelated `.claude/` directory remains untracked and untouched.

Slice 1B-P2 stops here for formal review. Workers, filesystem storage, evidence capture, controllers, runtime wiring, and later slices remain unauthorized.
