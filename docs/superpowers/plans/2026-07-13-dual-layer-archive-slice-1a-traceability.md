# Dual-Layer Archive Slice 1A Traceability Matrix

**Date:** 2026-07-15 (fourth remediation pass; final Slice 1A acceptance review)  
**Scope:** Pure deterministic event kernel only  
**Decision record:** `2026-07-13-dual-layer-archive-implementation-decisions.md`

## Event catalog, validator, apply handler, and test

Every row is schema version 1 and rejects missing and unexpected fields. `tests/archive/remediation-fixtures.php` is independent of the production catalog. Each event has a named `D-SCHEMA-<EventType>` assertion in `test-remediation-event-schemas.php` plus missing-field/unexpected-field assertions per event in `test-event-catalog.php`; removing or renaming a production field therefore breaks the independent acceptance fixture.

| Event | Catalog validator | Aggregate apply path | Named aggregate evidence |
|---|---|---|---|
| `ArchiveRequested` | `D-SCHEMA-ArchiveRequested` | `apply_archive_requested()` | `AC-01 ArchiveFinalized reaches FINALIZED`; `AC-02/03 a second archive request cannot create a concurrent active build`; `INV-01 Archive Case key cannot change after the first event` |
| `ArchiveBuildStarted` | `D-SCHEMA-ArchiveBuildStarted` | `apply_build_started()` | `AC-28 a second concurrent build attempt cannot advance the same revision`; `TRANSITION-RETRY-ORDINAL`; `TRANSITION-CANCELLED-BUILD-START`; `TRANSITION-FINALIZED-BUILD-START` |
| `EvidenceSnapshotCaptured` | `D-SCHEMA-EvidenceSnapshotCaptured` | `apply_snapshot_captured()` | `AC-05 retry after capture reuses the exact sealed snapshot`; `AC-17 evidence changed after review cannot be captured as approved`; `TRANSITION-CAPTURE-BEFORE-START`; `BIND-SNAPSHOT-*` |
| `LedgerMaterialized` | `D-SCHEMA-LedgerMaterialized` | `apply_materialized(..., ledger)` | `AC-01`; `TRANSITION-MATERIALIZE-BEFORE-SNAPSHOT` |
| `PacketMaterialized` | `D-SCHEMA-PacketMaterialized` | `apply_materialized(..., packet)` | `AC-01`; `BIND-PACKET-CERTIFICATES` |
| `ArchiveVerified` | `D-SCHEMA-ArchiveVerified` | `apply_verified()` | `ATOMIC-VERIFY-STANDALONE`; `BIND-VERIFY-REVISION/IDENTITY/PREDECESSOR`; `AC-14 invalid finalization batch fails closed` |
| `ArchiveFinalized` | `D-SCHEMA-ArchiveFinalized` | `apply_finalized()` | `AC-01`; `AC-07 finalization winner prevents cancellation`; `AC-12 replacement finalization supersedes named revoked predecessor`; `AC-23 replacement becomes sole active revision atomically`; `INV-05 finalization before capture/materialization/verification is prohibited` |
| `ArchiveFailed` | `D-SCHEMA-ArchiveFailed` | `apply_archive_failed()` | `TRANSITION-REQUESTED-FAILED`; `AC-06 verification-phase failure leaves a non-official FAILED revision`; `TRANSITION-FAILURE-PHASE`; `TRANSITION-FINALIZED-FAILED`; `TRANSITION-CANCELLED-FAILED`; `ATOMIC-PREFINAL-DRIFT-BINDINGS` |
| `ArchiveRetryRequested` | `D-SCHEMA-ArchiveRetryRequested` | `apply_retry_requested()` | `TRANSITION-REQUESTED-FAILED-RETRY`; `TRANSITION-RETRY-BEFORE-SNAPSHOT`; `TRANSITION-RETRY-SEALED-SNAPSHOT`; `TRANSITION-FAILED-VERIFYING`; `TRANSITION-TERMINAL-BUILD-FINALIZED/CANCELLED`; `DRIFT-BLOCKS-RETRY` |
| `ArchiveCancelled` | `D-SCHEMA-ArchiveCancelled` (nullable `build_attempt_id`) | `apply_archive_cancelled()` | `AC-07 a winning VERIFYING-stage cancellation prevents later finalization`; `AC-24 cancellation releases the build lock when no independent block remains`; `TRANSITION-MATERIALIZING-CANCELLED`; `TRANSITION-VERIFYING-CANCELLED`; `TRANSITION-FAILED-CANCELLED`; `T6-CANCEL-REQUESTED-NULL-ATTEMPT`; `T6-CANCEL-REQUESTED-FABRICATED-ATTEMPT`; `T6-CANCEL-ATTEMPT-MISMATCH`; `T6-CANCEL-ATTEMPT-REQUIRED`; `T6-CANCEL-ATTEMPT-EXACT`; `T5-DRIFT-BLOCKS-STANDALONE-CANCEL`; `T5-DRIFT-REBASE-ATOMIC` |
| `CorrectionRequested` | `D-SCHEMA-CorrectionRequested` | `apply_correction_requested()` | `INV-22 reset invalidation plus correction/revocation emits one complete batch`; `BIND-CORRECTION-SCOPE`; `AC-22 winning reset claim blocks ordinary correction`; `CORRECTION-AFTER-*`; `DRIFT-BLOCKS-CORRECTION` |
| `ArchiveRevoked` | `D-SCHEMA-ArchiveRevoked` | `apply_archive_revoked()` | `correction irreversibly revokes active archive`; `revocation cannot occur without correction batch`; `ATOMIC-CORRECTION-ORDER` |
| `ReplacementArchiveRequested` | `D-SCHEMA-ReplacementArchiveRequested` | `apply_archive_requested(..., predecessor)` | `AC-11 failed replacement leaves predecessor preserved/revoked and no active archive`; `AC-12`; `INV-12 lineage cycle rejected...`; `TRANSITION-SUPERSESSION-FORK` |
| `ResetRequested` | `D-SCHEMA-ResetRequested` | `apply_reset_requested()` + `assert_reset_scope_matches_case()` (binds `scope_digest` to the bound revision `subject_scope_digest`) | `AC-08-REQUESTED/CAPTURING/MATERIALIZING/VERIFYING/FAILED/CANCELLED`; `BIND-RESET-SCOPE-EMPLOYEE/PROGRAM/CYCLE`; `T1-RESET-COURSE-BROADENED/NARROWED/SUBSTITUTED/EXACT`; all three `AC-27` prohibitions |
| `ResetDeferred` | `D-SCHEMA-ResetDeferred` | `apply_reset_deferred()` | `AC-08 early reset can be explicitly DEFERRED`; `TRANSITION-DEFER-REQUIRES-BOUNDED-CONSENT`; `TRANSITION-DEFER-CONSENT-WINDOW`; `RESET-NULL-WINDOW-DEFER` |
| `ResetRejected` | `D-SCHEMA-ResetRejected` | `transition_reset()` | `AC-08 early reset can be terminally REJECTED`; `TRANSITION-DEFERRED-REJECTED` |
| `ResetCancelled` | `D-SCHEMA-ResetCancelled` | `transition_reset()` | `pending reset can be explicitly cancelled`; `TRANSITION-DEFERRED-CANCELLED`; `TRANSITION-AUTHORIZED-CANCELLED`; `RESET-CANCEL-PRESERVES-AUTHORIZATION` |
| `ResetAuthorized` | `D-SCHEMA-ResetAuthorized` | `apply_reset_authorized()` | `AC-09 reset authorization cannot be rebound to another scope`; `TRANSITION-DEFERRED-AUTHORIZED`; `TRANSITION-TERMINAL-RESET-*`; `RESET-CONSENT-WINDOW`; `BIND-RESET-SCOPE-COURSES`; `RESET-SINGLE-USE-NULL-WINDOW` |
| `ResetAuthorizationExpired` | `D-SCHEMA-ResetAuthorizationExpired` | `transition_reset_authorization()` | `unused reset authorization can expire only by event`; `RESET-EXPLICIT-EXPIRY`; `claimed reset cannot expire` |
| `ResetOperationInvalidated` | `D-SCHEMA-ResetOperationInvalidated` | `apply_reset_invalidated()` | `GRAMMAR-STANDALONE-INVALIDATION`; `TRANSITION-DEFERRED-INVALIDATED`; `RESET-INVALIDATION-PRESERVES-AUTHORIZATION`; `INCIDENT-ATOMIC-INVALIDATION`; `BIND-INCIDENT-INVALIDATION-REFERENCE` |
| `ResetExecutionClaimed` | `D-SCHEMA-ResetExecutionClaimed` | `apply_reset_claimed()` | `authorization cannot be consumed twice`; `RESET-CLAIM-GATEWAY`; `TRANSITION-CLAIM-BEFORE-EXPIRY/AT-EXPIRY/AFTER-EXPIRY`; `BIND-RESET-CLAIM-COURSES`; `GRAMMAR-CLAIM-WITH-OUTCOME` |
| `ResetCompleted` | `D-SCHEMA-ResetCompleted` | `transition_reset()` | `ResetCompleted applies after a durable claim`; `AC-27 no reset operation may follow COMPLETED`; `RESET-OUTCOME-UPSTREAM` |
| `ResetFailedSafe` | `D-SCHEMA-ResetFailedSafe` | `transition_reset()` | `ResetFailedSafe records conclusive no-change`; `RESET-FAILED-SAFE-SOURCE` |
| `ResetOutcomeBecameUncertain` | `D-SCHEMA-ResetOutcomeBecameUncertain` | `transition_reset()` | `AC-20 worker absence does not infer a reset outcome after claim`; `AC-10` reconciliations |
| `ResetReconciledAsCompleted` | `D-SCHEMA-ResetReconciledAsCompleted` | `transition_reset()` | `AC-10 uncertain reset can reconcile as completed`; `reset remediation may end as verified COMPLETED`; `claimed reset cannot reconcile before uncertainty event` |
| `ResetReconciledAsNoChange` | `D-SCHEMA-ResetReconciledAsNoChange` | `transition_reset()` | `AC-10 uncertain reset can reconcile as no change`; `RESET-RECONCILE-NO-CHANGE-SOURCE` |
| `ResetRemediationRequired` | `D-SCHEMA-ResetRemediationRequired` | `transition_reset()` + scope check | `AC-21 partial reset remains blocked in REMEDIATION_REQUIRED`; `RESET-REMEDIATION-SCOPE` |
| `ResetRemediatedRestored` | `D-SCHEMA-ResetRemediatedRestored` | `assert_reset_remediation()` + `transition_reset()` | `INV-27 partial reset restoration is not mislabeled FAILED_SAFE`; `AC-27 no reset operation may follow REMEDIATED_RESTORED`; `CORRECTION-AFTER-REMEDIATED-RESET` |
| `SourceDriftDetected` | `D-SCHEMA-SourceDriftDetected` | fingerprint/snapshot checks + `open_incident(source_drift)` + `assert_drift_detection_context()` | `ATOMIC-PREFINAL-DRIFT-FAILURE/ORDER/BINDINGS`; `INCIDENT-DETECTION-EXPECTED-FINGERPRINT`; `DETECTION-POINT-FINALIZED/CANDIDATE/PRE-CLAIM`; `INCIDENT-DOUBLE-OPEN`; `AC-17/AC-18` |
| `SourceDriftResolved` | `D-SCHEMA-SourceDriftResolved` | `resolve_source_drift()` + rebase grammar | `INCIDENT-RESTORATION-PROOF`; `INCIDENT-REPLACEMENT-REBASE standalone rebase resolution is prohibited`; `DRIFT-RECOVERY-RESTORED/REBASE-CANDIDATE/REBASE-FINALIZED/MISSING-CANCELLATION/MISSING-REQUEST/WRONG-FINGERPRINT/WRONG-REFERENCE/FRAGMENTED`; `source drift cannot resolve without an open incident` |
| `UnprotectedResetDetected` | `D-SCHEMA-UnprotectedResetDetected` | `assert_incident_scope_matches_case()` (binds to active, current, correction-target, or newest retained cancelled-revision `subject_scope_digest`) + `open_incident(unprotected_reset)` | `AC-15` dismissal/confirmation flow; `BIND-INCIDENT-SCOPE`; `T1-INCIDENT-COURSE`; `T1-INCIDENT-COURSE-PREFINALIZATION`; `T1-INCIDENT-COURSE-CORRECTION-TARGET`; `T1-INCIDENT-COURSE-CANCELLED-RETAINED/REPLAY/RECORDED-REPLAY` |
| `UnprotectedResetDismissed` | `D-SCHEMA-UnprotectedResetDismissed` | `resolve_unprotected_reset(false)` | `unprotected-reset incident can be dismissed only by event`; `INCIDENT-UNPROTECTED-DISMISSAL-PROOF`; `INCIDENT-RESOLUTION-REQUIRES-OPEN` |
| `UnprotectedResetConfirmed` | `D-SCHEMA-UnprotectedResetConfirmed` | `resolve_unprotected_reset(true)` | `confirmed out-of-band reset remains explicit and fail-closed`; `AC-27 no reset operation may follow a confirmed out-of-band reset`; `CORRECTION-AFTER-OUT-OF-BAND-RESET` |
| `IntegrityViolationDetected` | `D-SCHEMA-IntegrityViolationDetected` | `open_incident(integrity)` | `INV-15 blocking incident cannot open while authorization exists without atomic reset invalidation`; `INCIDENT-ATOMIC-INVALIDATION` |
| `IntegrityIncidentDispositionRecorded` | `D-SCHEMA-IntegrityIncidentDispositionRecorded` | `resolve_integrity()` + monotonic `integrity_compromise_confirmed` + accumulating `integrity_remaining_restrictions` | `integrity incident disposition is replayable and history-preserving`; `INTEGRITY-RESTRICTIONS-PERSISTED/FAIL-CLOSED`; `T2-RESTRICTION-PERSISTS`; `T2-RESTRICTION-REMAINS-EFFECTIVE`; `T2-LEGITIMATE-RESOLUTION`; `T2-COMPROMISE-PERSISTS`; `T2-COMPROMISE-THEN-FALSE-POSITIVE`; `T2-REPLAY-BLOCKING`; `INTEGRITY-COMPROMISE-REMAINS-BLOCKED`; `INTEGRITY-RESOLUTION-REQUIRES-OPEN` |

