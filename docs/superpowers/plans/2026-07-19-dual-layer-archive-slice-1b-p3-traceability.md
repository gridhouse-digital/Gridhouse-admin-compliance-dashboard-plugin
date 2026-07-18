# Slice 1B-P3A Durable Worker and Private Artifact Storage Traceability

**Status:** formally accepted after independent owner review
**Date:** 2026-07-19
**Branch:** `feature/dual-layer-archive-slice-1b-p3-workers`
**Starting HEAD:** `aa64119f3256e2e681f9f6e96654450d33a97fb2`
**Ending HEAD:** `aa64119f3256e2e681f9f6e96654450d33a97fb2`
**Remediation status:** all five original remediation areas, approved H1 cursor authentication, and owner-approved C2 compatibility-floor work are implemented and pass the definitive required matrix

## Scope result

P3A now provides a directly callable dark-mode worker coordinator, portable durable-task claim/reclaim and lease fencing, deterministic retry/dead-letter behavior, task-fenced command replay, one private-local immutable artifact store, and report-only orphan reconciliation. No production task handler, worker runner, WordPress wake-up path, controller, renderer, capture adapter, download surface, reset executor, projection rebuild executor, schema change, activation path, or runtime configuration loader was added.

The implementation remains inactive. It is constructed only by isolated tests and does not appear in the plugin entrypoint.

The approved R1 result document is exactly `{"result_code":"committed"}`: the fixed key is 11 bytes, the dynamic value is 9 bytes, and the canonical document is exactly 27 bytes. R2 provides streaming traversal, incomplete-page suppression, bounded global ordering, frozen-cutoff continuation, and live candidate revalidation. Approved Decision H1 now authenticates the entire canonical cursor before any continuation field can influence behavior. The production key remains constructor-injected and deliberately unwired.

## Approved assumptions retained

- Read/write envelope: approximately 100 lifecycle reads per write, validated at 100 reads/second and 5 lifecycle writes/second per site.
- Worker concurrency: up to five artifact workers per site.
- Tenancy: one operational tenant per WordPress site.
- Data sensitivity: employment/training PII only; PHI and PCI remain prohibited.
- Reliability: 99.9% monthly lifecycle API availability, lifecycle-command p99 at most 1,000 ms excluding asynchronous artifact work, zero RPO for acknowledged event commits, disaster-recovery RPO at most 15 minutes, and RTO at most four hours.
- Compatibility: distributed minimum PHP 8.3 under Decision C2; exact PHP 8.3.30 and PHP 8.5.7 verification; MySQL 8.0/8.4 and MariaDB 10.6 portable SQL. Historical PHP 7.4 evidence is retained unchanged in its original records.

## Files added

- `docs/superpowers/plans/2026-07-18-dual-layer-archive-slice-1b-p3-worker-decisions-proposal.md`
- `docs/superpowers/plans/2026-07-19-dual-layer-archive-slice-1b-p3-traceability.md`
- `docs/superpowers/plans/2026-07-20-dual-layer-archive-slice-1b-p3a-remediation-decisions-proposal.md`
- `docs/superpowers/plans/2026-07-21-dual-layer-archive-slice-1b-p3a-cursor-authentication-decision-proposal.md`
- `docs/superpowers/plans/2026-07-22-dual-layer-archive-p3a-php-8-compatibility-floor-decision-proposal.md`
- `docs/superpowers/plans/2026-07-23-dual-layer-archive-p3a-php-8-3-compatibility-floor-decision.md`
- `includes/archive/application/class-archive-orphan-reconciler.php`
- `includes/archive/application/class-archive-worker-coordinator.php`
- `includes/archive/contracts/interface-archive-artifact-store.php`
- `includes/archive/infrastructure/class-archive-artifact-store-exception.php`
- `includes/archive/infrastructure/class-private-archive-artifact-store.php`
- `tests/archive/p3-lease-race-worker.php`
- `tests/archive/test-p3-boundaries.php`
- `tests/archive/test-p3-digests.php`
- `tests/archive/test-p3-storage.php`
- `tests/archive/test-p3-worker.php`

## Files modified

