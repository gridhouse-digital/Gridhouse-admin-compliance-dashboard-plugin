# Slice 1B-P3A Worker and Private-Storage Decisions Proposal

**Status:** approved for P3A dark-mode implementation
**Date:** 2026-07-18
**Approval date:** 2026-07-18
**Approval evidence:** repository owner explicitly responded "Approved as written."
**Runtime effect:** none
**Scope:** durable worker core, private-local immutable artifact storage, and report-only orphan reconciliation in dark mode

**Decision C2 compatibility amendment:** the distributed minimum is PHP 8.3, and the required P3A verification runtimes are exact PHP 8.3.30 and PHP 8.5.7. This release-policy amendment changes no worker, storage, cursor, or retained-data contract in this record.

## Approval boundary

This record authorizes only the minimum dark-mode P3A implementation described below. Approval does not authorize runtime wiring, current-site access, schema changes, staging, commits, pushes, deployment, activation, or production task execution.

The Architecture PRD and Technical Design remain authoritative. The accepted P1/P2 contracts remain frozen except for the operational task-state behavior explicitly identified below. No schema change is proposed.

## Preflight evidence

| Check | Evidence |
|---|---|
| Branch | `feature/dual-layer-archive-slice-1b-p3-workers` |
| Starting HEAD | `aa64119f3256e2e681f9f6e96654450d33a97fb2` |
| Parent | `98050a75b4b7de74798e431d3fed06b3d98b1c7a` |
| HEAD subject | `feat(archive): add immutable snapshot and side-record persistence` |
| Worktree | Clean except pre-existing untracked `.claude/settings.local.json`; `.claude/` was not read, changed, or removed. |
| Plugin entrypoint | Zero archive references in `gridhouse-admin-compliance-dashboard.php`. |
| Runtime/I/O surface | Zero archive hooks, cron registration, WP-CLI registration, filesystem calls, or network calls in current `includes/archive/` production PHP. |
| Schema | Frozen 13-table v1 manifest; no schema change is required for this proposal. |

The current implementation was inspected at the task store, Unit of Work, schema/manifest, snapshot store, artifact descriptor repository, task-payload construction, persistence fixtures, two-connection task tests, side-record tests, and database safety bootstrap boundaries.

Current P3-relevant gaps are explicit:

1. The frozen schema and Technical Design define `retry`, but `GHCA_ACD_WPDB_Archive_Task_Store` currently reschedules to `pending` and rejects retained `retry` rows.
2. The current `claim()` accepts a known task ID and combines pending claim with expired-lease reclaim. P3 requires two deterministic queue-selection transactions.
3. The current retained-row loader throws on an unknown task schema before a worker can lease and dead-letter it.
4. The current artifact repository stores immutable descriptors only; no byte store, worker coordinator, or orphan reconciler exists.
5. The Unit of Work has receipt-first command replay but no task-fence input. A worker outcome therefore needs one in-transaction live-lease assertion before it may append.

## Accepted baseline run

The mandatory baseline ran with PHP `8.3.30` against the disposable database `ghca_acd_archive_test_db` on `127.0.0.1:33061`. The target identified itself as MySQL `8.0.46`. The bootstrap loaded neither `wp-load.php` nor `wp-config.php` and used no current-site credentials.

| Suite | Result |
|---|---:|
| Schema migration | 55/55 PASS |
| P1 persistence | 358/358 PASS |
| P2 side-record persistence | 263/263 PASS |
| Slice 1A kernel plus P2 digest vectors | 1,246/1,246 PASS across 14 suites |
| Legacy plugin baseline | 25/25 PASS across 2 suites |

All 19 invoked suites exited `0`.

## Proposed decisions

### 1. Task lease duration

- **Recommended value:** 120 seconds from claim time.
- **Reason:** It is long enough for ordinary database and local-file phases while remaining short enough to recover a crashed worker. Longer work must heartbeat cooperatively; no transaction spans the work.
- **Affected contract/class:** `GHCA_ACD_WPDB_Archive_Task_Store`; `GHCA_ACD_Archive_Worker_Coordinator` policy constant.
- **Stable failure code:** `invalid_task_lease_window` for an invalid requested window; `task_lease_lost` for an operational result after fencing loss.
- **Later change impact:** operational policy only for future claims; already persisted `lease_until_gmt` values remain authoritative until they expire.