## Named production aggregate operations

Generic `record()`/`record_batch()` is test-gated (`GHCA_ACD_ARCHIVE_TESTING`) and used only for deliberately malformed shapes. Production decisions use one named operation per approved business decision on `GHCA_ACD_Archive_Case`:

`request_archive`, `request_replacement_archive`, `start_build`, `capture_evidence_snapshot`, `materialize_ledger`, `materialize_packet`, `verify_and_finalize`, `fail_archive`, `request_retry`, `cancel_archive`, `correct`, `request_reset`, `defer_reset`, `reject_reset`, `cancel_reset`, `authorize_reset`, `expire_reset_authorization`, `claim_reset_execution`, `complete_reset`, `record_reset_failed_safe`, `record_reset_outcome_uncertain`, `reconcile_reset_as_completed`, `reconcile_reset_as_no_change`, `require_reset_remediation`, `record_reset_remediated_restored`, `detect_source_drift`, `resolve_source_drift_restored`, `resolve_source_drift_rebased`, `detect_unprotected_reset`, `dismiss_unprotected_reset`, `confirm_unprotected_reset`, `detect_integrity_violation`, `record_integrity_disposition`.

Invalidation is not a standalone operation: it commits only inside `correct()`, `detect_source_drift()`, `detect_unprotected_reset()`, or `detect_integrity_violation()` (`GRAMMAR-STANDALONE-INVALIDATION`).

## Closed atomic decision grammar

`validate_batch_contract()` dispatches every decision to exactly one approved shape; unlisted combinations reject with `unapproved_decision_shape`. Identical validation runs during authoritative replay (`replay_semantic_decisions()`).

