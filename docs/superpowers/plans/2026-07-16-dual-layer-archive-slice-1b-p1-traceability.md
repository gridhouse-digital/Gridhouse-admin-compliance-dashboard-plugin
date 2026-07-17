# Slice 1B-P1 Transactional Persistence Traceability

**Status:** implementation, remediation, and self-review PASS; ready for owner review. Runtime wiring, workers, snapshot/artifact storage, evidence capture, reset execution, feature enablement, deployment, commit, and push have not started.

## Scope delivered

- Append-only stream/event storage with strict retained-row verification.
- Insert-once command receipts and receipt-first lost-response idempotency.
- Expected sequence/head-digest concurrency and retryable first-stream races.
- Closed command-bound aggregate dispatch and atomic multi-event decisions.
- Synchronous case/revision/reset projections with ordered projector heads.
- Atomic durable-task insertion, exact lease fencing, heartbeat, retry, dead-letter, and retained-task verification.
- Fail-closed gating for commands that require later-slice snapshot/artifact side records.
- No controller, worker, hook, cron, current-site migration, or runtime loading.

## Re-review remediation evidence

| Finding | Root fix | Named evidence |
|---|---|---|
| Completion/retry did not bind lease owner or expiry | Every heartbeat, completion, retry, and dead-letter update now compares the exact task ID, leased state, owner, token, and `lease_until_gmt > now` | `TASK-LEASE-WRONG-OWNER-COMPLETION`, `TASK-LEASE-STALE-COMPLETION`, `TASK-LEASE-EXPIRED-COMPLETION`, `TASK-LEASE-WRONG-OWNER-RETRY`, `TASK-LEASE-EXPIRED-RETRY` |
| Heartbeat persistence was missing | Added compare-and-set heartbeat that only extends an exact live lease | `TASK-LEASE-HEARTBEAT`, `TASK-LEASE-WRONG-OWNER-HEARTBEAT`, `TASK-LEASE-NON-EXTENDING-HEARTBEAT`, `TASK-LEASE-EXPIRED-HEARTBEAT` |
| Reclaim had an exact-expiry gap | Reclaim treats `now >= lease_until_gmt` as expired | `TASK-LEASE-EXACT-EXPIRY`, `TASK-LEASE-RECLAIM`, `TASK-LEASE-RECLAIM-STALE-TOKEN` |
| Retry/dead-letter policy lacked executable proof | Exact live lease reschedules attempts 1-4 and makes attempt 5 dead | `TASK-LEASE-RETRY`, `TASK-LEASE-DEAD-LETTER` |
| Retained tasks crossed the store boundary unvalidated | `find()` now rejects unknown schema versions, malformed canonical payloads, contradictory row/state bindings, invalid timestamps, and dedupe mismatches | `TASK-UNKNOWN-SCHEMA-FAIL-CLOSED`, `TASK-MALFORMED-PAYLOAD-FAIL-CLOSED`, `TASK-UNKNOWN-TYPE-FAIL-CLOSED`, `TASK-DEDUPE-FAIL-CLOSED`, `TASK-READ-VALIDATION` |

The pre-fix PHP 8.3/MySQL 8.0 regression run failed with wrong reason codes and an undefined heartbeat method. The same focused suite passes after the shared task-store boundary fix.

## Persistence requirement evidence