- `README.md`
- `docs/superpowers/plans/2026-07-13-dual-layer-archive-development-handoff.md`
- `docs/superpowers/plans/2026-07-13-dual-layer-archive-event-sourcing-technical-design.md`
- `gridhouse-admin-compliance-dashboard.php`
- `includes/archive/application/class-archive-unit-of-work.php`
- `includes/archive/infrastructure/class-archive-canonical-json.php`
- `includes/archive/infrastructure/class-archive-digester.php`
- `includes/archive/infrastructure/class-wpdb-archive-task-store.php`
- `tests/archive/bootstrap.php`
- `tests/archive/persistence-bootstrap.php`
- `tests/archive/persistence-fixtures.php`
- `tests/archive/test-all.ps1`
- `tests/archive/test-persistence.php`

No schema, uninstall, runtime bootstrap, or archive behavior was modified for C2. The plugin entrypoint change is metadata-only (`Requires PHP: 8.3`); `README.md` and the two active design/handoff documents carry the matching release policy.

### H1-only files changed in this remediation

- `includes/archive/contracts/interface-archive-artifact-store.php`
- `includes/archive/application/class-archive-orphan-reconciler.php`
- `includes/archive/infrastructure/class-private-archive-artifact-store.php`
- `tests/archive/test-p3-storage.php`
- `docs/superpowers/plans/2026-07-21-dual-layer-archive-slice-1b-p3a-cursor-authentication-decision-proposal.md`
- `docs/superpowers/plans/2026-07-20-dual-layer-archive-slice-1b-p3a-remediation-decisions-proposal.md`
- `docs/superpowers/plans/2026-07-19-dual-layer-archive-slice-1b-p3-traceability.md`

No other production or test source was changed for H1.

## Decision-to-code-to-test mapping