| Decision shape | Ordered cardinality | Validator | Named tests |
|---|---|---|---|
| Single event | 1 (with per-type prohibitions) | `validate_single_decision()` | `ATOMIC-VERIFY-STANDALONE`; `revocation cannot occur without correction batch`; `GRAMMAR-STANDALONE-INVALIDATION`; `ATOMIC-PREFINAL-DRIFT-FAILURE`; `INCIDENT-REPLACEMENT-REBASE standalone rebase resolution is prohibited` |
| Verification+finalization | exactly `[ArchiveVerified, ArchiveFinalized]` | `validate_finalization_decision()` | `ATOMIC-FINALIZATION-ORDER/SURPLUS/BINDINGS`; `AC-14`; `A-STREAM-10` |
| Correction | `[ResetOperationInvalidated*, CorrectionRequested, ArchiveRevoked]` | `validate_correction_decision()` + `assert_correction_pairing()` | `ATOMIC-CORRECTION-ORDER`; `ATOMIC-CORRECTION-INVALIDATION-LIST/REFERENCE`; `GRAMMAR-EXTRA-EVENT`; `active_reset_requires_invalidation` cases |
| Drift detection | `[ResetOperationInvalidated*, SourceDriftDetected, ArchiveFailed?]` (failure mandatory pre-finalization) | `validate_drift_detection_decision()` | `ATOMIC-PREFINAL-DRIFT-FAILURE/ORDER/BINDINGS`; `ATOMIC-TRIAL-BATCH-RESET-THEN-INCIDENT`; `DETECTION-POINT-*` |
| Drift rebase recovery | `[SourceDriftResolved(replacement_rebased), (CorrectionRequested, ArchiveRevoked \| ArchiveCancelled)?, ArchiveRequested \| ReplacementArchiveRequested]` | `validate_drift_rebase_decision()` | `DRIFT-RECOVERY-REBASE-CANDIDATE/REBASE-FINALIZED/MISSING-CANCELLATION/MISSING-REQUEST/WRONG-FINGERPRINT/WRONG-REFERENCE/FRAGMENTED` |
| Incident detection | `[ResetOperationInvalidated*, UnprotectedResetDetected \| IntegrityViolationDetected]` | `validate_incident_decision()` | `GRAMMAR-INCIDENT-EXTRA`; `INCIDENT-ATOMIC-INVALIDATION`; `BIND-INCIDENT-INVALIDATION-REFERENCE`; `INV-15` |
| Everything else | fails closed | dispatcher | `GRAMMAR-ARBITRARY-BATCH`; `GRAMMAR-CLAIM-WITH-OUTCOME`; `ATOMIC-REPLAY-MALFORMED-BATCH`; `DRIFT-RECOVERY-FRAGMENTED` |

Multi-event decisions additionally verify one command identity, actor/authority, and correlation across the batch during authoritative replay (`A-BATCH-PROVENANCE`, positive control `A-STREAM-13`).

## Command contracts

One closed contract/factory exists per approved business command in `GHCA_ACD_Archive_Command`; caller intent carries only caller-controlled facts and server facts carry server-generated/server-resolved values. `GHCA_ACD_Archive_Client_Intent` performs the exact caller-field contract plus shared event-catalog fragment and cross-field validation before any digest or server fact exists. Named `B-CLIENT-INTENT-*` regressions cover nested case identity, enums, reset scope/consent, materialization kind, failure phase/attempt coherence, drift detection/restoration kind, integrity exclusivity, and payload bounds. Every command has `B-CMD-<Type>` and `B-RETRY-<Type>` evidence; retry recognition uses caller intent only. For every command with non-empty server facts, `B-SERVER-FACTS-<Type>` changes only those facts and proves an unchanged client-intent digest but a changed accepted-command digest. `B-SPLIT-<Type>` applies only where server facts exist. Commands with empty server facts use an explicit canonical `{}` (`B-EMPTY-SERVER-FACTS`, `B-EMPTY-SERVER-FACTS-NOT-LIST`).

| Command | Event decision | Caller intent | Server facts |
|---|---|---|---|
| `RequestArchive` | `ArchiveRequested` | case_key, request_kind | archive_id, revision_number, resolved_cycle, reviewed fingerprint, policy digest, subject scope digest |
| `RequestReplacementArchive` | `ReplacementArchiveRequested` | case_key, revoked predecessor ID | archive_id, revision_number, resolved_cycle, reviewed fingerprint, policy digest, subject scope digest |
| `StartBuild` | `ArchiveBuildStarted` | archive_id | build_attempt_id, start_phase, retry_ordinal, snapshot_id |
| `RecordEvidenceSnapshot` | `EvidenceSnapshotCaptured` | archive_id | snapshot identity/digest/bytes, fingerprints, completeness/policy, cycle, certificate manifest, subject scope digest |
| `RecordMaterializedArtifact` | `LedgerMaterialized` or `PacketMaterialized` | archive_id, artifact_kind | per-kind artifact identity/digests (closed variants) |
| `VerifyAndFinalize` | `[ArchiveVerified, ArchiveFinalized]` | archive_id | verified + finalized documents (one command identity) |
| `FailArchive` | `ArchiveFailed` | full observed failure facts | — |
| `RetryArchive` | `ArchiveRetryRequested` | archive_id | prior/new attempt IDs, resume phase, sealed snapshot |
| `CancelArchive` | `ArchiveCancelled` | archive_id, cancellation_reason | build_attempt_id, retained-candidate disposition |
| `RequestCorrection` | `[ResetOperationInvalidated*, CorrectionRequested, ArchiveRevoked]` | target_archive_id, reason_code | correction_operation_id, target_snapshot_id, affected scope digest, invalidation list |
| `RequestReset` | `ResetRequested` | bound_archive_id, scope, consent_mode, request_valid_until_gmt | reset_operation_id, scope_digest, snapshot_id |
| `DeferReset` | `ResetDeferred` | reset_operation_id | condition code, reevaluation deadline, consent expiry |
| `RejectReset` | `ResetRejected` | reset_operation_id | rejection code, safe explanation |
| `CancelReset` | `ResetCancelled` | reset_operation_id, cancellation_reason | authorization_id |
| `AuthorizeReset` | `ResetAuthorized` | reset_operation_id | authorization/archive/snapshot/scope/gateway/times/fingerprint |
| `ExpireResetAuthorization` | `ResetAuthorizationExpired` | reset_operation_id | authorization_id, scheduled expiry, observed time, expiry policy |
| `ClaimResetExecution` | `ResetExecutionClaimed` | reset_operation_id | authorization/gateway/upstream/scope/fingerprint/claim time |
| `CompleteReset` | `ResetCompleted` | full observed outcome facts | — |
| `RecordResetFailedSafe` | `ResetFailedSafe` | full observed no-change proof | — |
| `RecordResetOutcomeUncertain` | `ResetOutcomeBecameUncertain` | full observed uncertainty facts | — |
| `ReconcileResetAsCompleted` | `ResetReconciledAsCompleted` | full reconciliation proof | — |
| `ReconcileResetAsNoChange` | `ResetReconciledAsNoChange` | full reconciliation proof | — |
| `RequireResetRemediation` | `ResetRemediationRequired` | operation/upstream IDs, affected scope, evidence | remediation_case_id |
| `RecordResetRemediatedRestored` | `ResetRemediatedRestored` | full restoration proof | — |
| `DetectSourceDrift` | `[ResetOperationInvalidated*, SourceDriftDetected, ArchiveFailed?]` | archive_id, observed fingerprint, detection point, changed components | incident_id, snapshot_id, expected fingerprint, failure document, invalidation list |
| `ResolveSourceDriftRestored` | `SourceDriftResolved` (restored only; `B-CMD-DRIFT-KIND`) | incident/resolution facts | — |
| `RebaseSourceDriftRecovery` | rebase recovery decision | incident_id | resolved/cancellation/correction/revocation/request documents + request_type |
| `DetectUnprotectedReset` | `[ResetOperationInvalidated*, UnprotectedResetDetected]` | detector/probe/observed fingerprint/scope | incident_id, before fingerprint, invalidation list |
| `DismissUnprotectedReset` | `UnprotectedResetDismissed` | full dismissal proof | — |
| `ConfirmUnprotectedReset` | `UnprotectedResetConfirmed` | full confirmation facts | — |
| `DetectIntegrityViolation` | `[ResetOperationInvalidated*, IntegrityViolationDetected]` | target/verifier/containment facts | incident_id, invalidation list |
| `RecordIntegrityDisposition` | `IntegrityIncidentDispositionRecorded` | full disposition facts | — |