### 2. Heartbeat interval and maximum lease extension

- **Recommended value:** heartbeat every 30 seconds while external work is active. Each successful heartbeat sets `lease_until_gmt` to no more than 120 seconds after the heartbeat time and must strictly extend the current lease. There is no unbounded single-call extension.
- **Reason:** Four missed heartbeat opportunities occur before expiry. The per-heartbeat horizon is enforceable with the frozen schema; a cumulative lifetime cap would require a new persisted claim-start field and is therefore not proposed.
- **Affected contract/class:** `GHCA_ACD_WPDB_Archive_Task_Store::heartbeat()`; handler heartbeat callback supplied by `GHCA_ACD_Archive_Worker_Coordinator`.
- **Stable failure codes:** `task_heartbeat_fence_failed`, `task_lease_extension_exceeded`, `invalid_task_lease_window`.
- **Later change impact:** operational policy only; retained task payloads and lifecycle data are unaffected.

### 3. Retry delays for attempts 1-5

- **Recommended value:** attempt 1 failure: 60 seconds; attempt 2: 300 seconds; attempt 3: 900 seconds; attempt 4: 3,600 seconds; attempt 5: no retry and transition to `dead`.
- **Reason:** The fixed table bounds recovery to five attempts, avoids a hot retry loop, and remains easy to verify across databases and runtimes.
- **Affected contract/class:** `GHCA_ACD_WPDB_Archive_Task_Store::retry()` and worker policy constants.
- **Stable failure codes:** the sanitized cause remains in `last_error_code`; the terminal operational result is `task_attempts_exhausted`.
- **Later change impact:** changing the table affects future `available_at_gmt` calculations only. Existing retry timestamps remain unchanged operational records.

### 4. Backoff algorithm and jitter

- **Recommended value:** deterministic fixed delay table from Decision 3; no random jitter.
- **Reason:** At five workers per site, deterministic delays are sufficient for P3A and make crash/concurrency tests exact. Jitter is not justified by the approved load envelope.
- **Affected contract/class:** worker policy constants used by `GHCA_ACD_Archive_Worker_Coordinator` and the task store.
- **Stable failure code:** none; invalid attempt input uses `invalid_task_attempt`.
- **Later change impact:** operational policy only, except already persisted `available_at_gmt` values are not recalculated.

### 5. Claim batch and runner work bound

- **Recommended value:** claim batch size 1; one coordinator invocation processes at most one task or one exhausted expired-lease disposition.
- **Reason:** External phases may be long. One claim keeps transactions short, gives five site workers natural concurrency, and avoids speculative batching or an unused runner framework.
- **Affected contract/class:** one directly callable `GHCA_ACD_Archive_Worker_Coordinator::run_once()` method.
- **Stable failure codes:** `task_reclaim_select_failed`, `task_available_select_failed`, `task_claim_update_failed`.
- **Later change impact:** operational policy only.

### 6. Operational failure codes and error text

- **Recommended value:** codes must match `^[a-z][a-z0-9_.-]{0,63}$`. The coordinator owns one closed, reviewed `stable code => fixed message` map. New P3A writes persist only the exact fixed message mapped to the selected stable code, with a 512 UTF-8 byte ceiling for each reviewed map value. Handlers may return machine fields only; handler-supplied free-form context is not accepted, appended, sanitized, truncated, or persisted. Raw exception text, stack traces, SQL, credentials, tokens, cookies, URLs, paths, filesystem roots, names, email addresses, and evidence payloads never contribute bytes to `last_error_text`. An unknown handler failure maps to the fixed `task_handler_failed` message rather than preserving handler text.
- **Reason:** The trust boundary must not depend on every handler correctly recognizing or sanitizing PII, paths, URLs, credentials, SQL, or exception text. A closed reviewed map makes the persisted text deterministic and safe by construction.
- **Affected contract/class:** task-store retry/dead-letter validation; worker coordinator error mapping; artifact-store exception mapping.
- **Stable task failure codes:** `task_schema_unsupported`, `task_payload_invalid`, `task_type_unsupported`, `task_handler_failed`, `task_outcome_commit_failed`, `task_attempts_exhausted`.
- **Later change impact:** the fixed-message map, 512-byte new-writer ceiling, and code taxonomy are persisted operational contracts. Readers must remain backward-compatible with every already retained v1 row that satisfied the older 2,000-byte ceiling; those rows are read as retained history and are not rewritten or re-sanitized.