| Decision | Implemented contract | Production source | Executable evidence |
|---:|---|---|---|
| 1 | 120-second lease from claim time | `GHCA_ACD_WPDB_Archive_Task_Store::LEASE_SECONDS`, `claim_selected()`, `lease_until()` | `TASK-LIVE-LEASE-NOT-STOLEN`, `TASK-EXPIRED-LEASE-RECLAIM` |
| 2 | 30-second cooperative heartbeat policy; strict extension no more than 120 seconds from heartbeat time | task-store `HEARTBEAT_SECONDS`, `heartbeat()`; coordinator heartbeat callback | `WORKER-HEARTBEAT-30-SECOND-POLICY`, `TASK-HEARTBEAT-MAX-EXTENSION`, `TASK-STALE-HEARTBEAT` |
| 3 | Fixed retry delays 60/300/900/3,600 seconds for attempts 1-4; attempt 5 dead | task-store `RETRY_DELAYS`, `retry()`; coordinator `retry_or_dead()` | `TASK-RETRY-AVAILABILITY`, `TASK-MAX-ATTEMPTS-DEAD-EXACTLY-ONCE`, `TASK-MAX-ATTEMPTS-NO-SECOND-DEAD-LETTER` |
| 4 | Deterministic fixed delays without jitter | task-store constants and portable `CASE`/`DATE_ADD` update | exact retry timestamps in `test-p3-worker.php` on all three database families |
| 5 | Claim batch 1; one invocation performs at most one task or exhausted disposition | `GHCA_ACD_Archive_Worker_Coordinator::run_once()` | deterministic order and one-result assertions in `test-p3-worker.php` |
| 6 | Closed stable-code/fixed-message catalog; new text at most 512 UTF-8 bytes; retained v1 text up to 2,000 bytes remains readable; successful handler output is only the exact 27-byte canonical `result_code=committed` document | coordinator `FAILURE_MESSAGES`, exact outcome validator, and task-store retained-row validators | fixed-message tests plus all six `WORKER-OUTCOME-*` remediations |
| 7 | Frozen `pending`, `leased`, `retry`, `completed`, and `dead` transitions; all worker mutations fenced; attempt count never above 5 | task-store claim, heartbeat, completion, retry, dead-letter, and row validators | `TASK-CLAIM-DETERMINISTIC-ORDER`, all stale-mutation tests, retry timing, and maximum-attempt tests |
| 8 | Unknown task schema is leased by envelope, dead-lettered without payload decode, handler, or external side effect | task-store `load_claimed()`/`validate_claimed_v1()` split; coordinator schema branch | `TASK-INVALID-SCHEMA`, `TASK-INVALID-PAYLOAD`, `TASK-INVALID-TYPE` zero-handler assertions |
| 9 | A crash after outcome-command commit replays the stored response under a new live fence and completes without duplicate authoritative rows | optional Unit-of-Work `task_fence`; coordinator outcome-before-completion order | `TASK-OUTCOME-CRASH-REPLAY`, `TASK-OUTCOME-CRASH-NO-DUPLICATES`, `TASK-OUTCOME-STALE-FENCE-REPLAY-REJECTED` |
| 10 | Frozen `ghca-task-outcome-v1` digest over exact ordered task ID, schema version, and logical outcome | `GHCA_ACD_Archive_Digester::task_outcome()` | 9-check `test-p3-digests.php`, including independent 121-byte literal and fixed SHA-256 vector |
| 11 | Immutable-ID-only staging and committed keys with exact v1 formats | artifact-store `identity()`, key builders, and key validators | malformed key, identifier, staging uniqueness, and exact committed-key tests |
| 12 | Certificate 16 MiB, ledger 8 MiB, packet 64 MiB; PDF paths streamed in at most 1 MiB chunks | private store `MAX_BYTES`, `CHUNK_BYTES`, streamed write/read-back | `ARTIFACT-CERTIFICATE-SIZE-CEILING`, `ARTIFACT-CERTIFICATE-STREAMED`, `ARTIFACT-LEDGER-8M-CANONICAL`, `ARTIFACT-PACKET-64M-BOUNDED-MEMORY` |
| 13 | PDF header/tail/startxref validation with a complete classic `xref` token/line delimiter or bounded `/Type /XRef` stream; exact UTF-8 canonical ledger v1; size and SHA-256 read-back | private store media and read-back validators; explicitly bounded canonical JSON entry points | `ARTIFACT-PDF-XREF-TOKEN-REJECTED`, `ARTIFACT-PDF-CLASSIC-XREF-ACCEPTED`, `ARTIFACT-PDF-XREF-STREAM-ACCEPTED`, wrong-type/truncated and ledger tests |
| 14 | Injected pre-existing absolute root; POSIX 0700/0600 fail closed; Windows chmod best effort with provisioned ACL authoritative | private-store constructor and permission helpers | public-root, test-root, streamed-write, and cross-runtime Windows filesystem tests |
| 15 | Traversal, absolute/drive/UNC, mixed-separator, control, invalid-ID, symlink, public-root, overwrite, and collision protections; hard-link no-overwrite commit | private-store path containment, safe-open, exclusive staging, hard-link commit, and exact reuse | named traversal/path/symlink/collision/no-overwrite/crash tests in `test-p3-storage.php` |
| 16 | 86,400-second frozen safety cutoff; bounded stateless traversal; complete global `(mtime, logical_key)` ordering; full-canonical-cursor HMAC verified before field use | private-store cursor issuer/verifier, nullable continuation cutoff contract, reconciler first-call-only clock | all progress/order/stale tests plus five `ORPHAN-CURSOR-HMAC-*-TAMPER-REJECTED` tests, fixed/issued vectors, valid continuation, wrong-key, rotation, and non-exposure tests |
| 17 | P3A orphan disposition is report only | reconciler exposes classification only; artifact-store interface has no delete/quarantine method | `ORPHAN-REPORT-ONLY-NO-MUTATION` and all failure no-mutation fingerprints |
| 18 | Exact artifact ID/storage-key repository recheck immediately before each classification; mismatch or integrity failure aborts | `GHCA_ACD_Archive_Orphan_Reconciler::reconcile()` and existing descriptor repository | exact-reference protection, staging alias, binding mismatch, database/integrity abort, and recheck-race tests |
| 19 | Test roots are exact `ghca_acd_archive_test_{32hex}` direct children of the canonical system temp directory; cleanup cannot cross that boundary or follow links | P3 storage test helpers only | `TEST-CLEANUP-CANNOT-ESCAPE-ROOT`, `TEST-CLEANUP-BOUNDARY-NO-RESIDUE`, final temp-root enumeration zero |
| 20 | No wake-up mechanism, runner, hook, cron, Action Scheduler, registered CLI, REST/admin controller, activation, or runtime loader | absence enforced across entrypoint and archive source; coordinator/reconciler directly callable | 11-check `test-p3-boundaries.php` passes on both Decision C2 required runtimes, PHP 8.3.30 and 8.5.7 |

## Confirmed finding to root fix to named regression

