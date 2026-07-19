# Slice 1B-P3B Runtime and Handler Decisions Proposal

Status: **owner decision requested; proposal only**

Starting commit: `29766499cee1dca30f054f566b37f0f53c97da34`

Proposal branch: `feature/dual-layer-archive-slice-1b-p3b-runtime-decisions`

Distributed PHP minimum: PHP 8.3

Task schema: version 1

Canonical JSON: `ghca-cjson-1`

## 0. Purpose and authority

This record proposes the smallest independently reviewable implementation after accepted P3A. It does not authorize production code, runtime composition, WordPress hooks, current-site reads, network access, PDF rendering, activation, or deployment.

The event stream remains the lifecycle authority. Immutable snapshots, artifact descriptors, ledger items, and committed private objects remain evidence authorities within their accepted roles. Durable tasks are operational instructions only: their expiry, retry, exhaustion, or death never implies a lifecycle event. Every lifecycle mutation continues through the accepted aggregate, command, receipt-first Unit of Work, and task fence.

Where the earlier PRD or 2026-07-10 plan describes mutable status rows, public/uploads-based files, browser-driven PDF generation, or a universal LearnDash hook, the later event-sourcing design and accepted P1-P3A contracts prevail.

## 1. Current-state inventory

### 1.1 What P3A provides

| Area | Accepted contract |
|---|---|
| Durable task repository | `GHCA_ACD_WPDB_Archive_Task_Store`, task schema version 1, maximum five attempts, deterministic single-row claim and separate expired-lease reclaim transactions. |
| Leasing and fencing | 120-second lease, 30-second heartbeat cadence, live-lease comparisons on task ID, leased state, owner, token, and unexpired lease. A stale worker cannot heartbeat, commit an outcome, complete, retry, or dead-letter. |
| Retry policy | Fixed deterministic delays of 60, 300, 900, and 3,600 seconds after attempts 1-4. Attempt 5 is terminal. No random jitter. |
| Worker boundary | `GHCA_ACD_Archive_Worker_Coordinator::run_once()` claims at most one task, commits the claim before work, calls an injected handler outside a database transaction, then fences the authoritative outcome and completes the task. |
| Handler injection | A closed associative map from retained task-type string to callable. No service container, event bus, scheduler, hook, or production handler exists. |
| Handler outcome | Exact two-key value: `logical_outcome = completed` and `outcome = {result_code: committed}`. The inner canonical document is exactly 27 bytes. Unknown, nested, cyclic, oversized, or free-form results are rejected. |
| Outcome idempotency | `GHCA_ACD_Archive_Digester::task_outcome()` derives the command key from task ID, task schema version, and the frozen logical outcome `completed`. Receipt replay handles response loss. |
| Unit of Work fence | The optional task fence is checked while committing the command transaction. Receipt-first idempotency and immutable event/side-record writes are already accepted. |
| Private artifact storage | Injected private root and public-root exclusion list; immutable staging/commit/open; byte-count, SHA-256, canonical-ledger/PDF validation; size ceilings of 16 MiB certificate, 8 MiB ledger, and 64 MiB packet; no URL and no uploads fallback. |
| Orphan discovery | Directly callable, bounded, report-only reconciliation. The full continuation cursor is canonicalized and authenticated with HMAC-SHA-256 using an injected 64-lowercase-hex key. The first-call cutoff is frozen. |
| Compatibility | Distributed floor PHP 8.3; required PHP 8.3.30 and 8.5.7 verification. PHP 8.4 is optional and unavailable until a real CLI is present. |

P3A also retains these task states: `pending`, `leased`, `retry`, `completed`, and `dead`. Build projection states are `REQUESTED`, `CAPTURING`, `MATERIALIZING`, `VERIFYING`, `FINALIZED`, `FAILED`, and `CANCELLED`. The two state machines are intentionally independent.

### 1.2 Existing immutable relationships

1. `ArchiveRequested` or `ReplacementArchiveRequested` enqueues `capture_evidence`.
2. `EvidenceSnapshotCaptured` seals the snapshot relationship and enqueues `materialize_ledger` and `materialize_packet` for the same archive, snapshot, and build attempt.
3. The second matching materialization event enqueues `verify_and_finalize`.
4. `LedgerMaterialized` binds its descriptor, manifest, and ledger items to the archive, snapshot digest, and build attempt.
5. `PacketMaterialized` binds its descriptor to the same archive, snapshot digest, and build attempt.
6. `ArchiveVerified` and `ArchiveFinalized` are committed atomically by the accepted `VerifyAndFinalize` aggregate command.
7. `ArchiveFailed` and `SourceDriftDetected` are explicit decisions; they are never inferred from task state.

### 1.3 Deliberately absent from P3A

P3A has no production handler, task-specific payload validator, build coordinator, evidence-source adapter, LearnDash reader, certificate acquisition strategy, ledger materializer, packet/PDF renderer, final integrity verifier, runtime composition root, secret loader, wake-up adapter, worker health registry, current-site access, feature activation, or production hook.

The task store currently recognizes the broader design catalog (`capture_evidence`, `materialize_ledger`, `materialize_packet`, `verify_and_finalize`, reset tasks, integrity/orphan tasks, and projection rebuild), but recognition is not authorization to dispatch. Its retained-v1 validator checks the generic envelope and row bindings, not exact per-task fields.

### 1.4 Unresolved runtime contracts

The following cannot be inferred from P3A and must be decided before the corresponding implementation:

- exact task-specific v1 payloads other than the P3B1 ledger contract, including their future stable build/output identities;
- the lifecycle contract for a materializing retry after only one candidate layer committed: the aggregate rejects a second ledger, finalization currently requires both layer descriptors/events to share one build attempt, and the design permits verified candidate reuse;
- how a data-rich handler hands bounded prepared facts to the Unit of Work without expanding the persisted task outcome;
- permanent business-failure versus retryable operational-failure command behavior;
- evidence-source interface, consistent-read query set, ceilings, and source fingerprint;
- certificate generation/acquisition, authentication, and SSRF boundary;
- packet renderer ownership, version, licence, fonts/assets, and reproducibility;
- verification-time source-fingerprint adapter and integrity checks;
- deploy-time provisioning for the cursor key, private root, public roots, and any later credentials;
- production wake-up mechanism, operational recovery mechanism, and multisite fan-out;
- worker-health persistence and exposure;
- handlers for reset, integrity, orphan-disposition, and rebuild task types;
- controllers, download authorization, retention, activation, and rollout.

### 1.5 Frozen operating envelope

P3B carries forward the accepted assumptions rather than reopening them: approximately 100 reads per lifecycle write; validation at 100 reads/second and 5 lifecycle writes/second per site; at most five artifact workers per site; one operational tenant per WordPress site; employment/training PII only with PHI and PCI prohibited; 99.9% monthly lifecycle API availability; lifecycle-command p99 at most 1,000 ms excluding asynchronous artifact work; zero primary RPO for acknowledged event commits; disaster-recovery RPO at most 15 minutes; and RTO at most four hours.

## 2. Recommended P3B subdivision

### P3B1 — one complete dark-mode ledger vertical slice

Freeze only the `materialize_ledger` payload and its prepared-result/coordinator contract, then implement that one handler. P3B1 would add the one-type task catalog, amend trusted ledger-task production so only `EvidenceSnapshotCaptured` produces this installed task, add a build coordinator that delegates to existing aggregate commands, and implement a deterministic snapshot-only ledger handler. Tests use disposable databases, isolated private roots, and fakes; no current-site reads, network, renderer, or runtime hook is involved.

This is the smallest slice that proves a real production handler end to end: task validation, lease fencing, external filesystem work outside transactions, immutable artifact/ledger persistence, event append, receipt replay, crash recovery, and terminal task completion.

### P3B2a — evidence capture and certificate source

Implement the bounded evidence read session and `capture_evidence` only after the owner approves the evidence query/fingerprint specification and one certificate strategy. Current-site use remains separately gated; acceptance tests first use fixtures/disposable data.

### P3B2b — packet materialization

Select and pin an owned PDF toolchain, licence position, fonts/assets, resource ceilings, and reproducibility policy; then implement `materialize_packet` from sealed inputs only.

### P3B2c — verification and finalization

Implement `verify_and_finalize` after both materializers and the verification-time source-fingerprint adapter exist. This slice reopens committed artifacts, validates all immutable bindings, and invokes the accepted atomic command.

### P3B3 — composition and wake-up

Compose only handlers that have passed their dark-mode matrices and register one primary wake-up mechanism. Do not claim task types for which no production handler is installed.

### Later slices

Download controllers, admin UI, reset execution/reconciliation, integrity tooling, projection rebuild, destructive orphan disposition, retention, activation, and deployment remain later work.

## 3. Closed production task catalog

### 3.1 P3B1 payload scope

P3B1 freezes exactly one task-specific payload: `materialize_ledger` version 1. It does not change or freeze the task-specific payloads for `capture_evidence`, `materialize_packet`, or `verify_and_finalize`; each remains for its approved implementation slice. The generic P3A envelope validation and unknown-schema dead-letter behavior remain unchanged.

The ledger payload is one canonical JSON object with these exact keys and no others:

```json
{"archive_id":"<32-lower-hex>","build_attempt_id":"<32-lower-hex>","canonical_format_version":"ghca-cjson-1","ledger_artifact_id":"<32-lower-hex>","snapshot_id":"<32-lower-hex>","stream_id":"<32-lower-hex>","task_schema_version":1,"task_type":"materialize_ledger","trigger_event_id":"<32-lower-hex>"}
```

Rules:

- maximum 512 canonical bytes and object depth 1;
- every ID is exactly 32 lowercase hexadecimal characters;
- `task_schema_version` is integer `1`; all other values are strings;
- arrays, nested values, nulls, unknown keys, aliases, free-form text, paths, URLs, credentials, digests, and materialized bytes are forbidden;
- task row and payload must agree on task type, schema version, trigger event ID, stream ID, archive ID, build-attempt ID, and snapshot ID;
- the trigger event must be an authoritative `EvidenceSnapshotCaptured` for the same stream/archive/snapshot/build attempt; `ArchiveRetryRequested` is not an approved P3B1 ledger trigger;
- `ledger_artifact_id` is generated once by the existing injected Unit-of-Work ID generator when this task is enqueued, and is retained in both payload and task-dedupe input; it is never generated by the handler or on retry delivery.