Idempotency/response-loss evidence: `B-IDEMP-01/01B` isolate dedupe identity and caller-intent recognition; `B-IDEMP-02A` changes only command ID, `B-IDEMP-02B` only expected sequence, and `B-IDEMP-02C` only server facts; `B-IDEMP-03/03B` distinguish conflicting caller intent. `B-CANON-01` proves `canonical_format_version` participates in the accepted command document. `B-AUTH-<Type>` and `A-AUTH-<EventType>` bind every effective direct or structured subject scope to actor authority, including nested rebase request/correction documents; matching positive controls precede the negative cases.

## Architecture invariants

| Invariant | Slice 1A evidence | Boundary/status |
|---|---|---|
| INV-01 one immutable case key per stream | `A-STREAM-07/08`; `INV-01 Archive Case key cannot change after the first event`; `D-NESTED-01/02/03` | Slice 1A pure-domain coverage |
| INV-02 at most one active revision | `INV-02 finalized revision is the sole ACTIVE revision`; `AC-23 replacement becomes sole active revision atomically` | Slice 1A pure-domain coverage |
| INV-03 both layers from one snapshot | `BIND-PACKET-CERTIFICATES`; `ATOMIC-FINALIZATION-BINDINGS`; `BIND-STATE-CERT` | Slice 1A pure-domain coverage |
| INV-04 new snapshot means new revision | `BIND-SNAPSHOT-REVISION`; `AC-05 retry after capture reuses the exact sealed snapshot`; `TRANSITION-RETRY-SEALED-SNAPSHOT` | Slice 1A pure-domain coverage |
| INV-05 finalization requires all evidence and verification | `INV-05 finalization before capture/materialization/verification is prohibited`; `ATOMIC-VERIFY-STANDALONE`; `ATOMIC-FINALIZATION-ORDER/SURPLUS/BINDINGS` | Slice 1A pure-domain coverage |
| INV-06 candidate artifacts never authorize reset | `AC-08 deferred reset cannot authorize before finalized active snapshot`; `AC-08-REQUESTED/CAPTURING/MATERIALIZING/VERIFYING/FAILED/CANCELLED` | Slice 1A pure-domain coverage |
| INV-07 authorization binds one active revision/scope | `AC-09 reset authorization cannot be rebound to another scope`; `RESET-STATE-SCOPE/GATEWAY/AUTH-WINDOW`; `BIND-RESET-SCOPE-COURSES` | Slice 1A pure-domain coverage |
| INV-08 authorization single-use | `authorization cannot be consumed twice`; `RESET-CANCEL/INVALIDATION-PRESERVES-AUTHORIZATION`; `RESET-OUTCOME-UPSTREAM` | Slice 1A pure-domain coverage |
| INV-09 revoked never active again | `correction irreversibly revokes active archive`; `AC-12 replacement finalization supersedes named revoked predecessor`; `TRANSITION-SUPERSESSION-FORK` | Slice 1A pure-domain coverage |
| INV-10 replacement activation/supersession atomic | `AC-12`; `AC-23`; `ATOMIC-FINALIZATION-ORDER` | Slice 1A pure-domain coverage |
| INV-11 corrections append; never mutate prior evidence | `INV-22 reset invalidation plus correction/revocation emits one complete batch`; `ATOMIC-CORRECTION-ORDER`; `A-STREAM-10` | Aggregate append-only evidence covered; durable storage is Slice 1B |
| INV-12 lineage stays in one case and acyclic | `INV-12 lineage cycle rejected: a superseded ancestor cannot become a replacement predecessor again`; `BIND-VERIFY-PREDECESSOR`; `TRANSITION-SUPERSESSION-FORK` | Slice 1A pure-domain coverage |
| INV-13 every append uses expected version | `GHCA_ACD_Archive_Command` freezes expected sequence in the frozen command digest (`B-IDEMP-02B`) | Database row-lock/expected-version enforcement is Slice 1B |
| INV-14 duplicate commands yield one outcome | `B-IDEMP-01/01B/02/03/03B`; `B-RETRY-<Type>` for all 32 contracts; `B-GOLDEN-01/02` | Receipt-first persistence remains Slice 1B |
| INV-15 uncertainty fails closed | `INV-15 blocking incident cannot open while authorization exists without atomic reset invalidation`; `INCIDENT-BLOCKS-RESET-REQUEST`; `AC-18 source drift after finalization blocks reset request` | Slice 1A pure-domain coverage |
| INV-16 projections rebuild from events | `AC-13 deterministic replay reproduces identical aggregate state`; `A-STREAM-01/02/13` | Database projector rebuild remains Slice 1B |
| INV-17 failed reset does not change archive validity | `ResetFailedSafe records conclusive no-change`; `AC-10 uncertain reset can reconcile as no change`; `RESET-FAILED-SAFE-SOURCE` | Slice 1A pure-domain coverage |
| INV-18 out-of-band reset never fabricates authorization | `confirmed out-of-band reset remains explicit and fail-closed`; `CORRECTION-AFTER-OUT-OF-BAND-RESET` | Slice 1A pure-domain coverage |
| INV-19 reviewed fingerprint must match capture | `BIND-SNAPSHOT-REVIEWED/CAPTURED`; `AC-17`; `ATOMIC-PREFINAL-DRIFT-*` | Slice 1A pure-domain coverage |
| INV-20 authorization/claim verify source agreement | `AC-09`; `RESET-FAILED-SAFE-SOURCE`; `RESET-RECONCILE-NO-CHANGE-SOURCE`; `INCIDENT-DETECTION-EXPECTED-FINGERPRINT` | Slice 1A pure-domain coverage |
| INV-21 claim precedes side effects | `GRAMMAR-CLAIM-WITH-OUTCOME`; `ATOMIC-REPLAY-MALFORMED-BATCH`; `AC-20 worker absence does not infer a reset outcome after claim` | Kernel claim/outcome ordering covered; gateway execution is later slice |
| INV-22 correction/drift invalidates pre-claim reset | `ATOMIC-CORRECTION-INVALIDATION-LIST/REFERENCE`; `DETECTION-POINT-PRE-CLAIM`; `INCIDENT-ATOMIC-INVALIDATION`; `TRANSITION-DEFERRED-INVALIDATED` | Slice 1A pure-domain coverage |
| INV-23 one non-terminal reset; no reset after destructive outcome | `AC-27 no reset operation may follow COMPLETED`; `AC-27 no reset operation may follow REMEDIATED_RESTORED`; `AC-27 no reset operation may follow a confirmed out-of-band reset`; `TRANSITION-TERMINAL-RESET-*` | Slice 1A pure-domain coverage |
| INV-24 uncertain reset cannot be destructively retried | `AC-10` reconciliations; `AC-21 partial reset remains blocked in REMEDIATION_REQUIRED` | Slice 1A pure-domain coverage |
| INV-25 open incident dimensions fail closed | `INCIDENT-BLOCKS-RESET-REQUEST`; `DRIFT-BLOCKS-RETRY/BUILD-START/CAPTURE/CORRECTION`; `INTEGRITY-RESTRICTIONS-FAIL-CLOSED`; `INTEGRITY-COMPROMISE-REMAINS-BLOCKED` | Slice 1A pure-domain coverage |
| INV-26 idempotency stores scope and canonical command digest | `B-IDEMP-01/01B/02/03/03B`; `B-GOLDEN-01/02`; `B-CANON-01` | Durable receipt storage is Slice 1B |
| INV-27 remediated partial reset is not failed-safe | `INV-27 partial reset restoration is not mislabeled FAILED_SAFE`; `CORRECTION-AFTER-REMEDIATED-RESET`; `remediation_mismatch` identity checks | Slice 1A pure-domain coverage |
| INV-28 time/worker death does not infer lifecycle | `AC-20`; `RESET-EXPLICIT-EXPIRY`; `TRANSITION-CLAIM-AT-EXPIRY`; static `clock/random hits = 0` purity scan | Slice 1A pure-domain coverage |