| Finding | Root fix | Named regression evidence |
|---|---|---|
| Task-claim COMMIT cleanup | `commit()` retains transaction ownership until COMMIT succeeds, explicitly rolls back a failed/uncertain COMMIT, and poisons the store if rollback cleanup itself fails; `begin()` rejects nested or unclean use | `TASK-CLAIM-COMMIT-FAILURE-ROLLBACK` proves exact exception/code, zero claim residue, rollback attempt, no active InnoDB transaction, and a normal subsequent claim |
| Lease loss during disposition | One category-aware fence classifier translates only `integrity_blocked` zero-row lease failures; internal query failures are rethrown | `WORKER-LEASE-LOSS-DURING-RETRY`, `WORKER-LEASE-LOSS-DURING-DEAD-LETTER`, `WORKER-DATABASE-FAILURE-NOT-MASKED-AS-LEASE-LOSS` |
| Free-form/unbounded handler outcome | Canonical encode runs first, then a closed exact-shape/value/9-byte dynamic/27-byte total contract is enforced; rejected text is never forwarded or persisted | `WORKER-OUTCOME-DEPTH-BOUND`, `WORKER-OUTCOME-VALUE-COUNT-BOUND`, `WORKER-OUTCOME-STRING-BOUND`, `WORKER-OUTCOME-RECURSION-REJECTED`, `WORKER-OUTCOME-FREE-FORM-REJECTED`, `WORKER-OUTCOME-VALID-MACHINE-FIELDS` |
| Malformed PDF xref prefix | Classic xref requires the entire `xref` token followed only by horizontal whitespace and a CR/LF delimiter; bounded xref-stream support remains | `ARTIFACT-PDF-XREF-TOKEN-REJECTED`, `ARTIFACT-PDF-CLASSIC-XREF-ACCEPTED`, `ARTIFACT-PDF-XREF-STREAM-ACCEPTED` |
| Orphan starvation/unbounded materialization | `DirectoryIterator` continuation stack, 32,768-byte authenticated cursor ceiling, five-frame depth ceiling, 101-candidate retained frontier, final candidate revalidation, and fresh exclusive-after traversal per page | `ORPHAN-TRUNCATED-SCAN-MAKES-PROGRESS`, `ORPHAN-TRUNCATED-SCAN-NO-DUPLICATE-PREFIX`, `ORPHAN-LARGE-DIRECTORY-BOUNDED`, `ORPHAN-STABLE-ORDER-ACROSS-PAGES`, `ORPHAN-REPORT-ONLY-NO-MUTATION` |
| Caller-controlled cursor state | Dedicated injected 32-byte key; domain-separated HMAC-SHA-256 over all seven `ghca-cjson-1` payload fields; constant-time verification precedes structural/root/cutoff/filter/traversal/candidate checks | five field-tamper tests with zero descriptor queries/residue, `ORPHAN-CURSOR-HMAC-VALID-CONTINUATION`, `ORPHAN-CURSOR-HMAC-WRONG-KEY-REJECTED`, `ORPHAN-CURSOR-HMAC-NOT-EXPOSED`, `ORPHAN-CURSOR-HMAC-ROTATION-RESTART`, and independent fixed/issued vectors |

## Durable-task SQL and fencing evidence

- Expired reclaim and available claim are separate methods and separate transactions.
- Each selector uses `SELECT ... FOR UPDATE`, `ORDER BY available_at_gmt, task_row_id`, and `LIMIT 1`.
- The available selector is restricted to `pending`/`retry`; the expired selector is restricted to expired `leased` rows. Neither uses a cross-state `OR` or `SKIP LOCKED`.
- Claim transactions commit before the coordinator loads the task or invokes a handler.
- Heartbeat, completion, retry, dead-letter, and authoritative outcome fencing compare task ID, `task_state = leased`, lease owner, lease token, and `lease_until_gmt > now`.
- The Unit of Work takes the task-row lock before stream/receipt locks and validates the same live lease for both new command append and stored-response replay.
- Bounded retry handles a real deadlock/lock-timeout victim without relaxing ownership. The loser reselects after rollback and returns no claim.

## Definitive Decision C2 database matrix

One definitive 897.1-second final-code runner invocation completed all six required runtime/database cells with exit code `0`. Every cell ran 55 schema checks, 358 P1 persistence checks, 263 P2 side-record checks, 9 P3 digest checks, 67 P3 worker checks, and 88 P3 storage/orphan checks: exactly 840 assertions per cell.

| PHP | MySQL 8.0.46 (`127.0.0.1:33061`) | MySQL 8.4.10 (`127.0.0.1:33062`) | MariaDB 10.6.27 (`127.0.0.1:33063`) |
|---|---:|---:|---:|
| 8.3.30 | 840 PASS | 840 PASS | 840 PASS |
| 8.5.7 | 840 PASS | 840 PASS | 840 PASS |