This restriction is lifecycle-specific. It does not remove operational retry delivery, receipt replay, expired-lease reclaim, or attempt-five recovery for the original `EvidenceSnapshotCaptured` ledger task. P3B1 must not enqueue an installed `materialize_ledger` task from `ArchiveRetryRequested`; lifecycle materialization retry remains unsupported pending Decision D16.

No table, lifecycle-event, canonical-JSON, or digest change is required. Because the feature is unactivated, no retained production ledger task requires migration.

### 3.2 `materialize_ledger`

| Contract | Ruling |
|---|---|
| Exact fields | The nine fields in section 3.1 only. No packet ID, caller-supplied digest, materialized bytes, path, URL, or personal data is allowed. |
| Trusted producer | Unit of Work after authoritative `EvidenceSnapshotCaptured` only. `ArchiveRetryRequested` must not produce an installed P3B1 ledger task. |
| Handler responsibility | Load and revalidate the sealed snapshot; derive `ledger-document-v1` and its items from that snapshot only; stage, read back, validate, and atomically commit the ledger object; return the bounded prepared ledger result to the worker coordinator. |
| External effects | Private local filesystem only, outside database transactions. No current-site read and no network. |
| Immutable output | Ledger object, artifact descriptor, ledger-item rows, and `LedgerMaterialized`, all bound to archive, snapshot, snapshot digest, build attempt, and preallocated artifact ID. |
| Success outcome | The coordinator synthesizes the exact P3A completed/committed outcome only after `RecordMaterializedArtifact` commits or its receipt is replayed. The handler never returns a lifecycle outcome. |
| Retryable failures | `task_handler_failed` for transient filesystem/resource failures, including `artifact_open_failed` while validating an authoritative committed object; `task_outcome_commit_failed` for transient authoritative commit failure. Existing artifact reason remains available inside the exception chain but is never copied into task text. |
| Permanent failures | Explicit `ArchiveFailed` before completion for `archive_build_binding_invalid`, `archive_snapshot_invalid`, `archive_ledger_invalid`, or a positively proven `archive_immutable_conflict` under section 4.3.1. Inability to open or contain a path is not proof of conflict. |
| Operational-blocked failures | Derived-key containment, non-committed symlink, configuration, and unknown/unclassified failures use sanitized operational disposition only and append no `ArchiveFailed`. Malformed handler-supplied descriptor/storage keys are rejected as `task_prepared_result_invalid` before any Build Coordinator/UoW call. |
| Duplicate delivery | The stable `ledger_artifact_id`, exact logical key, deterministic bytes, immutable descriptor/manifest checks, and task-derived receipt must replay. Existing different bytes or rows are an immutable conflict, never overwritten. |

This is the only production handler recommended for P3B1.

### 3.3 Deferred build-task payloads

The intended strings `capture_evidence`, `materialize_packet`, and `verify_and_finalize` remain recognized by the accepted task store, but P3B1 does not approve their exact task-specific fields, producer changes, artifact-ID strategy, handlers, or dispatch. Their current retained envelopes remain untouched. Reset, integrity, orphan, and rebuild task strings are likewise outside P3B1. No new task type is proposed.

### 3.4 Installed-handler claim filtering

P3B1 authorizes the existing task store's available-task claim and expired-lease reclaim paths to accept the same closed, non-empty installed-type allowlist. For P3B1 that list is exactly `materialize_ledger`.

Both deterministic `SELECT ... FOR UPDATE` queries and their guarded `UPDATE` statements must include the installed-type predicate. The store must validate, deduplicate, and sort the allowlist before constructing portable prepared placeholders. An unsupported, empty, malformed, or non-string entry fails before a transaction. Ordering among eligible rows remains `available_at_gmt, task_row_id`; attempt and lease behavior remain unchanged.

An earlier available or expired `capture_evidence`/`materialize_packet` row must remain byte-for-byte operationally untouched while the next eligible ledger row is claimed or reclaimed. Filtering after selection is forbidden because it can starve installed work or mutate an uninstalled task.

## 4. Handler outcome grammar

### 4.1 Preserve the accepted result

`result_code = committed` remains sufficient as the coordinator-synthesized worker outcome. It means the authoritative lifecycle command committed or its stored command receipt was replayed; it does not mean every build ended successfully. An explicitly committed `ArchiveFailed` is also an authoritatively committed task decision.

This is **worker-outcome-v1**, bound to task schema version 1; an outcome-version field is forbidden. It is not returned or controlled by the handler. A future change requires a new task/outcome contract rather than an optional field.

After authoritative commit or receipt replay, the coordinator synthesizes exactly:

```json
{"logical_outcome":"completed","outcome":{"result_code":"committed"}}
```

The inner outcome remains canonical `{"result_code":"committed"}`: one level, one value, exactly 27 bytes, fixed 11-byte key, fixed 9-byte value. The complete outer canonical value is exactly 69 bytes, contains two object levels and two scalar leaf values, and has no extension point. Thus 69 bytes and two object levels are both the exact value and the maximum. No ID, digest, record reference, bytes, personal data, path, SQL, stack trace, URL, credential, or free-form reason is returned or persisted as task outcome.

### 4.2 Prepared ledger handoff

The P3B1 handler contract is exactly:

```text
handler(authoritative_task, heartbeat) -> prepared_ledger_result
```

The handler performs snapshot-only materialization and private-storage work outside a database transaction. It returns one in-memory value with exact keys:

```text
prepared_ledger_result = {
  artifact_descriptor: <exact 13-field ledger descriptor>,
  ledger_items: <ordered list of exact ledger-item-v1 documents>
}
```

No other key, callback, lifecycle result, command response, receipt, exception text, absolute filesystem path, credential, or free-form value is allowed. The descriptor's validated relative `storage_key` is the only locator. The value is bounded by the descriptor field limits, at most 10,000 items, and an exact derived `ledger-document-v1` no larger than 8 MiB. It is never persisted, logged, or used as a task outcome.

The worker coordinator must, in this order:

1. receive the prepared result from `handler(task, heartbeat)`;
2. validate its exact shape, descriptor, item schemas/order, task/snapshot/build/artifact bindings, literal producer contract, derived ledger bytes, item/content/manifest digests, storage key, and committed object read-back;
3. reject any invalid prepared result with `task_prepared_result_invalid` before any Build Coordinator or Unit-of-Work call;
4. recheck the live lease and construct the task fence;
5. derive the existing frozen `completed` task-outcome idempotency key;
6. invoke the fenced Build Coordinator, which invokes the existing receipt-first Unit of Work;
7. validate/replay the authoritative receipt and event decision; and
8. synthesize the fixed 69-byte completed/committed outcome locally, then perform the fenced task disposition.

No handler-controlled value is validated, interpreted, or consulted after the lifecycle command commits. This removes the unsafe commit-then-handler-validation window: after commit/replay, only authoritative receipt/history and coordinator constants control the response and task disposition.

### 4.3 Failure grammar

Operational task rows retain the accepted codes/messages and add only the closed permanent codes needed for terminal attribution:

| Stable code | Fixed safe message | Use |
|---|---|---|
| `task_schema_unsupported` | `The retained task schema version is not supported.` | Dead before handler. |
| `task_payload_invalid` | `The retained task payload is invalid.` | Dead before handler. |
| `task_type_unsupported` | `The retained task type is not supported.` | Dead before handler. |
| `task_handler_failed` | `The task handler failed.` | Retryable operational handler failure. |
| `task_prepared_result_invalid` | `The prepared ledger result is invalid.` | Invalid handler result; rejected before any UoW call. |
| `task_outcome_commit_failed` | `The authoritative task outcome could not be committed.` | Retryable authoritative commit failure. |
| `task_attempts_exhausted` | `The durable task exhausted its maximum attempts.` | Dead only after explicit lifecycle exhaustion commit/replay. |
| `task_lease_lost` | `The exact live task lease no longer owns this task.` | Runner response; stale worker performs no disposition. |
| `archive_build_binding_invalid` | `The archive build bindings are invalid.` | Permanent event code and dead-task code. |
| `archive_evidence_incomplete` | `The archive evidence is incomplete.` | Permanent event code and dead-task code. |
| `archive_certificate_invalid` | `The archive certificate is invalid.` | Permanent event code and dead-task code. |
| `archive_source_drift` | `The archive evidence source changed.` | Drift/failure event code and dead-task code. |
| `archive_snapshot_invalid` | `The archive snapshot is invalid.` | Permanent event code and dead-task code. |
| `archive_ledger_invalid` | `The archive ledger is invalid.` | Permanent event code and dead-task code. |
| `archive_packet_invalid` | `The archive packet is invalid.` | Permanent event code and dead-task code. |
| `archive_verification_failed` | `The archive verification failed.` | Permanent event code and dead-task code. |
| `archive_immutable_conflict` | `The retained archive evidence conflicts with the requested outcome.` | Permanent event code and dead-task code. |
| `archive_build_attempts_exhausted` | `The archive build exhausted its approved attempts.` | Lifecycle failure code only; task uses `task_attempts_exhausted`. |

Raw exception text is never copied. Before attempt 5, a retryable operational failure changes only the task. Permanent failures retain their exact closed lifecycle code and still require an explicit fenced `FailArchive` commit/replay before dead-lettering. Attempt 5 follows the recovery algorithm below; exhaustion alone is never evidence of lifecycle failure.

#### 4.3.1 Closed P3B1 failure classification

Classification uses both the concrete reason and its call context/category. It is not a string-prefix heuristic. A permanent mapping submits `FailArchive` only through section 4.5; a retryable operational mapping changes only task delivery before attempt 5 and is eligible for `archive_build_attempts_exhausted` only after final deterministic recovery fails; an operational-blocked mapping never invents a lifecycle failure.

