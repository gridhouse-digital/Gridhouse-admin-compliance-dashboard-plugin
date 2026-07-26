# Slice 1B-P3B2a Evidence Capture Traceability

**Status:** Ready for formal re-review, not self-accepted.

**Scope:** Dark, constructor-injected evidence validation, preparation, fingerprint comparison, fenced snapshot capture, recovery, and operational disposition. No production source adapter or runtime composition is included.

**Decision record:** `2026-07-26-dual-layer-archive-slice-1b-p3b2a-evidence-capture-decisions-proposal.md`, including owner-approved Amendments E02-A and E10-A.

**Branch:** `feature/dual-layer-archive-slice-1b-p3b2a-evidence-capture`

**Starting and ending HEAD:** `a155eec8433935ba5a28eaf3fa5d056c1a28404f`

## Files added and modified

### Added production files

- `includes/archive/contracts/class-archive-evidence-source.php`
- `includes/archive/application/class-archive-evidence-source-exception.php`
- `includes/archive/application/class-archive-evidence-result-validator.php`
- `includes/archive/application/class-archive-evidence-snapshot-preparer.php`
- `includes/archive/application/class-archive-evidence-task-handler.php`

### Modified production files

- `includes/archive/application/class-archive-task-catalog.php`
- `includes/archive/application/class-archive-worker-coordinator.php`
- `includes/archive/application/class-archive-build-coordinator.php`
- `includes/archive/infrastructure/class-wpdb-archive-snapshot-store.php`

The snapshot-store change is the one-line E10-A extension of private `is_object_document()` to accept the already-existing immutable `GHCA_ACD_Archive_Canonical_Object`. No schema, canonicalizer, digester, snapshot bytes, IDs, or retained data contract changed.

### Added tests

- `tests/archive/test-p3b2a-evidence.php`
- `tests/archive/test-p3b2a-evidence-persistence.php`
- `tests/archive/test-p3b2a-evidence-concurrency.php`

### Modified tests and runner

- `tests/archive/bootstrap.php`
- `tests/archive/test-p3-boundaries.php`
- `tests/archive/test-all.ps1`

`tests/archive/persistence-bootstrap.php` required no change.

### Documentation

- Modified `docs/superpowers/plans/2026-07-26-dual-layer-archive-slice-1b-p3b2a-evidence-capture-decisions-proposal.md` only to record owner-approved E02-A and E10-A.
- Added this traceability record.

## Formal re-review remediation

- Capture recovery now recognizes only capture-task `DetectSourceDrift`/`FailArchive` command IDs and returns a matching retained snapshot before considering failure, so later materialization failure cannot mask successful capture.
- Attempt-five deterministic recovery reclassifies its final exception; retryable-to-operational and retryable-to-unknown transitions dead-letter without a lifecycle event.
- `GHCA_ACD_Archive_Evidence_Source_Exception` validates one closed category/reason/context tuple table.
- Evidence text validation rejects all non-ASCII text when `Normalizer` is unavailable and still requires NFC when it is available.

## Decision-to-code-to-test mapping

