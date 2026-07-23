# Slice 1B-P3B1 Dark Ledger Vertical Slice Traceability

Status: **formally accepted**

This record covers only the dark-mode `materialize_ledger` vertical slice approved by Decisions D01-D15 and the D16 deferral in `2026-07-24-dual-layer-archive-slice-1b-p3b-runtime-decisions-proposal.md`. It records the later owner-approved test-only amendment to the P3 boundary suite. P3B2, P3B3, activation, deployment, and runtime composition remain excluded.

## Repository state

- Branch: `feature/dual-layer-archive-slice-1b-p3b1-ledger`
- Starting HEAD: `591980850883833e5077eb05465e11010645b27a`
- Ending HEAD: `591980850883833e5077eb05465e11010645b27a`
- Approved proposal history: commit `591980850883833e5077eb05465e11010645b27a`, `docs(archive): approve P3B runtime decisions`
- Staged files: zero
- Commit, push, pull request, activation, deployment: none
- Pre-existing untracked `.claude/`: untouched; no file under it was read, modified, staged, cleaned, or deleted

## Files added

- `includes/archive/application/class-archive-task-catalog.php`
- `includes/archive/application/class-archive-build-coordinator.php`
- `includes/archive/application/class-archive-ledger-task-handler.php`
- `includes/archive/application/class-archive-ledger-materializer.php`
- `tests/archive/test-p3b-task-contracts.php`
- `tests/archive/test-p3b-build-coordinator.php`
- `tests/archive/test-p3b-ledger-handler.php`
- `tests/archive/test-p3b-ledger-failures.php`
- `docs/superpowers/plans/2026-07-25-dual-layer-archive-slice-1b-p3b1-traceability.md`

## Files modified

- `includes/archive/application/class-archive-unit-of-work.php`
- `includes/archive/application/class-archive-worker-coordinator.php`
- `includes/archive/infrastructure/class-wpdb-archive-task-store.php`
- `tests/archive/bootstrap.php`
- `tests/archive/test-all.ps1`
- `tests/archive/test-p3-worker.php`
- `tests/archive/test-p3-boundaries.php` - owner-approved test-only amendment described below

No schema, schema manifest, migrator, event catalog/schema, aggregate, canonical-JSON, digester, private-store/orphan contract, plugin entrypoint, metadata, PHP-floor declaration, LearnDash/current-site, reset, controller, activation, or deployment file changed.

## Decision to implementation to regression mapping

| Decision | Implemented contract | Primary implementation | Named evidence |
|---|---|---|---|
| D01 | One complete dark-mode ledger slice only | Task catalog, materializer, ledger handler, build coordinator, existing worker/UoW/task store | All four P3B1 suites; boundary suite |
| D02 | Exact bounded nine-field `materialize_ledger` v1 payload; trusted preallocated ledger artifact ID; `EvidenceSnapshotCaptured` producer only | Task catalog, Unit of Work, task store | `P3B1-PAYLOAD-LEDGER-EXACT-V1`; every missing/wrong/null/binding/canonical/size negative; `P3B1-DEFERRED-PAYLOADS-UNCHANGED` |
| D03 | Handler returns bounded prepared facts; coordinator validates before UoW; fixed completed/committed result synthesized only after commit/replay | Ledger handler and worker coordinator | Seven `P3B1-PREPARED-RESULT-INVALID-BEFORE-UOW-*` cases; fenced success/crash replay tests; existing exact 27-byte outcome checks |
| D04 | Only existing aggregate commands through the receipt-first fenced UoW mutate lifecycle authority | Build coordinator and Unit of Work | `P3B1-BUILD-COORDINATOR-FENCED-SUCCESS`; `P3B1-BUILD-COORDINATOR-EXACT-SIDE-RECORDS` |
| D05 | Exact attempt-five recovery, response-loss replay, source/reason/category/context classification, distinct failure command, and conflict recheck | Worker and build coordinators plus ledger handler | `P3B1-FAILURE-MAPPING-CLOSED` (107 approved cases); wrong-category/source/context blocks; exact retained-event rebinds; attempt-five, authoritative-open, immutable-conflict, stream-race, and no-success-then-failure checks |
| D06 | Exact deterministic `ledger-document-v1`, descriptor, storage key, items, digests, event payload, and producer metadata | Ledger materializer and handler | Seven independent literal golden checks on PHP 8.3.30 and 8.5.7 in every matrix cell |
| D07-D10 | Capture, certificate acquisition, packet rendering, and verify/finalize remain deferred | No implementation | Boundary/static scans; installed handler allowlist contains only `materialize_ledger` |
| D11 | Constructor injection only; no production root/secret loader | Existing artifact-store injection and new constructors | Runtime-config and hardcoded-key scans: zero |
| D12 | No wake-up mechanism, runtime values, scheduler, cron, or composition root | No implementation | Boundary hook/scheduler/CLI checks and static scans: zero |
| D13 | No health persistence or exposure dependency | No implementation | No schema/controller/runtime additions |
| D14 | Installed-type predicate in both available claim and expired reclaim selection plus guarded update | Task catalog, task store, worker coordinator | `P3B1-INSTALLED-TYPE-AVAILABLE-CLAIM`; `P3B1-INSTALLED-TYPE-EXPIRED-RECLAIM`; invalid allowlists leave no transaction residue |
| D15 | Exact PHP/runtime/database gate plus dark-mode/static checks | Matrix runner and this record | Results below |
| D16 | Lifecycle materialization retry remains unresolved; `ArchiveRetryRequested` produces packet only, not an installed ledger task | Unit of Work | `P3B1-LIFECYCLE-RETRY-DEFERRED` |