- Schema assertions: 330.
- P1 persistence assertions: 2,148.
- P2 side-record assertions: 1,578.
- P3 digest assertions: 54.
- P3 worker assertions: 402.
- P3 storage/orphan assertions: 528.
- Total definitive Decision C2 database-matrix assertions: 5,040.
- Every target name was `ghca_acd_archive_test_db`, satisfying the required `ghca_acd_archive_test_` prefix.
- The disposable targets identified themselves as MySQL 8.0.46, MySQL 8.4.10, and MariaDB 10.6.27.
- The bootstrap created temporary restricted users for DDL-denial testing and loaded neither `wp-load.php`, `wp-config.php`, current-site credentials, nor the current-site database.
- The exact runtime preflight verified PHP 8.3.30 and PHP 8.5.7 plus `mysqli` before any database cell ran. No PHP 8.0 runtime was downloaded, installed, or executed.

Decision C1's proposed PHP 8.0 floor was superseded before implementation. Decision C2 makes PHP 8.3 the distributed minimum; earlier PHP 7.4 results remain historical evidence in their original trace records and were not rewritten or presented as current required-runtime results.

PHP 8.4 also remains unavailable. `C:\laragon\bin\php\php-8.4.12-nts-Win32-vs17-x64` contains no `php.exe`; no PHP 8.4 result is claimed.

## Independent regression and compatibility evidence

| Verification | PHP 8.3.30 | PHP 8.5.7 |
|---|---:|---:|
| Slice 1A kernel plus P2 digest vectors | 1,246 / 14 suites PASS | 1,246 / 14 suites PASS |
| Legacy baseline | 25 / 2 suites PASS | 25 / 2 suites PASS |
| P3A source-boundary suite | 11 / 1 suite PASS | 11 / 1 suite PASS |
| Archive production/test lint | 68 files PASS | 68 files PASS |

Completed Decision C2 executable assertions across the database matrix, kernel, legacy, and boundary runs: 7,604. Syntax validation covered 136 runtime/file combinations.

## Two-connection lease-race evidence

`TASK-TWO-CONNECTION-LEASE-RACE` starts two independent PHP processes with real database connections and releases them against the same available task. Every one of the six completed post-remediation cells returned one lease token and one `null`; no cell returned two owners. Representative traces were:

- `[null,"606e408fcf460dcbc746c362159da517"]`
- `["606e408fcf460dcbc746c362159da517",null]`

Both orderings occurred, proving the winner was scheduling-dependent while ownership remained exclusive. The persisted row's owner/token matched the one non-null result.

`TASK-CLAIM-COMMIT-FAILURE-ROLLBACK` injected a failed COMMIT before the database executed it. The store attempted explicit ROLLBACK, the database fingerprint remained unchanged, `information_schema.innodb_trx` reported no active transaction for the connection, and the same store/connection then claimed the pending row normally.

## Crash and failure-injection evidence

### Worker outcome crash

The crash-replay fixture commits a real `StartBuild` outcome through the Unit of Work, deliberately omits task completion, expires/reclaims the task, then invokes a second coordinator. The second claimant recomputes the same `ghca-task-outcome-v1` key, receives the stored command response under its new live task fence, and completes. Event-row, command-receipt, and artifact-descriptor counts remain exactly unchanged on replay. A stale prior lease cannot receive the stored response.

### Artifact crash points

- Before staging write: one empty staging object remains discoverable.
- After staging write: one fully verified staging object remains discoverable.
- Before hard-link publish: no committed key exists.
- After hard-link publish/before staging unlink: both names may exist, the committed bytes are already immutable, and retry recovers by exact verified reuse without overwrite.
- After blob commit/before descriptor transaction: the committed file is reported as an unreferenced orphan; no lifecycle event is inferred.
- Existing committed mismatches and unsafe collisions preserve the original committed object and leave no overwrite path.

Every negative P3 test asserts the exact exception class and stable reason code. Database residue and filesystem fingerprints are asserted unchanged at the relevant boundary; malformed path and cleanup tests additionally assert no residue outside the isolated root.

### Orphan continuation and failure injection