| Requirement | Named executable evidence |
|---|---|
| Ordered load, envelope/payload/hash verification, replay integrity | `PERSIST-LOAD-ORDERED`, `PERSIST-LOAD-HASH-TAMPER`, `PERSIST-LOAD-SEQUENCE-GAP`, `PERSIST-REHYDRATION-INTEGRITY` |
| Append-only events | `PERSIST-APPEND-ONLY` |
| Stream identity and expected-head concurrency | `PERSIST-FIRST-STREAM-CREATE`, `PERSIST-CASE-DIGEST-CONSTITUENT-MISMATCH`, `PERSIST-STREAM-IDENTITY-*`, `PERSIST-EXPECTED-SEQUENCE-CONFLICT`, `PERSIST-HEAD-DIGEST-CONFLICT` |
| Receipt-first idempotency and response-loss recovery | `PERSIST-IDEMPOTENCY-REPLAY`, `PERSIST-IDEMPOTENCY-CONFLICT`, `PERSIST-RESPONSE-LOSS-STALE-VERSION`, `PERSIST-RECEIPT-FIRST-RACE` |
| Closed command/case/scope binding | `PERSIST-COMMAND-BOUND-DISPATCH`, `PERSIST-COMMAND-CASE-BINDING`, `PERSIST-IDEMPOTENCY-SCOPE-DIGEST-BINDING`, `PERSIST-IDEMPOTENCY-ACTOR-NAMESPACE` |
| Atomic decisions, projections, tasks, stream head, receipt | `COMPONENT-MULTI-EVENT-ATOMIC`, `PROJECTOR-ALL-EVENT-TYPES`, `TASK-INSERT-ATOMIC`, `PERSIST-EVENT-INSERT-ROLLBACK`, `PERSIST-PROJECTION-ROLLBACK`, `PERSIST-TASK-INSERT-ROLLBACK`, `PERSIST-STREAM-HEAD-ROLLBACK`, `PERSIST-RECEIPT-ROLLBACK` |
| Projector ordering, idempotency, identity, and no-op advancement | `PROJECTOR-EXACT-NEXT`, `PROJECTOR-GAP-REJECTED`, `PROJECTOR-IDEMPOTENT-REPLAY`, `PROJECTOR-CONFLICTING-DUPLICATE`, `PROJECTOR-CASE-IDENTITY-BINDING`, `PROJECTOR-REVISION-IDENTITY-BINDING`, `PROJECTOR-RESET-IDENTITY-BINDING`, `PROJECTOR-AUTHORIZATION-IDENTITY-BINDING`, `PROJECTOR-ADVANCES-NOOP` |
| Real database concurrency | `PERSIST-FIRST-STREAM-RACE`, `PERSIST-FIRST-STREAM-RR-RACE`, `PERSIST-PROJECTION-RACE`, `TASK-LEASE-FENCING`, `TASK-LEASE-RECLAIM` |
| Later-slice side records fail closed | `PERSIST-ATOMIC-SIDE-RECORD-GATE` |
| Prefix independence and dark mode | `PERSIST-CUSTOM-PREFIX`, `PERSIST-NO-RUNTIME-WIRING` |

## Definitive verification

Final uninterrupted matrix: exit code 0; 9/9 cells pass. Every cell ran 55 schema checks plus 358 persistence checks.

| PHP | MySQL 8.0.46 | MySQL 8.4.10 | MariaDB 10.6.27 |
|---|---:|---:|---:|
| 7.4.33 | 55 + 358 PASS | 55 + 358 PASS | 55 + 358 PASS |
| 8.3.30 | 55 + 358 PASS | 55 + 358 PASS | 55 + 358 PASS |
| 8.5.7 | 55 + 358 PASS | 55 + 358 PASS | 55 + 358 PASS |

- Schema assertions: 495 total across the matrix.
- Persistence assertions: 3,222 total across the matrix.
- Slice 1A kernel: 1,242 checks across 14 suites on each PHP runtime.
- Legacy baseline: both suites pass on each PHP runtime.
- PHP lint: all 55 archive production/test PHP files pass on each PHP runtime.
- Static checks: zero runtime-hook, site-bootstrap, hardcoded-secret, or event-row mutation hits; plugin entrypoint has zero archive references.
- PHP 8.4 remains unavailable because the configured directory contains no CLI executable; no PHP 8.4 pass is claimed.

## Repository boundary

- Branch: `feature/dual-layer-archive-slice-1b-persistence`.
- Nothing staged, committed, or pushed by this remediation.
- `.claude/` remains unrelated and untouched.
- All database writes were limited to disposable loopback databases whose name uses the `ghca_acd_archive_test_` safety prefix.
- No known P1/P2 correctness, integrity, concurrency, compatibility, or evidence defect remains in Slice 1B-P1.