## Owner-approved P3B1 boundary amendment

After implementation, the inherited P3A boundary suite correctly found the new production ledger handler but still used one blanket flag that prohibited every production handler. The owner authorized modifying only `tests/archive/test-p3-boundaries.php` outside the original allowlist.

The two blanket checks were replaced with:

- `P3B1-BOUNDARY-ONLY-LEDGER-HANDLER`, which permits exactly `class-archive-ledger-task-handler.php` and `GHCA_ACD_Archive_Ledger_Task_Handler` and rejects every other production handler file/class;
- `P3B1-BOUNDARY-NO-DEFERRED-HANDLER-OR-RUNNER`, which rejects worker runners and reset/capture/packet/verify handlers while retaining the entrypoint-dark requirement.

The other nine boundary assertions were unchanged. The complete suite passes 11/11 on both required runtimes.

## Final recovery and failure-classification remediation

The final owner-directed remediation modified only:

- `includes/archive/application/class-archive-ledger-task-handler.php`;
- `includes/archive/application/class-archive-worker-coordinator.php`;
- `tests/archive/test-p3b-ledger-failures.php`;
- this traceability record.

`recover_authoritative_prepared()` now selects the retained ledger decision by the authoritative archive/build-attempt binding, independently materializes the expected ledger from the retained snapshot, and strictly compares the complete canonical `LedgerMaterialized` v1 payload, including the catalog-required `payload_schema_version`. Descriptor and ledger-row reads use only reconstructed authoritative expected values, never caller/event-provided digest, count, manifest, snapshot, artifact, stream, or build values. The descriptor, ordered ledger documents, item digests, manifest, count, and committed object are revalidated before returning prepared facts. A proven retained contradiction throws the exact `GHCA_ACD_Archive_Persistence_Exception`, `CATEGORY_INTEGRITY_BLOCKED`, `archive_immutable_conflict`; it cannot return prepared success, complete the task, call the UoW, or append a later lifecycle failure.

`classify_ledger_failure()` now authenticates the exception source, persistence category, and operation context before accepting a stable reason mapping. Valid-looking reasons from the wrong class, category, or context are operational-blocked and persist only the bounded sanitized task message, with no lifecycle command/event.

Named final regressions are:

- `P3B1-RECOVERY-EVENT-ITEM-COUNT-MISMATCH`;
- `P3B1-RECOVERY-EVENT-MANIFEST-MISMATCH`;
- `P3B1-RECOVERY-EVENT-ARTIFACT-BINDING-MISMATCH`;
- `P3B1-FAILURE-MAPPING-WRONG-CATEGORY-BLOCKED` and its no-authoritative-residue integration check;
- `P3B1-FAILURE-MAPPING-WRONG-SOURCE-BLOCKED` and its no-authoritative-residue integration check;
- `P3B1-FAILURE-MAPPING-WRONG-CONTEXT-BLOCKED`;
- `P3B1-CROSS-COMMAND-DEDUPE-DISTINCT`, using two fresh-schema fixtures with the same deterministic task/outcome input to prove the success and failure commands retain distinct scope and dedupe identities without creating a forbidden success-then-failure history.

The event regressions deliberately recompute the canonical event digest and stream head after changing one typed payload value. The event store therefore accepts each retained event as structurally valid; the new semantic rebind rejects it. Each probe asserts the exact exception class/category/reason, no prepared success, no task completion, no extra `RecordMaterializedArtifact`, no `ArchiveFailed`, an unchanged complete database fingerprint after fixture preparation, and an unchanged isolated-root fingerprint.