- A 1,201-object single directory returned exactly 1,000 scanned entries, zero candidates, a bounded continuation, and at most 8 MiB additional live memory; no `scandir()` materialization remains.
- A 205-candidate tree completed in six bounded calls. Every incomplete traversal returned zero candidates, each completed page contained at most 100, all 205 keys were emitted once, and the concatenated pages were globally ordered by `(mtime, logical_key)`.
- The test clock advanced from 2026-07-18 to 2026-07-30 after the first call; all continuations retained the first cursor's cutoff.
- The store authenticates `cursor_schema_version`, `root_digest`, `older_than_epoch`, `after`, `area_index`, `stack`, and `selected` with HMAC-SHA-256 over the exact domain-prefixed canonical payload. The complete signed cursor remains inside 32,768 canonical bytes.
- Structurally valid edits to `older_than_epoch`, `after`, `area_index`, `stack.next_position`, and `selected` each failed as `orphan_cursor_invalid` before candidate emission, filesystem traversal, or descriptor access. Every probe asserted zero descriptor queries and unchanged database/filesystem fingerprints.
- A second store with the same key continued successfully; a different key rejected the old cursor; starting from `null` under the replacement key succeeded. Neither raw nor hexadecimal key material appeared in cursor/result JSON or exception text.
- Independent fixed and production-issued HMAC vectors matched. A wrong-root cursor failed as `orphan_cursor_invalid`. Changing a buffered candidate's live mtime after successful authentication failed as `orphan_cursor_stale`.
- Whole-tree fingerprints before and after every page remained identical; P3A still has no delete, quarantine, move, or rename disposition.

## Filesystem containment and immutability evidence

- The private root and at least one public document root are injected. The store rejects equality, containment in either direction, non-real roots, and a symlink root.
- Logical keys contain only fixed path labels/extensions and exact 32-lowercase-hex immutable identifiers.
- Staging creation uses exclusive `x+b`; publish uses same-volume hard-link creation and has no rename/replace fallback.
- A verified pre-existing committed object is idempotent success; any safe mismatch is `artifact_immutable_mismatch`; an unsafe object is `artifact_commit_collision`.
- Certificate and packet paths write, hash, and read back in chunks no larger than 1 MiB. A full 64 MiB packet completed with at most 8 MiB additional peak memory in the regression.
- The ledger test accepts exactly 8 MiB of canonical v1 JSON through the explicit bounded codec; all default canonical JSON entry points retain the frozen 1 MiB ceiling.
- PDF structural validation uses the exact 8-byte header, at most 65,536 tail bytes, and at most 8,192 bytes at `startxref`.
- The malformed `xrefNOT_A_TABLE` target is rejected; valid classic line-delimited xref tables and `/Type /XRef` streams are retained.
- The symlink-escape assertion runs where the Windows account permits symlink creation and otherwise records the required supported-platform skip; containment and regular-file identity checks always run.
- Orphan discovery is report-only, carries only a bounded validated cursor, revalidates buffered files before emission, and rechecks the immutable descriptor repository immediately before classification.
- Final enumeration found zero `ghca_acd_archive_test_{32hex}` directories remaining directly below the system temp directory.

## Stable P3A failure-code catalog

| Boundary | Stable codes |
|---|---|
| Task selection/claim | `task_reclaim_select_failed`, `task_available_select_failed`, `task_claim_update_failed`, `invalid_task_attempt` |
| Task fencing | `invalid_task_lease_window`, `task_lease_extension_exceeded`, `task_heartbeat_fence_failed`, `task_completion_fence_failed`, `task_retry_fence_failed`, `task_dead_letter_fence_failed`, `task_outcome_fence_failed`, `task_lease_lost` |
| Task execution | `task_schema_unsupported`, `task_payload_invalid`, `task_type_unsupported`, `task_handler_failed`, `task_outcome_commit_failed`, `task_outcome_idempotency_invalid`, `task_attempts_exhausted` |
| Artifact root/path | `artifact_root_invalid`, `artifact_root_public`, `artifact_permissions_unsafe`, `artifact_directory_create_failed`, `artifact_key_invalid`, `artifact_path_escape`, `artifact_symlink_rejected` |
| Artifact write/commit/read | `artifact_staging_collision`, `artifact_write_failed`, `artifact_size_exceeded`, `artifact_size_mismatch`, `artifact_digest_mismatch`, `artifact_media_invalid`, `artifact_atomic_commit_unsupported`, `artifact_commit_collision`, `artifact_commit_failed`, `artifact_open_failed`, `artifact_immutable_mismatch` |
| Orphan discovery | `orphan_scan_failed`, `orphan_cursor_key_invalid`, `orphan_cursor_invalid`, `orphan_cursor_stale`, `orphan_reference_recheck_failed`, `orphan_reference_binding_mismatch` |
| Orphan result codes | `orphan_unreferenced`, `orphan_staging_unreferenced`, `referenced_protected` |
| Test-only containment | `test_storage_root_invalid`, `test_cleanup_boundary_violation` |