### 7. Task-state transition rules

- **Recommended value:**

| From | To | Guard/effect |
|---|---|---|
| enqueue | `pending` | Never claimed; `attempt_count = 0`; lease/error/completion fields clear. `available_at_gmt` may be immediate or scheduled. |
| `pending` or `retry` | `leased` | Available at `now`; short `SELECT ... FOR UPDATE`; set owner/new token/120-second lease; increment attempt count once, never above 5. |
| expired `leased` | `leased` | Reclaim transaction replaces owner and token. It increments attempt count when below 5. At attempt 5 it leaves the count at 5 and returns an exhausted disposition. |
| reclaimed `leased` at attempt 5 | `dead` | The new live owner immediately dead-letters with `task_attempts_exhausted` and invokes no handler. The dead-letter still requires the exact unexpired replacement lease. |
| live `leased` | `retry` | Retryable failure on attempts 1-4; apply Decision 3; clear lease and completion fields. |
| live `leased` | `completed` | Only after any authoritative outcome command committed or replayed; clear lease and set `completed_at_gmt`. |
| live `leased` | `dead` | Unsupported schema/type, invalid payload, non-retryable failure, or failure at attempt 5; clear lease. |

`completed` and `dead` are terminal. `pending` is only never-attempted work; `retry` is only previously attempted work. `dead` is valid at attempts 1-5, not only attempt 5. Every worker-owned heartbeat, retry, completion, or dead-letter update compares task ID, `task_state = leased`, owner, token, and `lease_until_gmt > now` and must change exactly one row.
- **Reason:** This resolves the frozen schema's `pending`/`retry` distinction and permits unknown-schema dead-lettering before the maximum attempt.
- **Affected contract/class:** `GHCA_ACD_WPDB_Archive_Task_Store` loaded-row validation and mutation methods; no table change.
- **Stable failure codes:** existing operation-specific fencing codes remain, with `task_retry_fence_failed` and `task_dead_letter_fence_failed` added. Exhausted work uses `task_attempts_exhausted` only after a new live lease has been established.
- **Later change impact:** task state and attempt semantics affect retained operational rows and require backward-readable state handling if changed later. They do not change lifecycle events.

The two claim transactions are separate and deterministic:

1. expired lease selection: `task_state = 'leased' AND lease_until_gmt <= now`, ordered by `available_at_gmt, task_row_id`, `LIMIT 1`, `FOR UPDATE`;
2. available selection: `task_state IN ('pending','retry') AND available_at_gmt <= now AND attempt_count < max_attempts`, ordered by `available_at_gmt, task_row_id`, `LIMIT 1`, `FOR UPDATE`.

Neither query uses `SKIP LOCKED` or a broad cross-state `OR`. Each transaction commits before the handler runs.

### 8. Unknown task-schema handling

- **Recommended value:** lease the selected row using only its queue envelope, then inspect `task_schema_version`. Any version other than 1 transitions that exact live lease to `dead` with `task_schema_unsupported`. Do not decode its payload, resolve a handler, invoke external work, or send a lifecycle command.
- **Reason:** Retained unknown work must become operably terminal without treating unrecognized bytes as instructions.
- **Affected contract/class:** task store adds a minimal claimed-row loader separate from strict v1 payload validation; worker coordinator performs the zero-handler disposition.
- **Stable failure codes:** `task_schema_unsupported`; malformed schema-1 payload uses `task_payload_invalid`.
- **Later change impact:** supporting a retained version later requires adding its decoder before deployment. Existing dead rows are not revived automatically.

### 9. Crash after outcome commit and before task completion