| Decision | Production evidence | Named regression evidence |
|---|---|---|
| E01, E03, E04 | Evidence-source interface plus injected fake-capable handler; no production implementation | `P3B2A-REVIEWED-FINGERPRINT-PROVENANCE-GATED`, boundary scans |
| E02 and E02-A | `GHCA_ACD_Archive_Task_Catalog::validate_capture_payload()` requires the exact six-field payload and strict integer `1` | `P3B2A-E02A-INTEGER-CANONICAL-FORMAT`, `P3B2A-TASK-PAYLOAD-CLOSED` |
| Retained P3A compatibility | Generic P3A dark-handler payloads remain accepted by the shared queue hook, but strict evidence dispatch rejects them before source/UoW work | `P3B2A-P3A-GENERIC-TASK-COMPATIBILITY`; retained P3 worker 67/67 |
| E05 | Build Coordinator reloads the exact request event and derives authoritative archive/case/cycle/policy/revision/subject bindings | `P3B2A-TASK-TRIGGER-EXACT`, `P3B2A-SNAPSHOT-BINDING-EXACT` |
| E06 | Physical source transaction remains deferred; P3B2a owns only the injected source contract | Boundary and provenance tests; no physical-source claim |
| E07 | Closed evidence validator enforces exact fields, normalized types, decimal/time/order rules, bounded structure, and fail-closed NFC handling when `Normalizer` is absent | golden vector, row/value, unknown-field, prohibited-data, and `P3B2A-E07-NFC-FAIL-CLOSED` tests |
| E08 | Existing canonical JSON and `GHCA_ACD_Archive_Digester::source_fingerprint()` are reused | `P3B2A-SOURCE-FINGERPRINT-GOLDEN`, `P3B2A-SOURCE-FINGERPRINT-CROSS-RUNTIME` |
| E09 | Reviewed fingerprint is loaded from the authoritative request; activation provenance remains gated; retained capture success wins over later-phase failure | `P3B2A-REVIEWED-FINGERPRINT-PROVENANCE-GATED`, `P3B2A-SOURCE-DRIFT-BEFORE-CAPTURE`, `P3B2A-CAPTURE-SUCCESS-WINS-LATER-PHASE-FAILURE` |
| E10 and E10-A | Snapshot preparer maps E07 into existing snapshot-v1; Build Coordinator submits through the existing fenced UoW; snapshot store accepts canonical object maps | E10-A digest/JSON, numeric-key roundtrip, arbitrary-object rejection, byte-identical replay, contradiction, duplicate replay |
| E11 | Existing 10,000-value and 1,048,576-byte canonical limits are independently applied to E07 and snapshot documents | `P3B2A-ROW-LIMIT-NO-TRUNCATION`, `P3B2A-VALUE-LIMIT-NO-TRUNCATION`, `P3B2A-SNAPSHOT-VALUE-LIMIT-INDEPENDENT`, `P3B2A-BYTE-LIMIT-NO-TRUNCATION` |
| E12 | Exact allowlists and mechanical HTML/URL/path/control/resource/object/callback rejection | `P3B2A-E07-UNKNOWN-FIELD-REJECTED`, `P3B2A-PROHIBITED-DATA-REJECTED`, PII placement test |
| E13 | Certificate eligibility/reference is validated; required certificate acquisition fails closed in the preparer | `P3B2A-CERTIFICATE-ACQUISITION-DEFERRED` |
| E14 | The evidence exception enforces exact category/reason/context tuples; capture classification keeps rollback/close/schema/unknown/unclassified failures operational; attempt five reclassifies the final recovery failure before disposition | `P3B2A-E14-CLOSED-FAILURE-TUPLES`, operational no-lifecycle cases, retryable-to-operational/unknown transitions, deterministic recovery, exhausted-no-outcome |
| E15 | Capture IDs use `ghca-p3b2a-capture-id-v1`; retained P3B1 helper body/domain remains unchanged | `P3B2A-P3B1-ID-DOMAINS-UNCHANGED` covers all retained and capture purposes |
| E16 | Coordinator rechecks fencing, commits only validated preparation, recovers retained success/failure, and completes after authoritative outcome | response-loss, StartBuild response-loss, attempt-five crash, stream-conflict reload, two-worker tests |
| E17 | Only the approved source contract, exception, validator, preparer, handler, and three coordinator/catalog changes were added; E10-A is the recorded owner-approved exception | 13/13 boundary checks on both runtimes |
| E18 | Exact two-runtime, three-database matrix plus runtime-only checks | Results below |

## Database verification

Each cell executed:

- schema: 55
- P1 persistence: 358
- P2 side records: 263
- P3 digests: 9
- P3 worker: 67
- P3 storage/orphans: 88
- P3B1 task contracts: 173
- P3B1 Build Coordinator: 4
- P3B1 ledger vectors: 7
- P3B1 failure/crash/race: 27
- P3B2a evidence contract: 14
- P3B2a evidence persistence/failure injection: 32
- P3B2a two-connection/ID-domain checks: 4
- total: 1,101 checks

| Runtime | MySQL 8.0.46 | MySQL 8.4.10 | MariaDB 10.6.27 |
|---|---:|---:|---:|
| PHP 8.3.30 | 1,101 PASS | 1,101 PASS | 1,101 PASS |
| PHP 8.5.7 | 1,101 PASS | 1,101 PASS | 1,101 PASS |

Database-matrix total: **6,606 executable checks**.

All test databases used the approved `ghca_acd_archive_test_` prefix, loopback ports 33061-33063, process-local environment credentials, explicit destructive opt-in, and a fresh random restricted-user password. No credential value was printed, retained, or written.

## Runtime-only verification

| Verification | PHP 8.3.30 | PHP 8.5.7 |
|---|---:|---:|
| Slice 1A kernel plus accepted digest vectors | 1,246 / 14 suites PASS | 1,246 / 14 suites PASS |
| Legacy baseline | 25 / 2 suites PASS | 25 / 2 suites PASS |
| P3/P3B1/P3B2a boundary suite | 13 PASS | 13 PASS |
| Standalone P3 digest rerun | 9 PASS | 9 PASS |
| Evidence contract with extensions disabled (`php -n`) | 14 PASS | 14 PASS |
| Archive production/test lint | 84 files PASS | 84 files PASS |