Existing P1/P2 validation, persistence, command-idempotency, and retained-row reason codes remain in force and were not renamed.

## Static and repository-boundary evidence

| Check | Result |
|---|---:|
| Plugin-entrypoint archive references | 0 |
| Archive WordPress hook, REST, activation, cron, Action Scheduler, or registered WP-CLI wiring | 0 |
| Archive production network calls | 0 |
| Archive production current-site bootstrap references | 0 |
| Archive production credential/API-key references | 0 |
| H1 production runtime key-loading or hardcoded key references | 0 |
| Archive production debug output | 0 |
| Immutable event/snapshot/artifact-descriptor/ledger SQL update, delete, or replace | 0 |
| Schema-file diff lines | 0 |
| Unexpected filesystem calls outside the private-local store | 0 |
| `git diff --check` errors | 0 |
| Changed/untracked file trailing-whitespace hits | 0 |
| Unapproved PHP 8.3 refactor or archive-behavior diff | 0 |

A broad text search found four occurrences of `schedule`; all four are pre-existing domain fields named `scheduled_expires_at_gmt`, not scheduling or worker wake-up code.

## Formal self-review

The PHP code-review and security-fix rules were applied after the complete available matrix. Manual review concentrated on transaction cleanup, category-aware exception translation, handler-text leakage, bounded collections, guaranteed stream closure, filesystem containment, cursor authentication order, secret non-exposure, and query construction. The original caller-controlled-cutoff probe no longer reproduces: the reconciler reads no continuation field, and the store validates the bounded envelope and HMAC with `hash_equals()` before any cursor payload value reaches cutoff, filtering, traversal, filesystem, or descriptor logic.

The generic quality analyzer reports expected structural warnings for the task store and private-local store (large classes, long validation methods, and branch complexity). Those branches encode the frozen row-state, media, path, and cursor invariants; splitting them into a competing queue, storage framework, or service graph would broaden P3A. All error-suppressed filesystem calls in the private store check the returned value or immediately perform read-back/containment validation. No debug output, hardcoded/runtime-loaded cursor key, network call, unbounded candidate collection, open database transaction during handler work, or unclosed production stream was found.

## Deviations and unresolved contracts

No H1 or C2 contract deviation remains. The dedicated key is injected only by tests; production provisioning/construction is intentionally absent under the dark-mode boundary. Cursor authentication is stateless and replayable, rotation invalidates in-flight cursors, and callers restart from `null` exactly as approved. The Decision C2 required PHP 8.3.30 and PHP 8.5.7 matrix is complete. PHP 8.4 remains optional and unavailable because the configured location has no CLI; no PHP 8.4 result is claimed. Independent owner review formally accepted P3A, H1, and Decision C2.

The canonical JSON codec gained explicit bounded encode/decode methods solely for the approved complete 8 MiB ledger validation. Its existing default methods remain capped at 1 MiB, and all pre-existing canonical tests continue to pass.

The following remain deliberately unresolved and unauthorized for later owner decisions: production worker wake-up, runtime configuration loading, production task handlers, LearnDash capture, certificate acquisition, ledger/packet business rendering, download controllers, reset execution/reconciliation, projection rebuild execution, and orphan quarantine/deletion.

## Final repository state

- Branch: `feature/dual-layer-archive-slice-1b-p3-workers`.
- Starting HEAD: `aa64119f3256e2e681f9f6e96654450d33a97fb2`.
- Ending HEAD: `aa64119f3256e2e681f9f6e96654450d33a97fb2`.
- Staged files: none.
- Commit performed: no.
- Push performed: no.
- Deployment or activation performed: no.
- Current-site database accessed: no.
- `.claude/settings.local.json`: pre-existing and untracked; `.claude/` was not read, modified, removed, staged, or otherwise touched.
- P3B work started: no.

P3A, H1, and Decision C2 are formally accepted after independent owner review. P3B has not started. Runtime wiring and feature activation remain unauthorized.