- **Recommended value:** the next valid claimant recomputes the same task outcome key, submits the same outcome command, receives the stored command response through receipt-first replay, and then completes its live task lease. It must not append a second event or create/overwrite a second artifact.
- **Reason:** The outcome command is the authoritative commit; task completion is only delivery bookkeeping.
- **Affected contract/class:** `GHCA_ACD_Archive_Unit_Of_Work` accepts an optional task-fence request and asserts the exact live lease inside the command transaction, including receipt replay; worker coordinator completes only after the committed/replayed response returns.
- **Stable failure codes:** `task_outcome_commit_failed`, `task_outcome_fence_failed`, and the existing command idempotency-conflict reasons.
- **Later change impact:** this is a retained command-idempotency contract and cannot change for retained tasks/receipts without a versioned reader.

### 10. Task-specific outcome idempotency

- **Recommended value:**

  `SHA-256("ghca-task-outcome-v1\n" + ghca-cjson-1({"logical_outcome":...,"task_id":...,"task_schema_version":1}))`

  The lowercase 64-hex result is the worker command's caller idempotency key. `logical_outcome` is a closed handler-owned machine code, not error text. Existing command scope and client-intent digests continue to apply. The helper accepts exactly the ordered constituents `logical_outcome`, `task_id`, and `task_schema_version`; extra, missing, reordered, malformed, or unsupported constituents fail with `task_outcome_idempotency_invalid` before hashing.

  The v1 golden vector is frozen independently of the production canonical encoder. Its exact 121 ASCII/UTF-8 bytes are the following escaped literal, where `\n` is one LF byte (`0x0a`), with no BOM, CR, or terminal newline:

  `ghca-task-outcome-v1\n{"logical_outcome":"completed","task_id":"0123456789abcdef0123456789abcdef","task_schema_version":1}`

  The fixed expected SHA-256 is:

  `8b46a186347750fff01afe7555072501c075e86a87bcf960a5bd7cf64fc75e0c`

The independent golden test must construct those literal bytes directly and hash them without calling `GHCA_ACD_Archive_Canonical_JSON` or the production outcome helper. A separate assertion compares the production helper with the fixed constant. The independent literal hash and production-helper result must both equal that constant on the Decision C2 required runtimes: PHP 8.3.30 and PHP 8.5.7.
- **Reason:** It binds response-loss replay to one task contract and one logical result without adding a table or changing the existing command receipt.
- **Affected contract/class:** worker coordinator key helper and existing command/UoW receipt path.
- **Stable failure code:** `task_outcome_idempotency_invalid` for invalid constituents; ordinary conflicting reuse remains `idempotency_conflict`.
- **Later change impact:** retained-data affecting. The domain prefix and document fields are immutable v1 inputs while any task/receipt using them is retained.

### 11. Private-storage key formats

- **Recommended value:**

  - staging: `staging/{tenant_id}/{stream_id}/{archive_id}/{artifact_id}/{staging_id}.part`
  - committed PDF: `committed/{tenant_id}/{stream_id}/{archive_id}/{artifact_id}.pdf`
  - committed ledger: `committed/{tenant_id}/{stream_id}/{archive_id}/{artifact_id}.json`

Every variable segment is exactly 32 lowercase hexadecimal characters. `staging_id` is freshly generated from 16 random bytes. Fixed directory names and extensions are the only non-ID segments.
- **Reason:** Keys are deterministic where immutability requires it, unique while staging, portable across Windows/Linux, and contain no PII or mutable display data.
- **Affected contract/class:** `GHCA_ACD_Archive_Artifact_Store`; `GHCA_ACD_Private_Archive_Artifact_Store`; existing descriptor `storage_key` contract.
- **Stable failure codes:** `artifact_key_invalid`, `artifact_staging_collision`, `artifact_commit_collision`.
- **Later change impact:** committed-key format is retained data and must remain readable. Staging format is operational only after its safety window expires.

### 12. Artifact byte ceilings