## Definitive database matrix

Every command used process-local credentials recovered read-only from exactly the three owner-approved disposable containers. The three values were identical, the database name matched the mandatory `ghca_acd_archive_test_` safety expression, a new random 32-character restricted-user password was created per process, and no credential value was printed, persisted, documented, or exported beyond that process. No current-site bootstrap or database was accessed.

| Runtime | MySQL 8.0.46 | MySQL 8.4.10 | MariaDB 10.6.27 |
|---|---:|---:|---:|
| PHP 8.3.30 | 1,051 PASS | 1,051 PASS | 1,051 PASS |
| PHP 8.5.7 | 1,051 PASS | 1,051 PASS | 1,051 PASS |

Per cell:

- schema: 55
- P1 persistence: 358
- P2 side records: 263
- P3 digests: 9
- P3 worker: 67
- P3 storage/orphans: 88
- P3B1 task contracts: 173
- P3B1 build coordinator: 4
- P3B1 ledger literal vectors: 7
- P3B1 failure/crash/race classification: 27
- total: 1,051

Across six cells: 6,306 executable assertions. Aggregate suite totals were schema 330, P1 2,148, P2 1,578, P3 digests 54, P3 worker 402, P3 storage/orphans 528, task contracts 1,038, build coordinator 24, ledger vectors 42, and failure/crash/race 162.

## Runtime-only verification

| Verification | PHP 8.3.30 | PHP 8.5.7 |
|---|---:|---:|
| Slice 1A kernel plus accepted digest vectors | 1,246 / 14 suites PASS | 1,246 / 14 suites PASS |
| Legacy baseline | 25 / 2 suites PASS | 25 / 2 suites PASS |
| P3/P3B1 boundary suite | 11 / 1 suite PASS | 11 / 1 suite PASS |
| Archive production/test lint | 76 files PASS | 76 files PASS |

Database-matrix plus kernel, legacy, and boundary evidence totals 8,870 assertions. Lint covered 152 runtime/file combinations.

PHP 8.4 remains optional and unavailable: no `php.exe` exists under the installed Laragon PHP 8.4 directories. No PHP 8.4 result is claimed.

## Concurrency, fencing, crash, and response-loss evidence

- Every final matrix cell's real two-connection lease race returned exactly one non-null 32-character fencing token and one `null`; no task obtained two owners.
- Available claim and expired reclaim use the same validated installed-type predicate in both `SELECT ... FOR UPDATE` and guarded `UPDATE`; capture and packet rows remain byte-for-byte untouched while the eligible ledger row is claimed.
- `P3B1-ATTEMPT5-CRASH-AFTER-COMMAND-BEFORE-TASK-COMPLETE` commits one materialization, reclaims the task, replays the receipt, completes once, and appends no `ArchiveFailed`.
- `P3B1-ATTEMPT5-CRASH-AFTER-OBJECT-BEFORE-COMMAND` reopens the same immutable object, verifies exact bytes/digest, commits the command once, and completes.
- `P3B1-ATTEMPT5-EXHAUSTED-NO-PRIOR-OUTCOME` performs the final deterministic retry and second history lookup before committing `archive_build_attempts_exhausted` exactly once.
- `P3B1-MATERIALIZATION-RACES-FAILURE-SUBMISSION` forces a real stream conflict, reloads authoritative history, and lets matching materialization win; `P3B1-NO-SUCCESS-THEN-ARCHIVE-FAILED` leaves the failure count zero.
- The three structurally valid retained-event probes reject count, manifest, and artifact-identity contradictions before any success replay or later lifecycle command; database and filesystem fingerprints remain unchanged after fixture preparation.
- Wrong-category and wrong-source attempt-five probes dead-letter only with sanitized `task_handler_failed`, invoke no `FailArchive`/UoW, append no `ArchiveFailed`, and leave authoritative database/filesystem fingerprints unchanged.
- Record and failure commands use distinct command IDs, correlation IDs, command types, scope digests, and final dedupe identities even when given the same task-derived outcome key.
- Existing P3A stale-heartbeat, stale-completion, stale-retry/dead-letter, failed-COMMIT rollback, and task-outcome crash replay checks remain green in all six cells.

## Filesystem containment and immutable evidence