| Reachable P3B1 source reasons | Exact classification |
|---|---|
| Snapshot task ID or authoritative task/snapshot/build mismatch, including `snapshot_id_invalid` or a missing exact snapshot | Permanent `archive_build_binding_invalid` when the task identity is contradictory; otherwise permanent `archive_snapshot_invalid` when the bound snapshot is absent. |
| `unsupported_snapshot_schema_version`, `unsupported_snapshot_canonical_version`, `snapshot_canonical_invalid`, `snapshot_schema_invalid`, `snapshot_retained_binding_mismatch` | Permanent `archive_snapshot_invalid`. |
| `snapshot_lookup_failed` | Retryable operational `task_handler_failed`; only the attempt-five path may later translate the still-failing operation to `archive_build_attempts_exhausted`. |
| Artifact-repository `CATEGORY_INVALID_COMMAND`: `artifact_binding_invalid`, `artifact_identity_invalid`, `ledger_binding_invalid`, `ledger_snapshot_binding_mismatch` | Permanent `archive_build_binding_invalid`. |
| Artifact-repository `CATEGORY_INVALID_COMMAND`: `artifact_descriptor_invalid`, `artifact_digest_invalid`, `artifact_role_type_invalid`, `unsupported_artifact_schema_version`, `artifact_storage_key_invalid`, `artifact_filename_invalid`, `side_ledger_item_count_exceeded`, `ledger_item_count_mismatch`, `ledger_item_schema_invalid`, `ledger_duplicate`, `ledger_gap`, `unsupported_ledger_item_schema_version`, `ledger_item_canonical_invalid`, `ledger_manifest_digest_mismatch` | Permanent `archive_ledger_invalid`. |
| Any artifact-repository `CATEGORY_INTEGRITY_BLOCKED`, including `artifact_contradictory_duplicate`, `artifact_retained_binding_mismatch`, `artifact_authoritative_binding_mismatch`, retained variants of the preceding validation reasons, `ledger_item_digest_mismatch`, retained `ledger_duplicate`, or retained `ledger_gap` | Permanent `archive_immutable_conflict`. Category wins when one reason can arise from both submitted and retained data. |
| `artifact_insert_failed`, `artifact_lookup_failed`, `artifact_duplicate_lookup_failed`, `ledger_item_insert_failed`, `ledger_item_load_failed` | Retryable operational `task_outcome_commit_failed` when raised inside the fenced UoW; otherwise retryable operational `task_handler_failed`. |
| Malformed handler-supplied descriptor or storage key | Reject as `task_prepared_result_invalid` before any Build Coordinator/UoW call; exact lifecycle-event count zero. The malformed value must never reach the private store or repository. |
| Private-store `artifact_key_invalid` for a key derived only from already validated task/snapshot IDs, or any infrastructure `artifact_path_escape` | Operational-blocked `task_handler_failed`; append no `ArchiveFailed`. A derived containment failure is not `archive_build_binding_invalid`. |
| Private-store `artifact_size_exceeded`, `artifact_media_invalid`, `artifact_size_mismatch`, or `artifact_digest_mismatch` while validating newly prepared/staged ledger bytes | Permanent `archive_ledger_invalid`. |
| Private-store `artifact_size_mismatch` or `artifact_digest_mismatch` while revalidating an object already claimed by an authoritative ledger descriptor/event | Positive retained-byte mismatch: permanent `archive_immutable_conflict`, submitted only through section 4.5. |
| Private-store `artifact_commit_collision` at the occupied committed key, or `artifact_immutable_mismatch` | Positive committed-identity conflict: permanent `archive_immutable_conflict`, submitted only through section 4.5. |
| Private-store `artifact_symlink_rejected` specifically at an already occupied committed artifact identity | Positive committed-identity conflict: permanent `archive_immutable_conflict`, submitted only through section 4.5. |
| Private-store `artifact_symlink_rejected` at a staging path, parent component, or other infrastructure path | Operational-blocked `task_handler_failed`; append no `ArchiveFailed`. |
| Private-store `artifact_open_failed` during new staging/I/O or authoritative committed-object validation | Retryable operational `task_handler_failed`; missing files, temporary sharing locks, permissions, open/read/seek failures, and similar access failures prove no immutable contradiction. Only a still-failing attempt-five deterministic recovery may translate it to `archive_build_attempts_exhausted`. |
| Private-store `artifact_directory_create_failed`, `artifact_write_failed`, `artifact_staging_collision`, or `artifact_commit_failed` | Retryable operational `task_handler_failed`; only the attempt-five path may later translate the still-failing operation to `archive_build_attempts_exhausted`. |
| Private-store `artifact_atomic_commit_unsupported` or `artifact_permissions_unsafe` | Known operational-blocked `task_handler_failed`; fail closed and retry/dead-letter operationally under P3A attempt rules, but append no `ArchiveFailed`. Environment/configuration repair is required. |

`artifact_root_invalid`, `artifact_root_public`, and `orphan_cursor_key_invalid` are constructor/provisioning failures before a P3B1 task can be claimed. `orphan_cursor_invalid`, `orphan_cursor_stale`, and `orphan_scan_failed` belong to the separate report-only reconciler and are not reachable from ledger handling. They therefore cannot become ledger lifecycle failures. Any unlisted reason, uncategorized `Throwable`, contradictory receipt/history pair, or failure whose operation context cannot be proven is operational-blocked: sanitize to `task_handler_failed` or `task_outcome_commit_failed`, fail closed, and never submit `FailArchive` merely to obtain a terminal task state. Prefix matching and raw exception-text inspection are forbidden.

### 4.4 Exact attempt-five recovery algorithm

This algorithm applies whenever the claimed row has `attempt_count = 5`, including reclaim of an expired fifth lease:

1. Revalidate the retained task schema/payload and its authoritative `EvidenceSnapshotCaptured` trigger, stream, case, revision, archive, build attempt, snapshot, and preallocated ledger-artifact bindings. Invalid/stale leases stop with no lifecycle or task disposition.
2. Derive the frozen task-outcome key, then derive the distinct canonical `RecordMaterializedArtifact` and `FailArchive` idempotency scopes/dedupe identities. Reconstruct authoritative history under the live fence before invoking the handler or writing a new object.
3. If a matching `LedgerMaterialized` decision already committed, validate its snapshot, descriptor, items, manifest, committed object, and corresponding `RecordMaterializedArtifact` receipt/history; synthesize completed/committed and complete the task. This is the required response-loss path.
4. If a matching `ArchiveFailed` decision already committed, validate its exact task/archive/build/snapshot binding and corresponding `FailArchive` receipt/history; dead-letter exactly once with that event's preserved permanent code or `task_attempts_exhausted` when the event code is `archive_build_attempts_exhausted`. Do not run the handler again.
5. If neither matching outcome exists, deterministically reconstruct the expected ledger bytes, item digests, manifest digest, descriptor, committed key, and event payload from the retained task and snapshot. If the committed key already contains exact matching immutable bytes, reuse it and continue with the prepared result. If it is absent, the handler may stage and commit those exact bytes. Only positive committed collision/mismatch evidence remains `archive_immutable_conflict`; `artifact_open_failed`, derived containment failure, or non-committed symlink rejection remains operational under section 4.3.1.
6. Validate the complete prepared result before any Build Coordinator/UoW call, recheck the live fence, and invoke the Build Coordinator/UoW for `RecordMaterializedArtifact` using its own command type/scope and the frozen task-outcome key.
7. If `RecordMaterializedArtifact` commits or its receipt replays, synthesize completed/committed and complete the task. A crash after command commit but before task completion returns to steps 2-3 on reclaim and must never append `ArchiveFailed`.
8. If deterministic immutable recovery or the final materialization command fails, retain the exact classified cause from section 4.3.1 and recheck both distinct authoritative outcomes before constructing any failure command. If a matching materialization or failure appeared, follow step 3 or 4.
9. If the remaining cause is one of the closed permanent mappings, submit `FailArchive` with that exact permanent code. If it is retryable operational after final deterministic recovery, submit `FailArchive` with `archive_build_attempts_exhausted`. If it is operational-blocked, unknown, lease loss, response loss, task death, or unclassified, append no lifecycle failure; on this fifth attempt, dead-letter only under the sanitized operational task code allowed by P3A.
10. Every `FailArchive` submission follows the conflict/recheck algorithm in section 4.5. After its commit/replay, dead-letter exactly once according to its authoritative code. A crash after failure commit but before dead-lettering returns to steps 2 and 4.

At no point may response loss after successful materialization, lease expiry, worker death, task death, or a proven immutable conflict be translated into `archive_build_attempts_exhausted`. A retryable `artifact_open_failed` may become exhaustion only after attempt-five deterministic recovery still fails. Operational-blocked path, containment, or non-committed symlink failures dead-letter/report only the sanitized operational code and append no lifecycle event. If neither materialization nor an approved failure decision can be authoritatively committed under the live fence, the worker reports a sanitized operational failure and claims no lifecycle success.

### 4.5 Cross-command idempotency and failure-submission race

The exact commands are `RecordMaterializedArtifact` and `FailArchive`. Both may use the same frozen task-outcome key as an input, but `command_type` is part of the canonical idempotency scope/client intent. Their scope digests and final dedupe identities are therefore distinct. A `FailArchive` lookup/submission can never directly replay a `RecordMaterializedArtifact` receipt, or vice versa.

Every permanent/exhaustion failure submission uses this exact algorithm:

1. Reconstruct the aggregate and authoritative history under the live task fence.
2. If a matching `LedgerMaterialized` exists, validate it and complete through its `RecordMaterializedArtifact` receipt/history.
3. If a matching `ArchiveFailed` exists, validate it and dead-letter through its `FailArchive` receipt/history.
4. Otherwise construct `FailArchive` using the exact observed stream sequence and head digest.
5. If the failure UoW encounters an expected-sequence or expected-head-digest stream conflict, do not retry that stale `FailArchive` blindly.
6. Reload authoritative history under the still-live fence.
7. Prefer a newly committed matching `LedgerMaterialized`; validate it and complete without submitting failure.
8. Otherwise replay a newly committed matching `ArchiveFailed` and dead-letter according to its exact code.
9. Submit a newly reconstructed failure only if refreshed history still contains neither outcome. It must use the refreshed exact sequence/head digest; if this new submission also conflicts or the fence is lost, stop operationally and append no lifecycle event.

This ordering prevents a stale failure decision from following a successful materialization. A matching authoritative success always wins the recheck; no execution may produce `LedgerMaterialized` and then append `ArchiveFailed` for the same task outcome.

### 4.6 Unresolved partial-artifact lifecycle retry gate

P3B1 deliberately does not infer how a later `ArchiveRetryRequested` should resume materialization after one candidate layer committed:

- `GHCA_ACD_Archive_Case` rejects a second `LedgerMaterialized` for the revision as `duplicate_ledger`;
- the accepted P2 finalization path requires the ledger descriptor/event and packet descriptor/event to share the same `build_attempt_id`; and
- the technical design permits reuse of a candidate only after descriptor, byte, digest, media, and snapshot-binding verification.

Those rules do not determine a safe transition by themselves. A separate owner decision must select one of these mutually exclusive lifecycle models:

1. cross-attempt candidate reuse with revised finalization rules;
2. a new explicit candidate-reuse/rebinding lifecycle event;
3. rematerialization with an approved invalidation/replacement transition; or
4. cancellation and replacement revision.

P3B1 selects and implements none. Lifecycle materialization retries remain unsupported until Decision D16 is separately resolved. Operational delivery retry/reclaim and response-loss recovery of the original `EvidenceSnapshotCaptured` ledger task remain supported.

## 5. Build coordinator contract

The proposed `GHCA_ACD_Archive_Build_Coordinator` is an application service over existing aggregate commands and Unit of Work. It is not a second state machine.

### 5.1 Required validation order

1. Before invoking the handler, the worker coordinator validates the task against the closed ledger catalog and loads the authoritative `EvidenceSnapshotCaptured` trigger, stream, and snapshot bindings. Attempt-five recovery also performs the receipt/history lookup in section 4.4 first.
2. The handler performs external materialization/storage work and returns the bounded prepared ledger result.
3. Before calling the Build Coordinator, the worker coordinator validates the complete prepared result and committed object as specified in section 4.2.
4. The Build Coordinator reconstructs the accepted aggregate and validates current revision/sequence and build phase.
5. It matches tenant/site, stream, archive, build attempt, snapshot, task type, trigger, and preallocated ledger artifact ID.
6. It revalidates the authoritative snapshot and any existing artifact/manifest/item rows; contradictory retained data fails closed.
7. The worker coordinator rechecks the live lease immediately before the command transaction.
8. The Build Coordinator constructs the existing command with the task-derived command ID and internal system actor.
9. It passes the exact task fence and validated side records to the existing Unit of Work.
10. After commit, only the stored receipt/event decision and fixed coordinator constants are consulted; the handler result is not validated again.

Any history/prepared-value disagreement fails closed. The coordinator never writes a projection, event, receipt, snapshot, descriptor, manifest, or ledger row directly.

### 5.2 Exact P3B1 command mapping

| Task | Prepared outcome | Existing command/aggregate operation |
|---|---|---|
| `materialize_ledger` | Descriptor, manifest facts, and ledger items | Existing `RecordMaterializedArtifact` command with `artifact_kind = ledger` / aggregate `materialize_ledger`. |
| Permanent build failure | Closed phase/code/safe message and accepted identifiers | Existing `FailArchive` command / aggregate `fail_archive`; source drift uses the accepted atomic drift/failure path. |

Mappings for capture, packet, and verification are deferred with their payload/handler decisions; P3B1 does not authorize them.

Stable coordinator rejection codes are `task_payload_invalid`, `task_lease_lost`, `archive_build_binding_invalid`, `archive_snapshot_invalid`, `archive_ledger_invalid`, `archive_immutable_conflict`, and `task_outcome_commit_failed`. Repository/private-store reasons are classified only through the closed mapping in section 4.3.1 and are redacted from task error text.

No lifecycle success is observable before Unit-of-Work commit. If object commit precedes DB commit and the process crashes, retry reopens the same immutable key, verifies identical bytes, and replays/retries the command. The unreferenced object remains reportable as an orphan if authoritative commit never succeeds; P3B does not delete it.

## 6. Evidence-capture boundary

### 6.1 Proposed interface

Defer implementation to P3B2a. Freeze the conceptual boundary as one injected evidence source with one operation:

```text
read_consistent_evidence(tenant_id, stream_id, employee_id, reviewed_fingerprint, limits)
```

It returns only the bounded canonical facts needed by the already-frozen snapshot schema plus a calculated source fingerprint. The interface returns no WordPress object, query handle, password, cookie, URL, HTML, or filesystem path.

Approved implementations would be:

- a disposable/fake source for dark-mode acceptance; and
- later, one `wpdb`/LearnDash implementation explicitly authorized for current-site read access.

No REST source, remote database source, browser source, or direct use of the existing UI/audit cache is approved.

### 6.2 Consistent-read contract proposed for P3B2a

- Dedicated connection/session where the platform permits it; InnoDB `REPEATABLE READ`, read-only consistent snapshot, no `FOR UPDATE`.
- Maximum transaction wall time 2 seconds.
- One employee/case per read.
- Maximum 10,000 source rows, 10,000 canonical values, and 10,000 evidence assets; snapshot canonical bytes remain at most 1 MiB.
- Explicit ordered queries only; no unbounded option/meta scans and no N+1 queries.
- Reads limited to user identity fields already approved for employment/training evidence, LearnDash course/progress/completion facts, applicable configuration/policy inputs, and certificate references required by the frozen snapshot.
- Compute the reviewed and captured source fingerprints from the same normalized, ordered evidence document using the accepted digest/canonical rules; do not hash database serialization or query order.
- Close/rollback the read transaction in `finally` before certificate generation/acquisition, filesystem work, rendering, or any network operation.

If the consistent captured fingerprint differs from the reviewed fingerprint, commit the accepted source-drift/failure outcome and do not publish snapshot success. A LearnDash change committed before the consistent snapshot is therefore detected. A change committed after the snapshot cannot tear the snapshot; it is checked again by the approved verification-time fingerprint before finalization. A limit breach is a fixed permanent `archive_evidence_incomplete` outcome, not truncation.

Data is minimized to frozen snapshot fields. No PHI or PCI is allowed. Operational errors contain identifiers only where the existing schema requires immutable IDs; names, email addresses, course titles, certificate bytes, and source values never enter task rows or logs.

The exact LearnDash tables/meta keys, normalization rules, and source-fingerprint golden vector cannot safely be inferred from current UI code and require a separate P3B2a owner decision. No current-site query is authorized by this proposal.

## 7. Certificate acquisition decision

### Option A — trusted local generation (recommended direction, deferred)

Call an injected, owned local generator with canonical snapshot facts. It must have a stable pure API, pinned version/assets/fonts, no browser session, no network, and deterministic output or an explicitly recorded producer/version contract. This has the smallest SSRF/authentication surface, but the repository does not presently own such a certificate generator, so P3B1 must not invent it.

### Option B — authenticated same-site WordPress route (not recommended for P3B1)

This adds a route/controller, service authentication, HTTP/TLS, loopback/rebinding, and runtime bootstrap before the handler exists. It also risks coupling archival correctness to web availability. It requires its own controller/security decision and is outside P3B1.

### Option C — external HTTP acquisition (excluded unless separately approved)

The existing browser-cookie forwarding and disabled TLS verification pattern is prohibited. If external HTTP is later considered, the minimum contract is HTTPS with verification enabled; exact scheme/host/port/path allowlist; DNS resolution and address validation at connection time and every redirect; rejection of loopback, link-local, private, reserved, and metadata addresses unless a separately approved exact same-site exception exists; zero redirects by default (maximum two only within the same allowlist); no browser cookies; scoped service credential by injection; 5-second connect and 20-second total timeout; 16 MiB response ceiling; exact PDF media/size/SHA-256 validation; retry only transport failures, 429, and 5xx; permanent failure for auth, other 4xx, redirect-policy, media, or size violations.

**Recommendation:** approve deferral to P3B2a and select Option A as the design target. Do not authorize any network integration now. This choice cannot safely be inferred because no owned deterministic generator is currently present.

## 8. Deterministic materialization

### 8.1 Ledger — complete `ledger-document-v1` contract for P3B1

Inputs are the authoritative retained snapshot, the exact ledger task, and fixed producer literals only. There is no live source read, clock value, locale sort, float, random value, environment path, or network input in the bytes.

#### 8.1.1 Exact top-level document

The committed ledger is one canonical `ghca-cjson-1` object with exactly these fields:

| Field | Type and exact source |
|---|---|
| `archive_id` | 32 lowercase hexadecimal characters; `snapshot.case.archive_id`, equal to task/stream binding. |
| `build_attempt_id` | 32 lowercase hexadecimal characters; task `build_attempt_id`. |
| `canonical_format` | Literal `ghca-cjson-1`. |
| `item_count` | Integer `0..10000`; exact `count(snapshot.courses)`. |
| `item_digests` | Ordered list of 64-lower-hex `GHCA_ACD_Archive_Digester::item(item)` values; same length/order as `items`. |
| `items` | Ordered list of exact ledger-item-v1 objects below, one for each `snapshot.courses` element without resorting. |
| `ledger_artifact_id` | 32 lowercase hexadecimal characters; task `ledger_artifact_id`. |
| `manifest_digest` | `GHCA_ACD_Archive_Digester::ledger_manifest(item_digests)`. |
| `schema_version` | Integer `1`. |
| `snapshot_digest` | 64 lowercase hexadecimal characters; retained snapshot row/capture-event digest. |
| `snapshot_id` | 32 lowercase hexadecimal characters; `snapshot.case.snapshot_id`, equal to task. |
| `stream_id` | 32 lowercase hexadecimal characters; `snapshot.case.stream_id`, equal to task/row. |