- **Recommended value:** certificate PDF 16,777,216 bytes (16 MiB); ledger JSON 8,388,608 bytes (8 MiB); packet PDF 67,108,864 bytes (64 MiB). Minimum accepted size is 1 byte before media validation.
- **Bounded-memory contract:** certificate and packet writing, read-back verification, and SHA-256 hashing use streams in fixed chunks no larger than 1 MiB. A 64 MiB packet must never be loaded fully into a PHP string or array. Ledger canonical validation may load the complete document because its approved hard ceiling is 8 MiB.
- **Reason:** These powers-of-two ceilings bound disk use, memory, and validation work while leaving packet headroom above one certificate and the approved 1 MiB snapshot ceiling. They are hard acceptance ceilings, not promised capacities.
- **Affected contract/class:** private artifact store kind policy and descriptor byte-count verification.
- **Stable failure code:** `artifact_size_exceeded`; mismatched read-back count uses `artifact_size_mismatch`.
- **Later change impact:** retained-data affecting if a ceiling is lowered because already retained bytes must remain readable/verifiable. Any change requires versioned validation policy.

### 13. Media validation

- **Recommended value:**
  - PDF: exact header `%PDF-1.0` through `%PDF-1.7` or `%PDF-2.0` at byte zero; final non-whitespace token `%%EOF`; a preceding final `startxref` with a decimal offset inside the file; the offset must point to `xref` or to an indirect object whose bounded opening bytes declare `/Type /XRef`.
  - Ledger: valid UTF-8 `ghca-cjson-1`; decode and re-encode must reproduce the exact bytes; top level must be a non-empty object with integer `schema_version = 1`.
  - All kinds: read-back byte count and SHA-256 must match the write result before commit.
- **Bounded-read contract:** PDF validation is seek-based. It reads only the 8-byte header, a tail window of at most 65,536 bytes for final non-whitespace `%%EOF` and the final `startxref`, and at most 8,192 opening bytes at the resolved xref offset to recognize `xref` or an indirect `/Type /XRef` object. Whole-file byte counting and hashing remain streamed in chunks no larger than 1 MiB. Certificate and packet validation must not read the entire PDF into memory. Ledger validation may load, decode, and re-encode its complete document only within the 8 MiB ceiling.
- **Reason:** This rejects empty, truncated, and obvious wrong-type bytes using the PHP standard library. Full semantic PDF rendering/parsing belongs to the later certificate/packet slice and is not introduced here.
- **Affected contract/class:** private artifact store validators; existing descriptor media types remain `application/pdf` and `application/json`.
- **Stable failure codes:** `artifact_media_invalid`, `artifact_size_mismatch`, `artifact_digest_mismatch`.
- **Later change impact:** media validation is a retained-byte compatibility contract; stricter future rules must continue to open previously accepted v1 artifacts or introduce a versioned policy.

No PDF dependency is recommended for P3A.

### 14. Permissions and Windows/Linux portability

- **Recommended value:** the injected storage root must already exist, be absolute, be a real directory, and not itself be a symlink. Production operations provision its ACL and backup policy. Store-created directories request `0700`; files request `0600`. On POSIX, failure to establish restrictive modes fails closed. On Windows, `chmod` is best effort and the pre-provisioned NTFS ACL is authoritative; containment, exclusive creation, hashing, and symlink checks remain mandatory.
- **Reason:** PHP permission bits do not model Windows ACLs. Requiring a provisioned root avoids the store inventing security policy outside its boundary.
- **Affected contract/class:** private artifact store constructor/health validation.
- **Stable failure codes:** `artifact_root_invalid`, `artifact_permissions_unsafe`, `artifact_directory_create_failed`.
- **Later change impact:** deployment policy only; artifact keys and bytes are unchanged.

### 15. Traversal, symlink, overwrite, collision, and public-root protection