## Transition matrix (Architecture PRD Section 6)

Permitted build edges and named evidence:

| Edge | Named test |
|---|---|
| REQUESTED→CAPTURING | `AC-01 ArchiveFinalized reaches FINALIZED` (full chain); matrix builders |
| REQUESTED→FAILED | `TRANSITION-REQUESTED-FAILED` |
| REQUESTED→CANCELLED | `AC-08-CANCELLED` builder; `TRANSITION-TERMINAL-BUILD-CANCELLED` builder |
| CAPTURING→MATERIALIZING | `AC-01`; `BIND-SNAPSHOT-*` positive path |
| CAPTURING→FAILED | `FAILED can retry explicitly and a non-finalized retry can cancel` |
| CAPTURING→CANCELLED | `FAILED can retry explicitly and a non-finalized retry can cancel` (cancel from restarted CAPTURING) |
| MATERIALIZING→VERIFYING | `AC-01` (both layers) |
| MATERIALIZING→FAILED | `AC-05` flow; `TRANSITION-RETRY-SEALED-SNAPSHOT` builder |
| MATERIALIZING→CANCELLED | `TRANSITION-MATERIALIZING-CANCELLED` |
| VERIFYING→FINALIZED | `AC-01` |
| VERIFYING→FAILED | `AC-06 verification-phase failure leaves a non-official FAILED revision` |
| VERIFYING→CANCELLED | `TRANSITION-VERIFYING-CANCELLED`; `AC-07 race precondition` |
| FAILED→CAPTURING | `TRANSITION-REQUESTED-FAILED-RETRY`; `FAILED can retry explicitly...` |
| FAILED→MATERIALIZING | `AC-05 retry after capture reuses the exact sealed snapshot` |
| FAILED→VERIFYING | `TRANSITION-FAILED-VERIFYING` |
| FAILED→CANCELLED | `TRANSITION-FAILED-CANCELLED` |

Prohibited build edges and named evidence: `INV-05` (finalize before verify), `AC-07 finalization winner prevents cancellation` (FINALIZED→CANCELLED), `TRANSITION-FINALIZED-BUILD-START`, `TRANSITION-FINALIZED-FAILED`, `TRANSITION-CANCELLED-BUILD-START`, `TRANSITION-CANCELLED-FAILED`, `TRANSITION-TERMINAL-BUILD-FINALIZED/CANCELLED` (retry), `TRANSITION-CAPTURE-BEFORE-START`, `TRANSITION-MATERIALIZE-BEFORE-SNAPSHOT`, `retry_required` (`failed revision cannot restart without an explicit retry request`), `TRANSITION-RETRY-ORDINAL`, `TRANSITION-RETRY-BEFORE-SNAPSHOT`, `TRANSITION-RETRY-SEALED-SNAPSHOT`, `AC-28`.

Validity edges: NOT_APPLICABLE→ACTIVE (`AC-01`/`INV-02`), ACTIVE→REVOKED (`correction irreversibly revokes active archive`), REVOKED→SUPERSEDED (`AC-12`), terminal SUPERSEDED (`INV-12 lineage cycle rejected...`); revoked-never-active is structurally enforced by `replacement_required`/`invalid_predecessor` (`TRANSITION-SUPERSESSION-FORK`, `INV-12`).

Permitted reset edges: NONE→REQUESTED (`AC-08-*` accepted), REQUESTED→DEFERRED (`AC-08 early reset can be explicitly DEFERRED`), REQUESTED→REJECTED (`AC-08 early reset can be terminally REJECTED`), REQUESTED→CANCELLED (`pending reset can be explicitly cancelled`), REQUESTED→INVALIDATED (`TRANSITION-TERMINAL-RESET-INVALIDATED` builder), REQUESTED→AUTHORIZED (`authorized_reset_case` flows), DEFERRED→AUTHORIZED/REJECTED/CANCELLED/INVALIDATED (`TRANSITION-DEFERRED-AUTHORIZED/REJECTED/CANCELLED/INVALIDATED`), AUTHORIZED→CLAIMED (`TRANSITION-CLAIM-BEFORE-EXPIRY`), AUTHORIZED→EXPIRED (`unused reset authorization can expire only by event`), AUTHORIZED→INVALIDATED (`correction invalidates unused authorization`), AUTHORIZED→CANCELLED (`TRANSITION-AUTHORIZED-CANCELLED`), CLAIMED→COMPLETED (`ResetCompleted applies after a durable claim`), CLAIMED→FAILED_SAFE (`ResetFailedSafe records conclusive no-change`), CLAIMED→OUTCOME_UNKNOWN (`AC-10` flows), OUTCOME_UNKNOWN→COMPLETED/FAILED_SAFE/REMEDIATION_REQUIRED (`AC-10`, `AC-21`), REMEDIATION_REQUIRED→COMPLETED (`reset remediation may end as verified COMPLETED`), REMEDIATION_REQUIRED→REMEDIATED_RESTORED (`INV-27`).

Prohibited reset edges: `authorization cannot be consumed twice`, `claimed reset cannot expire`, `claimed reset cannot reconcile before uncertainty event`, `TRANSITION-TERMINAL-RESET-REJECTED/CANCELLED/EXPIRED/INVALIDATED`, `TRANSITION-CLAIM-AT-EXPIRY`, `TRANSITION-CLAIM-AFTER-EXPIRY`, `TRANSITION-DEFER-REQUIRES-BOUNDED-CONSENT`, `TRANSITION-DEFER-CONSENT-WINDOW`, `RESET-NULL-WINDOW-DEFER`, all three `AC-27` prohibitions, `GRAMMAR-STANDALONE-INVALIDATION`.

Incident edges: open/resolve for each dimension per the event table above; `INCIDENT-DOUBLE-OPEN`, `INCIDENT-RESOLUTION-REQUIRES-OPEN`, `INTEGRITY-RESOLUTION-REQUIRES-OPEN`, `source drift cannot resolve without an open incident` cover the prohibited edges.

## Acceptance scenarios