No other top-level field is allowed. Canonical encoded size is at most 8 MiB; a limit breach is `archive_ledger_invalid`, never truncation.

#### 8.1.2 Exact ordered ledger item and snapshot mapping

Each `items[n]` has exactly these fields. `item_ordinal` is the zero-based position in the already-canonical `snapshot.courses` list; P3B1 does not apply a second sort.

| Item field | Exact value |
|---|---|
| `archive_id` | `snapshot.case.archive_id` |
| `certificate_artifact_id` | `snapshot.courses[n].certificate_artifact_id` |
| `completed_at_gmt` | `snapshot.courses[n].completed_at_gmt` |
| `completion_status` | `snapshot.courses[n].completion_status` |
| `course_id` | `snapshot.courses[n].course_id` |
| `course_stable_key` | `snapshot.courses[n].course_stable_key` |
| `course_title` | `snapshot.courses[n].course_title` |
| `cycle_key` | `snapshot.case.cycle_key`, which must equal `snapshot.cycle.key` |
| `employee_user_id` | `snapshot.subject.employee_user_id`, which must equal `snapshot.case.employee_user_id` |
| `item_ordinal` | Integer `n`, contiguous from zero |
| `item_schema_version` | Integer `1` |
| `ledger_artifact_id` | Task `ledger_artifact_id` |
| `program_key` | `snapshot.case.program_key` |
| `quiz_score_basis_points` | `snapshot.courses[n].quiz_score_basis_points` |
| `snapshot_id` | `snapshot.case.snapshot_id` |
| `started_at_gmt` | `snapshot.courses[n].started_at_gmt` |
| `stream_id` | `snapshot.case.stream_id` |
| `time_spent_seconds` | `snapshot.courses[n].time_spent_seconds` |

Canonical JSON orders object keys lexicographically. Item list order is snapshot course order, already frozen as `(category_order, course_order, unsigned course_id)` by the snapshot contract. The queryable ledger rows and the `items` array contain identical item documents.

#### 8.1.3 Null, integer, and unsigned-decimal rules

- Only `certificate_artifact_id`, `completed_at_gmt`, `course_stable_key`, `quiz_score_basis_points`, and `started_at_gmt` may be null. No top-level field may be null.
- `certificate_artifact_id` is null exactly when `certificate_required` is false; otherwise it is the exact 32-character snapshot artifact ID.
- Nullable timestamps/stable key/quiz score are copied without substitution. Empty string, zero timestamp, missing key, and string `"null"` are forbidden substitutes.
- `employee_user_id` and `course_id` are positive unsigned-decimal strings; `time_spent_seconds` is an unsigned-decimal string that may be `"0"`. They use `^(?:0|[1-9][0-9]*)$`, no sign, whitespace, decimal point, exponent, or leading zero, and maximum `18446744073709551615`. They are never PHP/JSON numbers.
- `quiz_score_basis_points`, when non-null, is an integer `0..10000`. `item_ordinal`, `item_count`, and both schema versions are JSON integers, not strings.
- Timestamps retain the exact accepted UTC `YYYY-MM-DDTHH:MM:SSZ` representation from the snapshot.

#### 8.1.4 Descriptor, storage, manifest, and event bindings

The exact 13-field descriptor is:

| Descriptor field | Exact value |
|---|---|
| `artifact_id` | Task `ledger_artifact_id` |
| `artifact_kind` | `ledger` |
| `artifact_schema_version` | Integer `1` |
| `byte_count` | Exact byte length of canonical ledger bytes |
| `content_digest` | Lowercase SHA-256 of those exact bytes |
| `content_digest_algorithm` | `sha256` |
| `filename` | `archive-ledger.json` |
| `media_type` | `application/json` |
| `producer_key` | `ghca_archive_ledger_materializer` |
| `producer_version` | `1.0.0` |
| `role_key` | `ledger` |
| `storage_adapter` | `private_local` |
| `storage_key` | Existing store derivation `committed/{tenant_id}/{stream_id}/{archive_id}/{ledger_artifact_id}.json` |

The storage identity uses `tenant_id = snapshot.case.tenant_id` and the exact stream/archive/artifact IDs above. The descriptor binding passed to the existing repository uses the same `stream_id`, `archive_id`, `snapshot_id`, `build_attempt_id`, and `snapshot_digest`; `created_at_gmt` is the Unit-of-Work commit time and is not part of the deterministic ledger bytes.

The ordered item digests determine `manifest_digest` through the already-frozen `ghca-ledger-manifest-v1` digest. The same `manifest_digest` and `item_count` bind the top-level ledger, ledger-item insert batch, and `LedgerMaterialized` payload. The event payload has exactly `archive_id`, `build_attempt_id`, `content_digest`, `item_count`, `ledger_artifact_id`, `manifest_digest`, `snapshot_digest`, and `snapshot_id`. Every value must equal the task, retained snapshot, descriptor, committed object, and ledger rows; no handler-supplied duplicate binding is trusted without recomputation.

Stage/write/flush/close/read-back/validate/commit follows P3A. Cleanup is limited to the invocation's validated staging key. Committed objects are never removed or overwritten. Repeated execution must yield the same bytes, descriptor, item rows, digests, and event payload.

#### 8.1.5 Independent literal golden vector

Future tests must hardcode the following literals independently; expected values must not be produced by the materializer, repository, or digester under test. Identity literals are tenant=`111...`, stream=`222...`, archive=`333...`, snapshot=`444...`, attempt=`555...`, ledger=`666...`, snapshot digest=`777...`, and certificate=`888...`, at the exact lengths shown.

Item 0 canonical bytes and digest:

```text
{"archive_id":"33333333333333333333333333333333","certificate_artifact_id":"88888888888888888888888888888888","completed_at_gmt":"2026-02-03T04:05:06Z","completion_status":"completed","course_id":"42","course_stable_key":null,"course_title":"Safe & Ready","cycle_key":"2026","employee_user_id":"18446744073709551615","item_ordinal":0,"item_schema_version":1,"ledger_artifact_id":"66666666666666666666666666666666","program_key":"annual_training","quiz_score_basis_points":9875,"snapshot_id":"44444444444444444444444444444444","started_at_gmt":"2026-01-02T03:04:05Z","stream_id":"22222222222222222222222222222222","time_spent_seconds":"3600"}
99f9e9253959df303166e0123a5bfc07b18aed53f0d1db4440b0ca857247ddc3
```

Item 1 canonical bytes and digest:

```text
{"archive_id":"33333333333333333333333333333333","certificate_artifact_id":null,"completed_at_gmt":null,"completion_status":"in_progress","course_id":"9007199254740993","course_stable_key":"course-9007199254740993","course_title":"Prevention \"A\"","cycle_key":"2026","employee_user_id":"18446744073709551615","item_ordinal":1,"item_schema_version":1,"ledger_artifact_id":"66666666666666666666666666666666","program_key":"annual_training","quiz_score_basis_points":null,"snapshot_id":"44444444444444444444444444444444","started_at_gmt":"2026-03-04T05:06:07Z","stream_id":"22222222222222222222222222222222","time_spent_seconds":"0"}
3f8c25d42c28c18acdca5cdb18f4a2a63dd475fa82e4184c01d2a5580dde22b1
```

Manifest digest:

```text
a5a434976c4ebff6c13509eb0ceef371521a45105bebf95dd1f41950930f5b45
```

Complete canonical ledger bytes (the single JSON line inside the fence, excluding the display newline, is exactly 1,928 bytes):

```json
{"archive_id":"33333333333333333333333333333333","build_attempt_id":"55555555555555555555555555555555","canonical_format":"ghca-cjson-1","item_count":2,"item_digests":["99f9e9253959df303166e0123a5bfc07b18aed53f0d1db4440b0ca857247ddc3","3f8c25d42c28c18acdca5cdb18f4a2a63dd475fa82e4184c01d2a5580dde22b1"],"items":[{"archive_id":"33333333333333333333333333333333","certificate_artifact_id":"88888888888888888888888888888888","completed_at_gmt":"2026-02-03T04:05:06Z","completion_status":"completed","course_id":"42","course_stable_key":null,"course_title":"Safe & Ready","cycle_key":"2026","employee_user_id":"18446744073709551615","item_ordinal":0,"item_schema_version":1,"ledger_artifact_id":"66666666666666666666666666666666","program_key":"annual_training","quiz_score_basis_points":9875,"snapshot_id":"44444444444444444444444444444444","started_at_gmt":"2026-01-02T03:04:05Z","stream_id":"22222222222222222222222222222222","time_spent_seconds":"3600"},{"archive_id":"33333333333333333333333333333333","certificate_artifact_id":null,"completed_at_gmt":null,"completion_status":"in_progress","course_id":"9007199254740993","course_stable_key":"course-9007199254740993","course_title":"Prevention \"A\"","cycle_key":"2026","employee_user_id":"18446744073709551615","item_ordinal":1,"item_schema_version":1,"ledger_artifact_id":"66666666666666666666666666666666","program_key":"annual_training","quiz_score_basis_points":null,"snapshot_id":"44444444444444444444444444444444","started_at_gmt":"2026-03-04T05:06:07Z","stream_id":"22222222222222222222222222222222","time_spent_seconds":"0"}],"ledger_artifact_id":"66666666666666666666666666666666","manifest_digest":"a5a434976c4ebff6c13509eb0ceef371521a45105bebf95dd1f41950930f5b45","schema_version":1,"snapshot_digest":"7777777777777777777777777777777777777777777777777777777777777777","snapshot_id":"44444444444444444444444444444444","stream_id":"22222222222222222222222222222222"}
```

Ledger content digest:

```text
3a048a719bd3f3a9642b93f1abbe7b39388c4199ab26824c3344c040887c424e
```

Exact canonical descriptor:

```json
{"artifact_id":"66666666666666666666666666666666","artifact_kind":"ledger","artifact_schema_version":1,"byte_count":1928,"content_digest":"3a048a719bd3f3a9642b93f1abbe7b39388c4199ab26824c3344c040887c424e","content_digest_algorithm":"sha256","filename":"archive-ledger.json","media_type":"application/json","producer_key":"ghca_archive_ledger_materializer","producer_version":"1.0.0","role_key":"ledger","storage_adapter":"private_local","storage_key":"committed/11111111111111111111111111111111/22222222222222222222222222222222/33333333333333333333333333333333/66666666666666666666666666666666.json"}
```

Exact canonical `LedgerMaterialized` payload:

```json
{"archive_id":"33333333333333333333333333333333","build_attempt_id":"55555555555555555555555555555555","content_digest":"3a048a719bd3f3a9642b93f1abbe7b39388c4199ab26824c3344c040887c424e","item_count":2,"ledger_artifact_id":"66666666666666666666666666666666","manifest_digest":"a5a434976c4ebff6c13509eb0ceef371521a45105bebf95dd1f41950930f5b45","snapshot_digest":"7777777777777777777777777777777777777777777777777777777777777777","snapshot_id":"44444444444444444444444444444444"}
```

These literals must match independently on PHP 8.3.30 and PHP 8.5.7. No new digest algorithm is introduced; the vectors exercise the accepted canonical JSON, `ghca-item-v1`, `ghca-ledger-manifest-v1`, and raw SHA-256 byte contracts.

### 8.2 Packet/PDF — deferred target

Packet inputs are only the sealed snapshot and its referenced committed certificate objects. It must not reread LearnDash, WordPress UI state, current time, or remote assets.

The repository bundles FPDI 2.6.0 under the MIT licence, but the observed TCPDF 6.11.2 installation is owned by LearnDash and is not a pinned plugin dependency. P3B must not make archive correctness depend on another plugin's private library path. Before P3B2b, the owner must approve an owned/pinned rendering stack, its licence/distribution implications, exact producer/version string, deterministic metadata policy, embedded fonts/assets with recorded digests, and golden output behavior across PHP 8.3/8.5.

Proposed ceilings for that later decision are: 10,000 evidence rows, 500 pages, 64 MiB final packet, 256 MiB incremental worker memory, and 90 seconds renderer wall time. Temporary files must live under an injected validated private staging root, be uniquely owned by the invocation, and be cleaned only within that boundary. The renderer must not fetch fonts, images, CSS, certificates, or URLs. PDF structure, byte count, and SHA-256 must pass P3A validation before commit.

Because PDF libraries may emit version-, compression-, timestamp-, or platform-dependent bytes, identical authoritative descriptors cannot be promised until golden cross-runtime tests prove the chosen stack. This decision is blocked and packet implementation remains outside P3B1.

## 9. Secret and path provisioning

Constructor injection is the only P3B1 ruling. The ledger handler/coordinator receive existing task, snapshot, artifact-repository, artifact-store, clock, and Unit-of-Work dependencies directly. P3B1 adds no configuration loader, constant reader, WordPress option reader, environment reader, site resolver, or multisite composition.

### 9.1 P3B1 dark-mode provisioning

- Tests instantiate the accepted private-local store with one isolated temporary private root, an explicit test public-root exclusion list, and an injected valid 64-lower-hex test cursor key.
- The test root and key are test data only. They must not be read from the current site, plugin constants, WordPress salts/options, environment configuration, or `.claude/`.
- Cleanup remains limited to the validated isolated test root. No production path or secret is selected.
- The ledger handler receives the already-constructed artifact-store contract; it never constructs absolute paths or reads a key.

Secrets never enter events, snapshots, artifact descriptors, tasks, outcomes, command receipts, options/database rows, logs, exception text, or generated documents. WordPress salts are not an approved substitute. Validation must occur before enabling task intake; missing/invalid values fail closed with fixed safe health codes.

### 9.2 Deferred P3B3 production decision

P3B3 must separately decide the authoritative production source and validation lifecycle for the private root, public-root list, orphan-cursor key, multisite/site mapping, file ownership/ACL deployment, key rotation, root migration, process overlap, rollback, and any later certificate credential. This proposal does not select constants, environment variables, a secrets manager, WordPress configuration, options, or network-level storage.

Existing P3A invariants remain: wrong-key cursors fail before cursor-controlled behavior; private storage stays outside public roots with no uploads fallback; root changes cannot strand retained descriptors; and secrets remain outside retained archive/task data. Those invariants do not authorize a production source.

### 9.3 Filesystem requirements

POSIX deployments require the service account to own the root, directories at `0700`, and files at `0600`. Windows deployments require an ACL limited to the PHP/web service identity and administrators; POSIX mode bits alone are not evidence. Existing realpath/containment, absolute/traversal/mixed-separator rejection, no-overwrite, symlink rejection, regular-file revalidation, and public-root exclusion remain mandatory.

## 10. Runtime composition and wake-up decision

No wake-up wiring, composition root, or production concurrency policy belongs in P3B1. Tests call the coordinator directly with injected dependencies and an installed-type allowlist containing only `materialize_ledger`.

### 10.1 Options

| Mechanism | Assessment |
|---|---|
| Native WP-Cron | No new dependency and fits existing WordPress distribution, but traffic-driven cron alone cannot guarantee the queue-age target. A host system cron calling `wp-cron.php` is an operations prerequisite. |
| Action Scheduler | Strong queue tooling, but the plugin has no owned/pinned dependency and P3A already owns the durable task model. P3B3 must decide whether that duplication is acceptable. |
| WP-CLI | Good operator recovery and host scheduler target, but not a primary in-request runtime and no command is currently registered. |
| Admin/AJAX wake-up | Request-bound, user-triggered, capability/nonce-sensitive, and prone to browser disconnects. These risks must be resolved if P3B3 considers it. |

### 10.2 P3B3 decision required

P3B3 must select the primary wake-up mechanism and any operator-only recovery mechanism after all task types it will claim have installed production handlers. It must then freeze maximum tasks per invocation, wall-clock budget, per-site concurrency, how existing P3A lease/heartbeat values interact with that budget, graceful shutdown, crash recovery, overlap/admission control, multisite scope, capability/nonce rules, feature flags, kill switch, manual recovery, and host-cron prerequisites.

P3B1 approves none of those runtime values. The existing P3A `run_once()` behavior remains a directly callable dark-mode test boundary, not authorization for WordPress scheduling or a production concurrency limit. No hook, cron event, Action Scheduler registration, WP-CLI command, AJAX wake-up, configuration loading, or runtime activation is added.

## 11. Observability and health

P3B1 exposes bounded in-process result fields to tests only and adds no external telemetry dependency, debug output, hook, option, or schema.

The later composition slice should expose these structured, non-PII fields through an approved operator surface:

- counts by task state and task type;
- age in seconds of the oldest available pending/retry task;
- active lease count, expired lease count, reclaim count, and lease-loss count;
- attempts, retries, dead-letter count by stable reason code, and task duration buckets;
- last successful worker heartbeat/invocation timestamp and last committed task timestamp;
- private-root readable/writable/containment status, public-root exclusion status, cursor-key validity status, and immutable-open check status;
- last safe stable reason code only, never raw exception text.

Proposed alert thresholds:

- warning when the oldest available build task exceeds 300 seconds;
- warning on any expired live-looking lease or repeated lease loss; critical when more than five accumulate in 10 minutes;
- critical on any dead `verify_and_finalize`, integrity task, or `archive_immutable_conflict`;
- critical immediately for invalid/missing cursor key, unsafe/unwritable private root, public-root overlap, or immutable object mismatch;
- warning at attempt 3; critical at attempt 5/dead.

Health values must be bounded and safely redacted. No names, emails, course titles, payload JSON, file paths, SQL, stack traces, secrets, object bytes, URLs, or current-site credentials may be logged. Production `echo`, `var_dump`, `print_r`, `error_log`, or display-errors output remains prohibited. Any persistent heartbeat/health registry requires its own storage and exposure decision in P3B3.

## 12. Explicit exclusions

This proposal and P3B1 explicitly exclude:

- plugin-entrypoint changes, runtime hooks, cron registration, Action Scheduler, WP-CLI registration, or composition loading;
- activation, deployment, feature enablement, migrations, or current-site database access;
- REST, AJAX, admin, download, or public/private artifact controllers;
- LearnDash evidence capture and certificate generation/acquisition;
- packet/PDF renderer code or a new rendering dependency;
- reset execution, reset reconciliation, projection rebuild, integrity execution, and source-drift detector execution outside the later approved capture/verify boundary;
- orphan quarantine/deletion and public artifact exposure;
- table/schema changes, new lifecycle events, or mutation/update/delete of immutable rows;
- canonical JSON or frozen digest changes;
- network integration or runtime secret/configuration loading;
- unrelated PHP 8.3 refactoring;
- staging, committing, pushing, activation, or deployment.

## 13. Verification design for future implementation

Every negative case must assert the exact exception class, exact stable reason code, no unintended database residue, and no filesystem residue outside the isolated root.

### 13.1 Named P3B1 regression groups

**Task catalog and payloads**

- `P3B1-PAYLOAD-LEDGER-EXACT-V1`
- per field: missing, extra, null, wrong type, uppercase/wrong-length ID, wrong task/row binding, wrong trigger kind/event, noncanonical JSON, depth, value-count, and 512-byte boundary
- ledger artifact-ID preallocation by the trusted Unit of Work for an authoritative `EvidenceSnapshotCaptured`; duplicate enqueue/delivery of that trigger reuses the same task and artifact identity
- `P3B1-LIFECYCLE-RETRY-DEFERRED` proves `ArchiveRetryRequested`, including `resume_phase = materializing`, does not enqueue an installed P3B1 `materialize_ledger` task
- `P3B1-DEFERRED-PAYLOADS-UNCHANGED` proves capture, packet, and verify payload production is byte-for-byte unchanged
- `P3B1-INSTALLED-TYPE-AVAILABLE-CLAIM` proves earlier capture/packet rows remain untouched while the next eligible ledger row is claimed
- `P3B1-INSTALLED-TYPE-EXPIRED-RECLAIM` proves earlier expired capture/packet leases remain untouched while the next eligible expired ledger lease is reclaimed
- malformed/empty/unknown installed-type filters fail before a claim transaction