- **Recommended value:** constructor receives the private root plus one or more injected public roots; no WordPress constant or environment variable is read by the store. Canonical private root must be outside and not equal to every public root, using case-insensitive comparison on Windows and case-sensitive comparison elsewhere. Reject absolute logical keys, drive/UNC prefixes, `..` anywhere, backslashes, empty/dot segments, mixed separators, controls, invalid IDs, and any existing symlink component. Re-resolve and containment-check the parent immediately before open/write/commit.

  Staging uses `fopen(..., 'x+b')`. Commit uses same-volume `link(staging, committed)` as an atomic fail-if-present publish. Successful creation of that committed hard link is the immutable byte-commit point. The subsequent `unlink(staging)` is cleanup only: its failure does not invalidate or roll back the committed object, and the remaining staging entry becomes a reportable orphan subject to Decisions 16-18.

  When the committed key already exists, the store never overwrites, replaces, truncates, renames over, or relinks it. It opens the committed leaf without following links, rechecks containment and regular-file identity, and verifies the exact expected media kind, byte count, SHA-256, and Decision 13 structural validity using bounded reads. An exact match is idempotent commit success and may be reused by a retry before descriptor insertion. A safe regular object whose bytes, digest, size, or structure differ fails with `artifact_immutable_mismatch`; an existing key that cannot be safely established as the expected regular immutable object fails with `artifact_commit_collision`. If hard-link commit is unavailable for a new key, fail closed; do not fall back to check-then-rename or overwrite-capable rename.
- **Reason:** `rename()` may replace an existing destination on supported platforms. The hard-link creation is the only no-overwrite publish point. Treating a verified pre-existing committed object as success safely recovers crashes after link creation, before staging cleanup, or after blob commit but before the descriptor/event transaction.
- **Affected contract/class:** private artifact store.
- **Stable failure codes:** `artifact_root_public`, `artifact_key_invalid`, `artifact_path_escape`, `artifact_symlink_rejected`, `artifact_write_failed`, `artifact_atomic_commit_unsupported`, `artifact_commit_collision`, `artifact_commit_failed`, `artifact_open_failed`, `artifact_immutable_mismatch`.
- **Later change impact:** path/key rules affect retained data and cannot be narrowed without preserving a reader for existing committed keys. The commit mechanism is operational if immutability and no-overwrite guarantees remain equivalent.

### 16. Orphan safety window

- **Recommended value:** 24 hours from filesystem modification time. Enumerate both `staging/` and `committed/` candidates. One reconciliation call scans at most 1,000 entries in stable `(mtime, logical_key)` order and returns at most 100 candidate results plus counts and a `truncated` flag.
- **Reason:** The window exceeds the lease/retry crash windows used by one invocation and tolerates clock/response delays. Scan/result bounds prevent an operator task from becoming an unbounded filesystem walk.
- **Affected contract/class:** `GHCA_ACD_Archive_Orphan_Reconciler`; artifact-store candidate enumeration.
- **Stable failure codes:** `orphan_scan_failed`; truncation is a result flag, not a failure.
- **Later change impact:** operational policy only. Previously reported candidates are always re-evaluated.

### 17. P3A orphan disposition

- **Recommended value:** report only. P3A may not delete, unlink, move, rename, or quarantine an orphan candidate.
- **Reason:** Retention/legal-hold policy is not approved. Report-only discovery proves safe classification without creating an irreversible or policy-bearing operation.
- **Affected contract/class:** orphan reconciler returns a bounded result; the artifact-store contract does not need a quarantine/delete method in P3A.
- **Stable failure code:** none for an unreferenced candidate. A committed candidate is returned as `orphan_unreferenced`; a staging candidate is returned as `orphan_staging_unreferenced` under Decision 18.
- **Later change impact:** operational only. Quarantine or deletion requires a new explicit owner decision and tests.

### 18. Referenced-object recheck

- **Recommended value:** immediately before classifying a candidate, query the immutable artifact repository by parsed `artifact_id` and strictly validate any retained descriptor.

  - Exact descriptor artifact ID plus exact retained `storage_key`: `referenced_protected`.
  - Committed candidate with the same artifact ID but a different retained storage key: abort the complete reconciliation call with `orphan_reference_binding_mismatch`; do not classify it as protected or unreferenced.
  - Staging candidate with no exact descriptor storage-key reference: report `orphan_staging_unreferenced`, even when a committed descriptor for the same artifact ID exists.
  - Committed candidate with no descriptor: report `orphan_unreferenced`.
  - Any database error or retained-descriptor schema, canonical, digest, identity, or storage-key integrity failure aborts the complete reconciliation call with `orphan_reference_recheck_failed`; the reconciler never guesses or returns a partial successful classification.
  - P3A remains report-only and performs no deletion, unlink, rename, move, replacement, or quarantine.