| Scenario | Slice 1A evidence | Later-slice boundary |
|---|---|---|
| AC-01 | `AC-01 ArchiveFinalized reaches FINALIZED`; `INV-02`; `ArchiveFinalized establishes active archive identity` | Real integrations remain disabled |
| AC-02 | `AC-02/03 a second archive request cannot create a concurrent active build`; `AC-02 rejected duplicate request leaves aggregate unchanged`; `B-IDEMP-01/01B` | Receipt-first stored response is Slice 1B |
| AC-03 | one active-build aggregate guard; expected sequence frozen in command digest (`B-IDEMP-02B`) | Two-connection race is Slice 1B |
| AC-04 | `FAILED can retry explicitly and a non-finalized retry can cancel`; `TRANSITION-REQUESTED-FAILED-RETRY` | Real integrations remain disabled |
| AC-05 | `AC-05 retry after capture reuses the exact sealed snapshot`; `TRANSITION-RETRY-SEALED-SNAPSHOT` | Real integrations remain disabled |
| AC-06 | `AC-06 verification-phase failure leaves a non-official FAILED revision` | Real integrations remain disabled |
| AC-07 | `AC-07 race precondition: the candidate revision is VERIFYING`; `AC-07 a winning VERIFYING-stage cancellation prevents later finalization`; `AC-07 finalization winner prevents cancellation` | Expected-version race is Slice 1B |
| AC-08 | `AC-08-REQUESTED/CAPTURING/MATERIALIZING/VERIFYING/FAILED/CANCELLED` (accepted + never authorized + terminal decision); `AC-08 early reset can be explicitly DEFERRED/terminally REJECTED` | Real integrations remain disabled |
| AC-09 | `AC-09 reset authorization cannot be rebound to another scope`; `BIND-RESET-SCOPE-COURSES`; `BIND-RESET-CLAIM-COURSES` | Real integrations remain disabled |
| AC-10 | `AC-10 uncertain reset can reconcile as completed/as no change`; `RESET-RECONCILE-NO-CHANGE-SOURCE` | Real integrations remain disabled |
| AC-11 | `AC-11 failed replacement leaves predecessor preserved/revoked and no active archive` | Real integrations remain disabled |
| AC-12 | `AC-12 replacement finalization supersedes named revoked predecessor` | Real integrations remain disabled |
| AC-13 | `AC-13 deterministic replay reproduces identical aggregate state`; `A-STREAM-13` | Projection-table rebuild is Slice 1B |
| AC-14 | `AC-14 invalid finalization batch fails closed`; `AC-14 failed multi-event decision commits no partial verification event` | Real transaction rollback/failure injection is Slice 1B |
| AC-15 | `unprotected-reset incident can be dismissed only by event`; `confirmed out-of-band reset remains explicit and fail-closed`; `BIND-INCIDENT-SCOPE`; retained-candidate live, uncommitted-replay, and authoritative recorded-replay scope checks (`T1-INCIDENT-COURSE-CANCELLED-*`) | Detector integration is later |
| AC-16 | all named `A-TAMPER` cases; `A-STREAM-03` through `A-STREAM-12` | Artifact/database verification is later |
| AC-17 | `AC-17 evidence changed after review cannot be captured as approved`; `BIND-SNAPSHOT-REVIEWED/CAPTURED` | Real integrations remain disabled |
| AC-18 | `AC-18 source drift after finalization blocks reset request`; `INCIDENT-BLOCKS-RESET-REQUEST` | Real integrations remain disabled |
| AC-19 | `unused reset authorization can expire only by event`; `RESET-EXPLICIT-EXPIRY`; `TRANSITION-DEFERRED-INVALIDATED`; `TRANSITION-CLAIM-AT/AFTER-EXPIRY` | Expiry scheduling is Slice 1B+ |
| AC-20 | `AC-20 worker absence does not infer a reset outcome after claim` | Watchdog is later slice |
| AC-21 | `AC-21 partial reset remains blocked in REMEDIATION_REQUIRED`; `RESET-REMEDIATION-SCOPE`; `INV-27` | Real integrations remain disabled |
| AC-22 | `AC-22 winning reset claim blocks ordinary correction`; `AC-22 winning correction invalidates and blocks reset claim` | Expected-version race is Slice 1B |
| AC-23 | `AC-23 replacement becomes sole active revision atomically` | Real integrations remain disabled |
| AC-24 | `AC-24 cancellation releases the build lock when no independent block remains` | Real integrations remain disabled |
| AC-25 | `AC-25 same idempotency identity with different intent has a different command digest`; `B-IDEMP-03/03B` | Stored conflict response is Slice 1B |
| AC-26 | `A-STREAM-03` (sequence must begin at one); `A-STREAM-06` (gap); `A-STREAM-04` (duplicate); `A-STREAM-05` (reorder); `A-STREAM-09` (predecessor mismatch); `A-STREAM-11/12` (duplicate event_id) | Projector quarantine/gap detection is Slice 1B |
| AC-27 | `AC-27 no reset operation may follow COMPLETED`; `AC-27 no reset operation may follow REMEDIATED_RESTORED`; `AC-27 no reset operation may follow a confirmed out-of-band reset` | Real integrations remain disabled |
| AC-28 | `AC-28 a second concurrent build attempt cannot advance the same revision` | Stream concurrency is Slice 1B |

## Second-pass remediation findings

| Finding | Implementation | Named regression evidence |
|---|---|---|
| R1 named production command/aggregate API | 33 named operations on `GHCA_ACD_Archive_Case` (test-gated generic recording retained for malformed fixtures); 32 closed command factories on `GHCA_ACD_Archive_Command` | All lifecycle tests migrated off `record()`; `B-CMD-<Type>` for all 32 contracts; `GRAMMAR-STANDALONE-INVALIDATION` |
| R2 caller-intent/response-loss semantics | caller/server split per closed contract; server-generated IDs and fingerprint/policy facts in server facts; `canonical_format_version` in client-intent and command documents | `B-RETRY-<Type>` for all 32 contracts; `B-IDEMP-01/01B/02A/02B/02C/03/03B`; `B-SPLIT-<Type>` where applicable; `B-SERVER-FACTS-<Type>` where applicable; `B-CANON-01`; frozen `B-GOLDEN-01/02` |
| R3 command provenance and authority | `GHCA_ACD_Archive_Event_Types::command_originated()` classification; complete command identity required in `validate_recording_context()`; shared effective-scope extraction for direct digests, structured reset scope, and nested rebase request/correction documents; batch provenance check in `replay_semantic_decisions()` | `A-CMD-01/02/03/04`; matching and foreign `A-AUTH-<EventType>` cases; `B-AUTH-<Type>` including nested rebase correction control/rejection; `A-BATCH-PROVENANCE`; `A-STREAM-13`; replaced `A-GOLDEN-03` |
| R4 closed atomic decision grammar | `validate_batch_contract()` dispatcher + per-shape validators; identical replay validation | `GRAMMAR-ARBITRARY-BATCH`; `GRAMMAR-CLAIM-WITH-OUTCOME`; `GRAMMAR-EXTRA-EVENT`; `GRAMMAR-INCIDENT-EXTRA`; `ATOMIC-REPLAY-MALFORMED-BATCH`; `A-STREAM-10` |
| R5 destructive/incident scope binding | `assert_reset_scope_matches_case()`; `assert_incident_scope_matches_case()`; correction `affected_scope_digest` check; `assert_incident_invalidations()` reference binding; `assert_drift_detection_context()`; retained `integrity_remaining_restrictions` in `has_open_block()` | `BIND-RESET-SCOPE-EMPLOYEE/PROGRAM/CYCLE`; `BIND-RESET-SCOPE-COURSES`; `BIND-RESET-CLAIM-COURSES`; `BIND-INCIDENT-SCOPE`; `BIND-CORRECTION-SCOPE`; `BIND-INCIDENT-INVALIDATION-REFERENCE`; `DETECTION-POINT-FINALIZED/CANDIDATE/PRE-CLAIM`; `INTEGRITY-RESTRICTIONS-PERSISTED/FAIL-CLOSED/CLEARED`; `INTEGRITY-COMPROMISE-REMAINS-BLOCKED` |
| R6 reset timing/consent semantics | strict `claimed_at_gmt < expires_at_gmt`; nullable `request_valid_until_gmt` with `bounded_reevaluation` window requirement; null-guarded window comparisons | `TRANSITION-CLAIM-BEFORE-EXPIRY/AT-EXPIRY/AFTER-EXPIRY`; `RESET-SINGLE-USE-NULL-WINDOW`; `RESET-BOUNDED-REQUIRES-WINDOW`; `RESET-NULL-WINDOW-DEFER` |
| R7 source-drift behavior | rebase requires the recovery decision (`resolve_source_drift_rebased()`/`validate_drift_rebase_decision()`); `assert_no_open_drift()` blocks build start/capture/materialization/retry; correction and reset blocked via `has_open_block()` | `INCIDENT-REPLACEMENT-REBASE standalone rebase resolution is prohibited`; `DRIFT-RECOVERY-RESTORED/REBASE-CANDIDATE/REBASE-FINALIZED/MISSING-CANCELLATION/MISSING-REQUEST/WRONG-FINGERPRINT/WRONG-REFERENCE/FRAGMENTED`; `DRIFT-BLOCKS-RETRY/BUILD-START/CAPTURE/CORRECTION` |
| R8 identity/stream integrity | `GHCA_ACD_Archive_Cycle` policy whitelist (`calendar_year`, `employee_start_date`); duplicate `event_id` rejection in `GHCA_ACD_Archive_Event_Stream_Verifier`; BIGINT UNSIGNED decimal-string bound in event envelope, actor, case key, catalog, and reset scope | `CYCLE-POLICY-01/02/03`; `A-STREAM-11/12`; `A-SEQ-OVERFLOW`; `A-GOLDEN-03` (18446744073709551615 / 9223372036854775808 vectors); `BIGINT-BOUNDARY-*` boundary/overflow checks; `CJSON-INT-OVERFLOW/UNDERFLOW` |
| R9 test oracle and transition coverage | `archive_expect_exception()` requires an explicit exact class; `archive_expect_transition_rejection()` asserts class, reason code, unchanged state, unchanged uncommitted count | All negative tests name exact classes; `TRANSITION-*` additions incl. the ten previously missing edges; corrected `AC-07`; `AC-08` per state; `AC-27` trio; `INV-12` lineage-cycle test |
| R10 canonical/schema evidence | explicit empty-object and numeric-key object representations preserve JSON object/list identity; independent literal-byte digest vectors; this traceability rewrite | `CJSON-EMPTY-OBJECT(-NESTED)`; `CJSON-EMPTY-LIST`; `CJSON-NUMERIC-KEY-OBJECT/ROUNDTRIP/SAFE`; `C-IMM-09/10/11`; `GOLDEN-CASE-INDEPENDENT`; `GOLDEN-SCOPE-INDEPENDENT/PRODUCTION` |