**Handler outcome and coordinator**

- exact 27-byte `result_code=committed` outcome and unchanged completed digest golden vector
- `P3B1-PREPARED-RESULT-INVALID-BEFORE-UOW` covers missing/extra/nested/free-form/cyclic/oversized/mismatched prepared values and asserts zero Build Coordinator/UoW calls
- coordinator synthesizes the fixed outcome after commit/replay; no handler-controlled value is validated after lifecycle commit
- task/case/revision/build-attempt/snapshot/artifact/trigger mismatch
- fence loss before work, after staging, after object commit, immediately before command, during Unit of Work, and before task completion
- tampered event, snapshot, descriptor, manifest, ledger item, committed object, byte count, media, digest, and logical key
- duplicate delivery and command-response loss replay without duplicate event, row, or object
- `P3B1-ATTEMPT5-CRASH-AFTER-COMMAND-BEFORE-TASK-COMPLETE` replays the committed materialization and completes without `ArchiveFailed`
- `P3B1-ATTEMPT5-CRASH-AFTER-OBJECT-BEFORE-COMMAND` reuses exact immutable bytes and commits materialization once
- `P3B1-ATTEMPT5-EXHAUSTED-NO-PRIOR-OUTCOME` commits `archive_build_attempts_exhausted` only for a classified retryable operational failure after final recovery and the second receipt/history lookup find no outcome
- `P3B1-FAILURE-MAPPING-CLOSED` enumerates every reachable snapshot-read, artifact-repository, and private-store reason/context in section 4.3.1 and rejects any unmapped reason as operational-blocked with no lifecycle invention
- `P3B1-ATTEMPT5-IMMUTABLE-CONFLICT-PRESERVED` proves a committed-key/retained-row conflict remains `archive_immutable_conflict`, never exhaustion
- `P3B1-ATTEMPT5-LEDGER-INVALID-PRESERVED` proves a deterministic permanent ledger failure remains `archive_ledger_invalid`, never exhaustion
- `P3B1-ATTEMPT5-RETRYABLE-EXHAUSTION` proves only a still-failing classified retryable operational cause after final deterministic recovery commits `archive_build_attempts_exhausted`
- `P3B1-ATTEMPT5-UNKNOWN-FAILURE-NO-LIFECYCLE-INVENTION` proves an unknown/unclassified failure dead-letters or reports only the sanitized operational task failure and appends no `ArchiveFailed`
- `P3B1-AUTHORITATIVE-OPEN-FAILURE-NOT-IMMUTABLE` proves authoritative committed-object `artifact_open_failed` is retryable operational before attempt five and is never `archive_immutable_conflict`
- `P3B1-DERIVED-PATH-ESCAPE-NO-LIFECYCLE` proves coordinator-derived `artifact_path_escape` is operational-blocked and appends no lifecycle event
- `P3B1-STAGING-SYMLINK-NO-LIFECYCLE` proves staging/parent/infrastructure `artifact_symlink_rejected` is operational-blocked and appends no lifecycle event
- `P3B1-COMMITTED-MISMATCH-IS-IMMUTABLE` proves committed-object size/digest mismatch, occupied committed collision/symlink, or `artifact_immutable_mismatch` preserves `archive_immutable_conflict` through fenced failure submission
- each of the preceding four storage-classification regressions asserts the exact exception class/reason, exact task disposition, exact lifecycle-event count, absence of a false `ArchiveFailed`, unchanged immutable database rows where applicable, and no filesystem mutation outside the isolated root
- crash after exhaustion-failure commit but before dead-letter replays the failure and dead-letters exactly once
- retryable failures affect only operational task state; permanent/exhausted failures require explicit lifecycle command and never derive from lease expiry/death
- `P3B1-MATERIALIZATION-RACES-FAILURE-SUBMISSION` interleaves `RecordMaterializedArtifact` with the first `FailArchive` submission and proves authoritative materialization wins the conflict recheck
- `P3B1-FAILURE-STREAM-CONFLICT-RECHECK` proves an expected-sequence/head conflict reloads history under the live fence and never blindly retries stale failure intent
- `P3B1-CROSS-COMMAND-DEDUPE-DISTINCT` proves equal task-outcome key inputs produce distinct `RecordMaterializedArtifact` and `FailArchive` scope/dedupe identities and cannot cross-replay receipts
- `P3B1-NO-SUCCESS-THEN-ARCHIVE-FAILED` proves no successful matching `LedgerMaterialized` can be followed by `ArchiveFailed` for the same task outcome

**Ledger determinism and storage**

- `P3B1-LEDGER-DOCUMENT-V1-LITERAL-GOLDENS-PHP83` asserts every independent literal in section 8.1.5 on PHP 8.3.30
- `P3B1-LEDGER-DOCUMENT-V1-LITERAL-GOLDENS-PHP85` asserts the same bytes, item/content/manifest digests, descriptor, and event payload on PHP 8.5.7
- canonical output follows retained snapshot order; attempts to reorder the already-canonical course list are rejected rather than silently sorted
- maximum item/byte boundaries; no truncation
- traversal, absolute path, mixed separator, symlink, public-root, collision, overwrite, and immutable mismatch attacks
- staging cleanup is invocation-owned and cannot escape the isolated test root
- failed command leaves at most a reportable unreferenced committed object, never an event/descriptor partial commit

**Security and boundaries**

- secrets, PII, paths, SQL, exception text, and stack traces absent from task payload/outcome/error/log/receipt fixtures
- no hook, entrypoint, cron, Action Scheduler, WP-CLI, REST, AJAX, controller, current-site bootstrap, current-site credentials/database, network call, renderer dependency, schema change, or debug output
- reset/rebuild/destructive orphan behavior remains disabled

### 13.2 Later P3B2 regressions

- bounded repeatable-read source fixture: transaction closure on every path, row/value/byte ceilings, concurrent change before/after snapshot, normalized fingerprint golden vectors, source drift, and no external work inside transaction;
- certificate option-specific TLS/allowlist/redirect/DNS/auth/cookie/timeout/size/media/retry tests if any network path is separately approved;
- packet producer/version/font/asset recording, page/memory/time ceilings, cross-runtime golden determinism, cleanup, and crash replay;
- verify/finalize source drift, artifact tamper, second-materialization trigger, atomic two-event commit, and response replay.

### 13.3 Required matrix

The future implementation must run PHP 8.3.30 and PHP 8.5.7 against MySQL 8.0, MySQL 8.4, and MariaDB 10.6, using only disposable databases with the approved safety prefix. It must also run kernel, legacy, P1/P2/P3A/P3B suites, full archive/test lint, `git diff --check`, and forbidden-surface scans on both runtimes. PHP 8.4 remains optional and must be reported unavailable unless an actual CLI exists.

## 14. Decision table