- All P3B1 filesystem work uses an injected isolated private root and the accepted private-local store; no caller constructs an absolute production path and no public URL/upload fallback exists.
- The handler stages canonical ledger bytes, validates returned byte count and SHA-256, atomically commits to the deterministic immutable key, then opens and validates the committed object before returning prepared facts.
- Prepared-result negatives cover missing, extra, nested, free-form, cyclic, oversized, and mismatched values before any Build Coordinator/UoW call.
- New/staged digest mismatch is `archive_ledger_invalid`; a positively proven committed identity/retained-byte mismatch is detected as `archive_immutable_conflict`, while a contradiction discovered after retained materialization is operational-blocked without appending a false later `ArchiveFailed`; path escape, infrastructure symlink, unsafe-permission, unsupported-atomic, and unknown cases append no lifecycle failure.
- Existing 88-check P3 storage/orphan suite proves traversal/symlink containment, no overwrite, read-back digest/size/media validation, crash points, committed immutability, orphan safety/recheck, and cleanup confinement in every database cell.
- Negative task-contract cases preserve an aggregate database fingerprint; prepared-result failures preserve event/command counts; test-root cleanup validates its prefix and cannot escape the isolated root.

## Closed failure classification and stable codes

`P3B1-FAILURE-MAPPING-CLOSED` evaluates 107 exact approved source/reason/category/operation-context cases. It proves:

- authoritative task/snapshot/build contradictions map to `archive_build_binding_invalid`;
- invalid or absent bound snapshots map to `archive_snapshot_invalid`, while internal `snapshot_lookup_failed` remains retryable and uses the context-specific sanitized handler/outcome task code;
- submitted repository binding/ledger validation maps to `archive_build_binding_invalid` or `archive_ledger_invalid`;
- retained repository `CATEGORY_INTEGRITY_BLOCKED` variants override submitted-data mappings and become `archive_immutable_conflict`;
- repository insert/lookup/load failures map to retryable `task_handler_failed` outside the fenced UoW and `task_outcome_commit_failed` inside outcome commit;
- newly prepared size/media/digest failures map to `archive_ledger_invalid`, while authoritative committed-object mismatch/collision becomes `archive_immutable_conflict`;
- open/directory/write/staging/commit failures are retryable, containment/configuration/unknown causes are operational-blocked, and unmapped exceptions never invent lifecycle state.
- a high-level reason presented by the wrong exception source, persistence category, or operation context is blocked before any lifecycle mapping; raw error text never reaches durable task state.

Stable task/coordinator result codes in this slice are:

- `task_schema_unsupported`
- `task_payload_invalid`
- `task_type_unsupported`
- `task_handler_failed`
- `task_prepared_result_invalid`
- `task_outcome_commit_failed`
- `task_attempts_exhausted`
- `task_lease_lost`

The closed build-failure catalog is:

- `archive_build_binding_invalid`
- `archive_evidence_incomplete`
- `archive_certificate_invalid`
- `archive_source_drift`
- `archive_snapshot_invalid`
- `archive_ledger_invalid`
- `archive_packet_invalid`
- `archive_verification_failed`
- `archive_immutable_conflict`
- `archive_build_attempts_exhausted`

Only the binding, snapshot, ledger, immutable-conflict, and attempt-exhaustion paths are reachable from the installed P3B1 ledger handler. Raw repository/store exception text is never copied into durable task text.

## Static and scope-boundary evidence

| Check | Result |
|---|---:|
| Plugin entrypoint archive references | 0 |
| Archive WordPress hooks/activation registrations | 0 |
| Archive cron, scheduler, Action Scheduler, or WP-CLI registrations | 0 |
| Archive REST/controller surface | 0 |
| Current-site bootstrap/global credentials | 0 |
| Network calls/URLs | 0 |
| Runtime root/secret/uploads configuration loading | 0 |
| P3B1 schema DDL | 0 |
| P3B1 debug output | 0 |
| Hardcoded/runtime-loaded key surface | 0 |
| Immutable event/snapshot/artifact/ledger update/delete/replace SQL | 0 |
| Schema/manifest/migrator file changes | 0 |
| `git diff --check` | PASS |

## Deviations and unresolved contracts

- No implementation deviation remains.
- The owner-approved boundary-test amendment is the sole change outside the original allowlist and changes test policy only.
- D16 remains intentionally unresolved. P3B1 does not enqueue an installed ledger task from `ArchiveRetryRequested` and selects no reuse/rebinding/invalidation lifecycle model.
- Capture, certificate acquisition, packet rendering, verify/finalize, production secret/path loading, health exposure, wake-up/runtime composition, current-site reads, activation, and all P3B2/P3B3 work remain deferred.
- PHP 8.4 remains unavailable and unclaimed.
- Status is **formally accepted**.