Correction to the prior matrix: `A-STREAM-03` asserts the sequence-must-begin-at-one rule; the gap test is `A-STREAM-06`.

## Third-pass remediation findings (T1–T8)

Every mapping below cites exact test IDs. No blanket "Covered" claims are used.

| Finding | Root-cause implementation | Named regression evidence |
|---|---|---|
| T1 exact destructive course-scope binding | `assert_reset_scope_matches_case()` binds reset scope to the bound revision's `subject_scope_digest`; `assert_incident_scope_matches_case()` resolves active, current, correction-target, or newest retained cancelled revision evidence before comparing the unprotected-reset scope. Cancellation cannot erase the evidence needed for live or replay validation. | `T1-RESET-COURSE-BROADENED/NARROWED/SUBSTITUTED/EXACT`; `T1-INCIDENT-COURSE`; `T1-INCIDENT-COURSE-PREFINALIZATION(-EXACT)`; `T1-INCIDENT-COURSE-CORRECTION-TARGET(-EXACT)`; `T1-INCIDENT-COURSE-CANCELLED-RETAINED(-EXACT)`; `T1-INCIDENT-COURSE-CANCELLED-REPLAY`; `T1-INCIDENT-COURSE-CANCELLED-RECORDED-REPLAY`; preserved `BIND-*` cases. |
| T2 preserve confirmed integrity compromise and restrictions | Monotonic `integrity_compromise_confirmed` (never cleared by a later incident) plus accumulating `integrity_remaining_restrictions` (a later incident's clean disposition can never erase an earlier incident's unresolved restriction); `has_open_block_for_integrity()` replaces the overwriteable latest-disposition check. | `T2-RESTRICTION-PERSISTS`; `T2-RESTRICTION-REMAINS-EFFECTIVE`; `T2-LEGITIMATE-RESOLUTION`; `T2-COMPROMISE-PERSISTS`; `T2-COMPROMISE-THEN-FALSE-POSITIVE`; `T2-REPLAY-BLOCKING`; preserved `INTEGRITY-RESTRICTIONS-PERSISTED/FAIL-CLOSED`, `INTEGRITY-COMPROMISE-REMAINS-BLOCKED`. |
| T3 receipt-first idempotency | `GHCA_ACD_Archive_Client_Intent` now invokes the same exact caller contract and shared `GHCA_ACD_Archive_Event_Catalog::validate_payload_fragment()` semantic/cross-field validation used by full commands before digest calculation. The command and client-intent paths share one rule source. | `B-CLIENT-INTENT-PREPARE/MALFORMED/UNKNOWN-TYPE/MATCHES-COMMAND`; semantic negatives `B-CLIENT-INTENT-CASE-KEY/ENUM/RESET-SCOPE/RESET-CONSENT-WINDOW/ARTIFACT-KIND/FAILURE-PHASE-ATTEMPT/DRIFT-DETECTION-POINT/RESTORATION-KIND/INTEGRITY-EXCLUSIVITY/PAYLOAD-BOUND`; `B-RETRY-<Type>`; `B-IDEMP-01B/03B`. |
| T4 canonical object/list semantics | `GHCA_ACD_Archive_Empty_Object` preserves `{}` versus `[]`. `GHCA_ACD_Archive_Canonical_Object` stores explicit string-key/value pairs so PHP-coercible numeric object keys (`"0"`, `"42"`, `"-7"`) round-trip as objects rather than becoming lists; construction deeply detaches nested PHP references and access relies on reference-free copy-on-write state. Shared detachment enforces depth/value limits before recursive array copying, while already immutable canonical objects are safely shared once, avoiding repeated traversal. Ordinary contiguous integer-key PHP arrays remain lists because PHP has already erased object intent. | `CJSON-EMPTY-OBJECT-*`; `CJSON-EMPTY-LIST`; `CJSON-NUMERIC-KEY-OBJECT`; `CJSON-NUMERIC-KEY-ROUNDTRIP`; `CJSON-NUMERIC-KEY-SAFE`; `CJSON-DETACH-CYCLE`; `CJSON-NESTED-CANONICAL-MAX-DEPTH/DEPTH-OVERFLOW`; `C-IMM-09/10/11/12`; `B-EMPTY-SERVER-FACTS`; `B-EMPTY-SERVER-FACTS-NOT-LIST`. |
| T5 atomic drift-rebase recovery | A standalone cancellation of the drift-affected candidate is rejected while source drift is `OPEN`; the cancellation is only valid inside the rebase decision (where the resolution is applied first). This prevents a fragmented cancellation that a later rebase could omit. | `T5-DRIFT-BLOCKS-STANDALONE-CANCEL`; `T5-DRIFT-REBASE-ATOMIC`; preserved `DRIFT-RECOVERY-REBASE-CANDIDATE/REBASE-FINALIZED/MISSING-CANCELLATION/MISSING-REQUEST/FRAGMENTED`. |
| T6 remove fabricated cancellation attempt IDs | `ArchiveCancelled.build_attempt_id` is nullable; the aggregate binds it exactly — `null` before any attempt exists (a requested cancellation cannot invent one), exact match once an attempt exists. | `T6-CANCEL-REQUESTED-NULL-ATTEMPT`; `T6-CANCEL-REQUESTED-FABRICATED-ATTEMPT`; `T6-CANCEL-ATTEMPT-MISMATCH`; `T6-CANCEL-ATTEMPT-REQUIRED`; `T6-CANCEL-ATTEMPT-EXACT`. |
| T7 unsigned sequence range | Every command factory, `from_parts()`, and the private constructor accept an untyped input and reject non-strings explicitly before the canonical unsigned-decimal (`BIGINT UNSIGNED`) range check, preventing PHP 7 coercion. Valid string boundaries remain portable across PHP 7.4/8.3/8.5. | `T7-SEQ-ZERO/ABOVE-INT-MAX/MAX-UNSIGNED/CANONICAL/NEGATIVE/OVERFLOW/LEADING-ZERO/FLOAT/EMPTY/LOSSY-CAST`; actual-type `T7-SEQ-INTEGER/ACTUAL-FLOAT/BOOLEAN/NULL`; `T7-SEQ-FROM-PARTS-NON-STRING`; generated `T7-SEQ-NON-STRING-<Type>` for all 32 public factories. |
| T8 acceptance-evidence gaps | Added named exact rejection tests (each proves exact class, exact reason code, unchanged state, unchanged uncommitted count) and an oracle self-check; corrected the `B-SPLIT-*` "where applicable" claim; distinguished the 14 executable suites from `test-helpers.php`. | `T8-AUTHORIZE-AGAINST-REVOKED` (`reset_not_eligible`); `T8-REVOKE-NON-FINALIZED` (`active_archive_mismatch`); `T8-OUTCOME-BEFORE-CLAIM` (`invalid_reset_transition`); `T8-GENERIC-REQUEST-REQUIRES-REPLACEMENT` (`replacement_required`); `T8-ORACLE-BROAD`; `T8-ORACLE-WRONG`. |