| No. | Question | Recommended ruling and justification | Alternatives/defer reason | Affected contracts/classes | Compatibility/reversal impact | Exact owner response |
|---|---|---|---|---|---|---|
| D01 | What is the smallest P3B1? | Implement only the closed task catalog, coordinator handoff, and complete dark-mode `materialize_ledger` vertical slice. This proves fencing, immutable storage, Unit-of-Work commit, and replay without current-site/network/render risk. | All four handlers is too broad; scheduler-only has no useful handler. | Task store, Unit of Work, worker coordinator, new catalog/build coordinator/ledger handler/materializer. | No DB schema/event/digest migration. Reversal before activation is code/test only. | `Approve D01 as written.` |
| D02 | What exact task payload is frozen? | Freeze only the exact nine-field `materialize_ledger` v1 payload in section 3.1, preallocate only its ledger artifact ID, and produce it only from authoritative `EvidenceSnapshotCaptured`. Capture, packet, verify, and lifecycle-retry payload changes are deferred to their approved implementation/decision slices. | Freezing unimplemented handlers or inferring a partial-artifact lifecycle retry creates speculative retained contracts. | Unit of Work ledger task production, ledger task catalog/store validation, persistence fixtures. | Changes only unactivated ledger task payload/dedupe. Later reversal after retained ledger tasks requires task-version/migration policy, not table/event/digest migration. | `Approve D02 as written.` |
| D03 | How do prepared facts become the fixed task outcome? | `handler(task, heartbeat)` returns a bounded prepared ledger result; coordinator validates it before any UoW call, invokes the fenced Build Coordinator/UoW, then synthesizes the existing completed/committed outcome after commit/replay. | A commit callback lets handler-controlled validation occur after lifecycle commit; expanding the durable outcome leaks data and changes frozen grammar/digest. | Worker coordinator, ledger handler, build coordinator, P3A worker tests. | No persisted compatibility change. Later worker-outcome grammar change requires a new version. | `Approve D03 as written.` |
| D04 | How are handler facts turned into lifecycle state? | Build coordinator revalidates authoritative history/immutable rows and invokes only existing aggregate commands through fenced receipt-first UoW. | Direct event/row writes or a parallel state machine violate accepted authority. | New build coordinator; existing command, aggregate, UoW, repositories. | No schema/event/digest change. Reversal is application architecture. | `Approve D04 as written.` |
| D05 | What exactly happens on attempt five? | Follow sections 4.3.1-4.5: replay matching outcomes first; recover exact bytes; try `RecordMaterializedArtifact`; preserve positively proven immutable conflicts; allow retryable `artifact_open_failed` to become `archive_build_attempts_exhausted` only after final recovery still fails; dead-letter/report path, containment, non-committed symlink, unknown, and other operational-blocked causes without lifecycle invention. | Immediate exhaustion can convert response loss or ambiguous I/O into the wrong lifecycle fact; treating containment as a business binding failure or every symlink as retained conflict records an unproven event; blind conflict retry can append failure after success. | Worker/build coordinators, exact reason-plus-operation-context mapping, distinct command receipt/event lookups, failure command path, tests. | No new event type/schema or low-level reason. Failure-code value becomes retained event semantics once activated. | `Approve D05 as written.` |
| D06 | Which exact materializer contract is approved now? | Ledger only, with the complete `ledger-document-v1`, snapshot mappings, null/decimal rules, fixed descriptor producer/storage metadata, event/manifest bindings, and literal golden vectors in section 8.1. | Packet depends on an unresolved renderer; a partially specified ledger cannot prove deterministic recovery. | New ledger materializer/handler; snapshot/artifact/ledger repositories and store. | No schema/digest algorithm change. The approved document, producer/version, filename, ordering, and vectors become retained-data contracts after activation. | `Approve D06 as written.` |
| D07 | Is evidence capture ready? | No. Freeze the boundary/ceilings, but defer implementation until exact LearnDash reads, normalization, fingerprint vectors, and current-site read authority are approved. | Current UI/cache code is not a sufficient evidence contract. | Future evidence source and capture handler. | No current impact. Later rules affect retained snapshot/event data and require a new snapshot/policy version if changed. | `Approve D07 deferral and require a P3B2a decision.` |
| D08 | How are certificates acquired? | Defer; target an owned trusted local generator. Exclude external HTTP now and prohibit cookie forwarding/TLS disablement. | Same-site route adds runtime/controller risk; HTTP adds SSRF/auth/TLS risk. | Future certificate source/capture handler. | No current impact. Chosen producer/version becomes retained descriptor evidence; credentials are deployment-only. | `Approve D08 deferral and Option A as the design target.` |
| D09 | Is packet/PDF materialization ready? | No. Require an owned/pinned renderer, licence approval, deterministic metadata/fonts/assets, limits, and cross-runtime golden evidence first. | LearnDash-owned TCPDF is unpinned; FPDI alone is not a complete renderer. | Future packet materializer/handler and dependency manifest. | Dependency/distribution change; retained producer/version and output digests make later reversal a new-build/replacement concern, not mutation. | `Approve D09 deferral and require a P3B2b decision.` |
| D10 | Is verify/finalize ready? | Defer until both materializers and verification source adapter exist; preserve atomic existing command. | Partial verification could finalize incomplete or drifted evidence. | Future verify handler/build coordinator. | No schema/event/digest change if existing command facts are used. | `Approve D10 deferral to P3B2c.` |
| D11 | How are secrets and paths supplied in P3B1? | Constructor injection only, with isolated test values. Defer the production source, multisite mapping, rotation, migration, ownership, and rollback contract to P3B3; add no loader. | Selecting constants/options/environment/secrets-manager behavior now is speculative runtime composition. | Existing artifact-store contract and P3B1 constructors; future P3B3 composition. | No production deployment contract or retained-data migration in P3B1. | `Approve D11 as written and defer production provisioning to P3B3.` |
| D12 | What runtime/wake-up values are approved? | None in P3B1. Defer mechanism selection, task/wall budgets, concurrency, shutdown, overlap, multisite, recovery, capability, feature-flag, and kill-switch values to P3B3. | Handlers can be dark-mode verified without speculative runtime wiring or operational numbers. | Future P3B3 composition and wake-up adapter only. | No deployment/operations or retained-data impact in P3B1. | `Approve D12 deferral to P3B3.` |
| D13 | What health contract is frozen? | Bounded non-PII counters/health fields and thresholds in section 11; no persistence/exposure dependency in P3B1. | Raw logs leak; external telemetry or an option/table needs separate approval. | Future composition/health reporter. | Operational policy only until a persistent schema/API is approved. | `Approve D13 as written.` |
| D14 | Which tasks may P3B1 claim or reclaim? | Only installed `materialize_ledger`; apply the allowlist inside both available-task selection/update and expired-lease selection/update. Earlier capture/packet rows remain untouched while eligible ledger work preserves deterministic order. | Filtering after claim can starve ledger work and mutate/dead-letter uninstalled tasks. | Task-store available claim, expired reclaim, worker coordinator, tests. | No schema/event/digest change; later installed handlers are additive allowlist values. | `Approve D14 as written.` |
| D15 | What verification/release gate applies? | Named regressions plus PHP 8.3.30/8.5.7 x three databases; no PHP 8.4 claim without CLI; dark mode and forbidden-surface scans mandatory. | A newer runtime cannot prove the PHP 8.3 floor; single-database tests cannot prove SQL portability. | Test runners, future P3B traceability. | Test/release policy only. | `Approve D15 as written.` |
| D16 | How does a lifecycle materialization retry handle a previously committed single candidate layer? | No P3B1 implementation. Defer an owner choice among cross-attempt reuse with revised finalization, an explicit reuse/rebinding event, approved invalidation/replacement before rematerialization, or cancellation/replacement revision. Until resolved, `ArchiveRetryRequested` must not enqueue an installed P3B1 ledger task. | `duplicate_ledger`, same-build-attempt finalization, and candidate-reuse permission conflict; selecting one model would change retained lifecycle semantics beyond P3B1. | Future aggregate/event/finalization/task-production design in a separately approved slice. | Retained-data affecting if event/finalization semantics change; requires explicit owner approval and compatibility plan before implementation. | `Defer D16; approve no lifecycle materialization retry in P3B1.` |

Decisions D07-D12 do not authorize capture, certificates, packet rendering, verification, production provisioning, runtime values, or wake-up wiring. Decision D16 is an unresolved lifecycle owner gate, not permission to implement any of its alternatives. Its P3B1 ruling is only that lifecycle materialization retry remains unsupported.

## 15. Proposed P3B1 implementation boundary

This list is a future allowlist, not authorization to create or edit the files.

### 15.1 Production additions

- `includes/archive/application/class-archive-task-catalog.php` — exact `materialize_ledger` v1 payload and installed-type allowlist validation only.
- `includes/archive/application/class-archive-build-coordinator.php` — fenced authoritative revalidation, distinct `RecordMaterializedArtifact`/`FailArchive` command mapping, and the failure conflict/recheck algorithm in section 4.5.
- `includes/archive/application/class-archive-ledger-task-handler.php` — snapshot-only orchestration, heartbeat, staging/immutable commit, and bounded prepared-result return.
- `includes/archive/application/class-archive-ledger-materializer.php` — pure bounded canonical ledger document/item construction.

No interface is added unless implementation proves that a second concrete implementation is required. Constructor-injected existing repositories/store and callables are sufficient for the first vertical slice.

### 15.2 Production modifications

- `includes/archive/application/class-archive-unit-of-work.php` — trusted ledger artifact-ID preallocation and exact ledger-task payload production from `EvidenceSnapshotCaptured` only; remove `materialize_ledger` from `ArchiveRetryRequested` task production while leaving capture/packet/verify payload contracts and event order unchanged.
- `includes/archive/application/class-archive-worker-coordinator.php` — validate handler-supplied descriptor/storage keys before Build Coordinator/UoW; classify storage failures by exact reason plus explicit operation context; run distinct-command receipt/history recovery and attempt-five classification; synthesize the fixed outcome after commit/replay; and disposition by authoritative decision. Prefix/message heuristics and new low-level store codes are forbidden.
- `includes/archive/infrastructure/class-wpdb-archive-task-store.php` — validate the exact ledger payload and add the same installed-type predicate to both available claim and expired reclaim selection/update; all other SQL/lease behavior remains unchanged.

### 15.3 Test additions

- `tests/archive/test-p3b-task-contracts.php`
- `tests/archive/test-p3b-build-coordinator.php`
- `tests/archive/test-p3b-ledger-handler.php`
- `tests/archive/test-p3b-ledger-failures.php`

### 15.4 Test modifications

- `tests/archive/bootstrap.php` — dark-mode class loading only.
- `tests/archive/persistence-fixtures.php` — stable ledger artifact-ID fixtures only.
- `tests/archive/test-persistence.php` and `tests/archive/test-side-record-persistence.php` — exact amended ledger payload and unchanged deferred-payload assertions.
- `tests/archive/test-p3-worker.php` — installed-type claim/reclaim, lifecycle-retry deferral, prepared-result-before-UoW, fence-loss, closed failure mapping, attempt-five recovery, distinct command dedupe, race recheck, synthesized outcome, crash, and replay regressions.
- `tests/archive/test-all.ps1` — add P3B groups to the existing exact runtime/database matrix without changing safety-prefix enforcement.

### 15.5 Documentation after implementation

- Add a P3B1 traceability/re-review record with exact file, decision, test, runtime, database, crash, fencing, containment, and repository-state evidence.
- Do not rewrite accepted P3A decisions or historical compatibility evidence.

### 15.6 Files that must remain unchanged in P3B1

- plugin entrypoint and all runtime/bootstrap/hook registration files;
- plugin metadata and distributed PHP-floor documents;
- archive schema, schema manifest, migrator, and database table definitions;
- canonical JSON and all digester algorithms/golden contracts;
- event type/catalog/schema definitions and aggregate state-machine rules;
- capture, packet, and verify task-specific payload production/validation contracts; only removal of the installed ledger task from the `ArchiveRetryRequested` production branch is allowed;
- private artifact-store storage/cursor contracts and orphan disposition behavior;
- current LearnDash/data-provider/audit/PDF/AJAX code;
- reset, rebuild, controller, download, activation, and deployment code;
- `.claude/`.

## Owner approval request

Please approve the numbered P3B decisions as written or identify the decisions requiring revision. Decisions D07-D12 approve deferral rather than capture, rendering, verification, production provisioning, or runtime/wake-up implementation. Decision D16 deliberately leaves the partial-artifact lifecycle retry model unresolved and approves only its exclusion from P3B1. No P3B production implementation will begin until the proposal is explicitly approved.