- **Reason:** A descriptor may commit after enumeration. The exact ID/key recheck closes that race without concealing a contradictory binding or treating a stale staging alias as authoritative.
- **Affected contract/class:** orphan reconciler plus existing `GHCA_ACD_WPDB_Archive_Artifact_Repository::find_descriptor()`; no broad delete/query surface is added.
- **Stable failure codes:** `orphan_reference_recheck_failed`, `orphan_reference_binding_mismatch`; result codes `referenced_protected`, `orphan_unreferenced`, `orphan_staging_unreferenced`.
- **Later change impact:** retained-data safety invariant; any later disposition implementation must preserve the immediate recheck.

### 19. Test-only storage root and cleanup boundary

- **Recommended value:** each test creates exactly one directory directly below `sys_get_temp_dir()` named `ghca_acd_archive_test_{32-lowercase-hex}` and injects it as the private root. Cleanup accepts only a canonical directory whose parent is exactly the canonical system temp directory and whose basename matches that pattern. It never follows symlinks; a symlink entry is removed only as an entry inside the validated root. Cleanup refuses every other target.
- **Reason:** Failure-injection tests need real filesystem behavior without any chance of cleaning the repository, uploads, home directory, storage root, or a path reached through a link.
- **Affected contract/class:** P3 test helper only; no production cleanup API.
- **Stable failure codes:** `test_storage_root_invalid`, `test_cleanup_boundary_violation`.
- **Later change impact:** test policy only.

### 20. Worker wake-up and runtime exclusions

- **Recommended value:** P3A adds no WordPress hook, plugin-entrypoint require, WP-Cron event, Action Scheduler integration, registered WP-CLI command, REST/admin controller, activation/migration wiring, feature flag, or runtime configuration loader. The coordinator and reconciler are constructed and called directly by isolated tests only.
- **Reason:** Durable task execution can be verified without choosing a wake-up mechanism or activating the archive module.
- **Affected contract/class:** no `GHCA_ACD_Archive_Worker_Runner` in P3A; no entrypoint modification.
- **Stable failure code:** none.
- **Later change impact:** operational integration only and requires a separately approved slice.

## Minimum implementation shape if approved

The proposed implementation reuses the existing queue, command, and descriptor paths:

1. Extend `GHCA_ACD_WPDB_Archive_Task_Store`; do not create another queue abstraction.
2. Add one `GHCA_ACD_Archive_Worker_Coordinator` with an injected map of `task_type => callable`. Only a present, approved map entry can run. No service container, worker runner, event bus, or production handler is added.
3. Add an optional task fence to worker-originated Unit-of-Work requests. The Unit of Work locks and validates the exact live lease before a new outcome append or a stored-response replay. A stale worker receives no successful outcome response.
4. Handlers run outside transactions and receive the claimed task plus a heartbeat callback. P3A handlers are test-only. The coordinator performs the final fenced command and task completion ordering.
5. Add `GHCA_ACD_Archive_Artifact_Store`, `GHCA_ACD_Private_Archive_Artifact_Store`, and one reason-coded artifact-store exception. The root is injected and no public URL is exposed.
6. Add `GHCA_ACD_Archive_Orphan_Reconciler` in report-only mode.

This shape deliberately does not add the Technical Design's later wake-up runner or any live capture/materialization/reset adapter. Those classes have no work to perform in P3A.

## Stable code catalog proposed for P3A