Golden vectors deliberately refrozen for T1 (the archive `subject_scope_digest` and the actor authority scope it binds to are now the exact authorized reset-scope digest): `B-GOLDEN-02` / real `ghca-command-v1` (`361dd1a6…`), `A-GOLDEN-01` (`8484a6b5…`), `A-GOLDEN-02` (`f56d0a53…`), `A-GOLDEN-03` (`b0d7527f…`). `B-GOLDEN-01` (client-intent) is unchanged because client intent carries no server facts. All frozen values are byte-identical on PHP 7.4.33, PHP 8.3.30, and PHP 8.5.7.

## Fourth-pass acceptance findings (F1-F6)

| Finding | Root-cause implementation | Named regression evidence |
|---|---|---|
| F1 effective authority-scope binding | One catalog extractor derives every direct digest or structured reset scope, rejects contradictory scope representations, and is reused by commands and recorded event envelopes. Rebase commands additionally inspect nested request and correction documents. | `B-AUTH-<Type>` for `RequestCorrection`, `RequestReset`, `AuthorizeReset`, `ClaimResetExecution`, `RequireResetRemediation`, `DetectUnprotectedReset`, and `ConfirmUnprotectedReset`; `B-AUTH-RebaseSourceDriftRecovery-CORRECTION-CONTROL/CORRECTION/REQUEST`; matching/foreign `A-AUTH-<EventType>` controls. |
| F2 incident scope through all retained lifecycle states | Incident evidence selection covers active, current candidate, correction target, and newest retained cancelled revision. A cancelled pre-build revision therefore cannot lose course-scope binding. | `T1-INCIDENT-COURSE-PREFINALIZATION(-EXACT)`; `T1-INCIDENT-COURSE-CORRECTION-TARGET(-EXACT)`; `T1-INCIDENT-COURSE-CANCELLED-RETAINED(-EXACT)`; uncommitted and validly hashed authoritative replay negatives `T1-INCIDENT-COURSE-CANCELLED-REPLAY/RECORDED-REPLAY`. |
| F3 semantic receipt-first validation | Client intent validates exact fields plus shared event-schema fragments and cross-field semantics before digest/receipt lookup; full commands reuse the same rule source. | `B-CLIENT-INTENT-PREPARE` for the direct RequestArchive control, all 32 `B-RETRY-<Type>` caller-intent preparation paths, and the named semantic `B-CLIENT-INTENT-*` negatives listed in T3. |
| F4 lossless canonical object/list identity | Explicit empty and member-pair object types preserve `{}` and numeric string keys without PHP array-key coercion. Construction recursively detaches caller references; immutable nested objects are safely shared so exact-depth traversal stays bounded, while cyclic arrays fail before process exhaustion. | `CJSON-EMPTY-*`; `CJSON-NUMERIC-KEY-OBJECT/ROUNDTRIP/SAFE`; `CJSON-DETACH-CYCLE`; `CJSON-NESTED-CANONICAL-MAX-DEPTH/DEPTH-OVERFLOW`; `C-IMM-09/10/11/12`. |
| F5 non-coercing unsigned sequence contract | All 32 public factories, `from_parts()`, and the constructor validate that `expected_sequence` is actually a string before canonical unsigned-decimal range validation. | `T7-SEQ-NON-STRING-<Type>` x32; `T7-SEQ-FROM-PARTS-NON-STRING`; actual integer/float/boolean/null and boundary cases. |
| F6 independent digest oracles | Each retry test asserts caller-intent recognition before separately constructing a full retry; server-fact binding tests change only server facts; idempotency tests separately vary command ID, expected sequence, and server facts. | `B-RETRY-<Type>` x32; `B-SERVER-FACTS-<Type>` where applicable; `B-IDEMP-02A/02B/02C`. |

## Pure Slice 1A source files

Three pure files were added across the third/fourth remediation work:

- `includes/archive/infrastructure/class-archive-empty-object.php` — `GHCA_ACD_Archive_Empty_Object` explicit empty-object marker (T4).
- `includes/archive/infrastructure/class-archive-canonical-object.php` — `GHCA_ACD_Archive_Canonical_Object` lossless numeric-string-key object representation with deep immutability (T4/F4).
- `includes/archive/domain/class-archive-client-intent.php` — `GHCA_ACD_Archive_Client_Intent` receipt-first caller-intent contract (T3).

## Test files

The suite is **14 executable test processes**. `tests/archive/test-helpers.php`, `tests/archive/remediation-fixtures.php`, and `tests/archive/bootstrap.php` are shared includes, not executable suites; `test-helpers.php` also matches the `test-*.php` glob but is required by every suite rather than run on its own.

Executable suites:

- `tests/archive/test-canonical-json.php`
- `tests/archive/test-digests.php`
- `tests/archive/test-event-catalog.php`
- `tests/archive/test-value-objects.php`
- `tests/archive/test-aggregate.php`
- `tests/archive/test-remediation-event-envelope.php`
- `tests/archive/test-remediation-command-contracts.php`
- `tests/archive/test-remediation-immutability.php`
- `tests/archive/test-remediation-event-schemas.php`
- `tests/archive/test-remediation-aggregate-bindings.php`
- `tests/archive/test-remediation-atomic-reset-incidents.php`
- `tests/archive/test-remediation-transition-exception.php`
- `tests/archive/test-remediation-transition-matrix.php`
- `tests/archive/test-remediation-canonical-boundaries.php`

Shared includes (not executable suites): `tests/archive/test-helpers.php`, `tests/archive/remediation-fixtures.php`, `tests/archive/bootstrap.php`.

Every aggregate rejection asserted through `archive_expect_transition_rejection()` checks the exact exception class, exact reason code, unchanged aggregate state, and unchanged uncommitted-event count. The harness converts warnings, notices, and deprecations into exceptions; `archive_expect_exception()` requires an explicit exact class and rejects a broad `Throwable`/`Exception`/`Error` expectation, proven in-suite by `T8-ORACLE-BROAD` and `T8-ORACLE-WRONG`.

## Verification snapshot (2026-07-15, fourth remediation pass; formal Slice 1A acceptance)

| Target | Result |
|---|---|
| PHP 7.4.33 NTS x64 (standalone CLI at `%TEMP%\ghca-php-7.4.33-nts-x64\php.exe`) | 1,242 Slice 1A checks pass across the 14 executable test processes, 0 failures, 0 timeouts (60 s per process) |
| PHP 8.3.30 ZTS x64 | 1,242 Slice 1A checks pass across the 14 executable test processes, 0 failures, 0 timeouts (60 s per process) |
| PHP 8.5.7 NTS x64 | 1,242 Slice 1A checks pass across the 14 executable test processes, 0 failures, 0 timeouts (60 s per process) |
| PHP 8.4 | Unavailable: the installed `php-8.4.12-nts` Laragon directory has no `php.exe`; no CLI pass is claimed |
| Parser | all 35 Slice 1A PHP files pass `php -l` on PHP 7.4.33, 8.3.30, and 8.5.7 |
| Golden vectors | `B-GOLDEN-01/02`, `A-GOLDEN-01/02/03` byte-identical on PHP 7.4.33, 8.3.30, and 8.5.7 |
| Legacy plugin baseline | 25/25 checks pass (`tests/test-audit-pdf-jobs.php`: 14; `tests/test-course-lifespans.php`: 11) on PHP 7.4.33, 8.3.30, and 8.5.7 |
| Static purity/dependency scan | zero WordPress/LearnDash calls, persistence APIs, filesystem/network calls, clock/random calls, runtime include/require statements, or PHP-8-only constructs in `includes/archive/`; zero kernel references in the plugin entrypoint or other runtime PHP |