Database matrix plus kernel, legacy, and boundary evidence totals **9,174 assertions**. The standalone P3 digest and no-extension evidence reruns duplicate matrix coverage and are excluded from that aggregate. Lint covered **168 runtime/file combinations**.

PHP 8.4 remains optional and unavailable: no `php.exe` exists under the installed Laragon PHP 8.4 directories. No PHP 8.4 result is claimed.

## Concurrency, crash, and recovery evidence

- Every matrix cell's retained P3 lease race returned exactly one non-null fencing token and one `null`.
- `P3B2A-TWO-WORKER-ONE-OWNER` used two real database connections and proved one capture owner.
- Installed-type filtering left earlier packet work pending while claiming eligible evidence.
- Attempt-five crash after snapshot command commit replayed the exact stored response, performed zero repeated source reads, emitted one snapshot event, and completed the task.
- StartBuild response loss reloaded committed state and continued without a duplicate start.
- Failure-command response loss replayed the one retained failure without another source call or lifecycle event.
- A stale stream conflict reloaded the matching authoritative snapshot and produced no competing failure.
- A retained capture snapshot remained authoritative after a P3B1-domain materialization failure on the same build attempt.
- Attempt-five retryable recovery succeeded on the deterministic second read; when both reads failed and no outcome existed, `archive_build_attempts_exhausted` committed exactly once.
- Attempt-five retryable-to-operational and retryable-to-unknown transitions reclassified the second error, dead-lettered operationally, and emitted zero lifecycle failures.
- Rollback, connection-close, unsupported-schema, unclassified, and unknown operational failures dead-lettered with sanitized operational state and zero `ArchiveFailed`.

## E10-A containment and immutability evidence

- Numeric course key `"101"` survived canonical encoding, immutable insert, database reload, and receipt replay.
- `audit_mapping` and `course_lifespan_rules` remained `GHCA_ACD_Archive_Canonical_Object` values after reload.
- Stored canonical JSON and snapshot SHA-256 matched the pre-insert canonical document.
- Duplicate replay added no event or snapshot row.
- Contradictory bytes raised `archive_immutable_conflict` and left the retained snapshot unchanged.
- `stdClass` and arbitrary PHP objects remained rejected by the snapshot-store object predicate.
- Existing P2 side-record persistence passed 263/263 in every matrix cell.

## Stable P3B2a failure surface

Evidence-source reason codes:

- `archive_build_binding_invalid`
- `archive_snapshot_invalid`
- `archive_evidence_prohibited`
- `archive_source_drift`
- `archive_source_read_failed`
- `archive_source_transaction_failed`
- `archive_source_query_failed`
- `archive_evidence_incomplete`
- `archive_source_schema_unsupported`
- `archive_certificate_invalid`
- `archive_immutable_conflict`
- `task_handler_failed`

Worker/task disposition codes exercised by P3B2a:

- `task_payload_invalid`
- `task_prepared_result_invalid`
- `task_handler_failed`
- `task_outcome_commit_failed`
- `task_attempts_exhausted`
- existing fencing codes from P3A

All retained task error text comes from the fixed worker message catalog. Source exception text and injected unknown exception text are not persisted.

## Static and scope-boundary evidence

- `git diff --check`: PASS.
- Plugin-entrypoint archive references: 0.
- Runtime hook/scheduler/REST/WP-CLI/activation calls in archive production code: 0.
- Current-site bootstrap, global database handle, or WordPress credential references: 0.
- Network calls: 0.
- Debug calls: 0.
- Changed schema/migration files: 0.
- Added DDL: 0.
- Added hardcoded secret/key material: 0.
- Added immutable event/snapshot/artifact/ledger update/delete SQL: 0.
- No production source adapter, LearnDash query, certificate acquisition, filesystem renderer, packet, verification/finalization, reset execution, D16 lifecycle retry execution, P3B3 wiring, controller, or runtime registration was added.

## Deferred and unresolved contracts

- E06 physical source queries, `WITH CONSISTENT SNAPSHOT`, transaction cleanup, elapsed budget enforcement, source-specific database tests, and vendor-tested cancellation/timeouts remain reserved for the separately authorized source-adapter slice.
- Review-time fingerprint producer equivalence remains an activation-blocking provenance gate.
- Certificate acquisition, packet/ledger follow-on work, verification/finalization, D16 lifecycle retry execution, P3B3 runtime composition, and activation remain unapproved.
- Formal acceptance remains an owner/reviewer action.

## Git and workspace state

- Branch remained `feature/dual-layer-archive-slice-1b-p3b2a-evidence-capture`.
- HEAD remained `a155eec8433935ba5a28eaf3fa5d056c1a28404f`.
- Staged files: 0.
- No commit, push, pull request, activation, deployment, or current-site database access occurred.
- The pre-existing untracked `.claude/` directory was not accessed or modified.