| Boundary | Codes |
|---|---|
| Task selection/claim | `task_reclaim_select_failed`, `task_available_select_failed`, `task_claim_update_failed`, `invalid_task_attempt` |
| Task fencing | `invalid_task_lease_window`, `task_lease_extension_exceeded`, `task_heartbeat_fence_failed`, `task_completion_fence_failed`, `task_retry_fence_failed`, `task_dead_letter_fence_failed`, `task_outcome_fence_failed`, `task_lease_lost` |
| Task execution | `task_schema_unsupported`, `task_payload_invalid`, `task_type_unsupported`, `task_handler_failed`, `task_outcome_commit_failed`, `task_outcome_idempotency_invalid`, `task_attempts_exhausted` |
| Artifact root/path | `artifact_root_invalid`, `artifact_root_public`, `artifact_permissions_unsafe`, `artifact_directory_create_failed`, `artifact_key_invalid`, `artifact_path_escape`, `artifact_symlink_rejected` |
| Artifact write/commit/read | `artifact_staging_collision`, `artifact_write_failed`, `artifact_size_exceeded`, `artifact_size_mismatch`, `artifact_digest_mismatch`, `artifact_media_invalid`, `artifact_atomic_commit_unsupported`, `artifact_commit_collision`, `artifact_commit_failed`, `artifact_open_failed`, `artifact_immutable_mismatch` |
| Orphan discovery | `orphan_scan_failed`, `orphan_reference_recheck_failed`, `orphan_reference_binding_mismatch`; result codes `orphan_unreferenced`, `orphan_staging_unreferenced`, `referenced_protected` |
| Test containment | `test_storage_root_invalid`, `test_cleanup_boundary_violation` |

Existing P1/P2 persistence and command reason codes remain unchanged unless this record explicitly adds a more specific operation code.

## Minimum named regression requirements if approved

| Decision | Named executable evidence required |
|---|---|
| Fixed persisted failure text | `WORKER-FAILURE-FIXED-MESSAGE`, `WORKER-FAILURE-HANDLER-TEXT-REJECTED`, `TASK-RETAINED-ERROR-TEXT-2000-READABLE` |
| Frozen task-outcome digest | `TASK-OUTCOME-GOLDEN-LITERAL-BYTES`, `TASK-OUTCOME-GOLDEN-FIXED-SHA256`, `TASK-OUTCOME-PRODUCTION-HELPER`, `TASK-OUTCOME-CROSS-RUNTIME`, `TASK-OUTCOME-EXTRA-CONSTITUENT`, `TASK-OUTCOME-MISSING-CONSTITUENT`, `TASK-OUTCOME-REORDERED-CONSTITUENTS`, `TASK-OUTCOME-MALFORMED-CONSTITUENT`, `TASK-OUTCOME-UNSUPPORTED-CONSTITUENT` |
| Bounded artifact memory | `ARTIFACT-CERTIFICATE-STREAMED`, `ARTIFACT-PACKET-64M-BOUNDED-MEMORY`, `ARTIFACT-PDF-BOUNDED-HEADER-TAIL-XREF`, `ARTIFACT-LEDGER-8M-CANONICAL` |
| Idempotent immutable commit | `ARTIFACT-COMMIT-CRASH-AFTER-LINK`, `ARTIFACT-COMMIT-CRASH-BEFORE-STAGING-UNLINK`, `ARTIFACT-COMMIT-CRASH-BEFORE-DESCRIPTOR-TRANSACTION`, `ARTIFACT-COMMIT-EXISTING-EXACT-REUSED`, `ARTIFACT-COMMIT-EXISTING-MISMATCH`, `ARTIFACT-COMMIT-NEVER-OVERWRITES` |
| Reference classification | `ORPHAN-EXACT-REFERENCE-PROTECTED`, `ORPHAN-COMMITTED-REFERENCE-BINDING-MISMATCH`, `ORPHAN-STAGING-SAME-ID-DIFFERENT-KEY-UNREFERENCED`, `ORPHAN-DATABASE-FAILURE-ABORTS`, `ORPHAN-RETAINED-DESCRIPTOR-INTEGRITY-ABORTS`, `ORPHAN-REPORT-ONLY-NO-MUTATION` |

Each negative test must assert the exact exception class or result type, exact stable code, unchanged committed bytes, and no forbidden filesystem mutation. The outcome-digest vector must run independently and through the production helper on PHP 8.3.30 and PHP 8.5.7.

## Explicit non-authorization

Approval of this record would authorize only the Phase 2 P3A implementation described by the owner handoff. It would still not authorize LearnDash capture, certificate acquisition, ledger/packet business rendering, PDF generation, controllers, downloads, WordPress scheduling, WP-CLI registration, reset execution/reconciliation, projection rebuild execution, source-drift detection, schema changes, current-site database access, activation, staging, commit, push, deployment, or pull request creation.

## Owner decision requested

Please respond exactly “Approved as written” or identify the numbered decisions to revise. Production implementation must not begin without that explicit approval.
