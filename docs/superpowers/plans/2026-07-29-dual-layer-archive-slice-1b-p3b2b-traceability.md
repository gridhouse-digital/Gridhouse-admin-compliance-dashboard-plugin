# Slice 1B-P3B2b Evidence Source Adapter Traceability

**Date:** 2026-07-29
**Status:** formally accepted by the owner
**Branch:** `feature/dual-layer-archive-slice-1b-p3b2b-evidence-source-adapter`
**Proposal/starting/ending HEAD:** `7d6e5e0d68bf961ea5c2d9cb49a2479337e9328f`

## Scope delivered

P3B2b adds one dark, constructor-injected LearnDash evidence source and one isolated wpdb-compatible read session. It implements the owner-approved B01-B18 contracts without runtime configuration loading, WordPress wiring, current-site bootstrap, network access, schema changes, or lifecycle behavior changes.

The frozen source identity is:

```text
source_adapter_key = learndash-local
source_adapter_version = 1.0.0
wordpress_version = 7.0.2
learndash_version = 5.1.6.1
plugin_version = 1.2.0
```

The source-version descriptor is detached from caller mutation and accepts exactly the three version fields. Missing, extra, malformed, or mismatched values fail before any source query. Production code-version attestation remains blocked on P3B3.

## Files added

- `includes/archive/infrastructure/class-wpdb-archive-evidence-read-session.php`
- `includes/archive/infrastructure/class-learndash-archive-evidence-source.php`
- `tests/archive/test-p3b2b-evidence-source.php`
- `tests/archive/test-p3b2b-evidence-source-persistence.php`
- `tests/archive/test-p3b2b-evidence-source-concurrency.php`
- `docs/superpowers/plans/2026-07-29-dual-layer-archive-slice-1b-p3b2b-traceability.md`

## Files modified

- `includes/archive/contracts/class-archive-evidence-source.php`
- `includes/archive/application/class-archive-evidence-task-handler.php`
- `includes/archive/application/class-archive-evidence-result-validator.php`
- `includes/archive/infrastructure/class-wpdb-archive-snapshot-store.php`
- `tests/archive/bootstrap.php`
- `tests/archive/test-p3-boundaries.php`
- `tests/archive/test-p3b2a-evidence.php`
- `tests/archive/test-p3b2a-evidence-persistence.php`
- `tests/archive/test-all.ps1`
- `docs/superpowers/plans/2026-07-28-dual-layer-archive-slice-1b-p3b2b-evidence-source-adapter-decisions-proposal.md`

The original owner-approved narrow B17 amendment to `tests/archive/test-p3b2a-evidence-persistence.php` changed only the two retained fake source signatures by adding the third `callable $checkpoint` parameter. The later formal-review authorization added the named order/membership persistence regression, and separately permitted only the numeric-sorted membership comparison in the snapshot store.

The owner-authorized mechanical remediation updated `tests/archive/test-all.ps1` with process-local P3B2b source-test credentials and the three P3B2b suites. After the owner authorized starting only the three disposable database containers, the definitive runner completed all six cells successfully.

## Decision-to-code-to-test mapping

| Decision | Implementation | Principal executable evidence |
|---|---|---|
| B01-B02 versions and adapter compatibility | `GHCA_ACD_LearnDash_Archive_Evidence_Source` freezes the exact source tuple and `learndash-local/1.0.0` | descriptor exactness, spoof rejection, caller-mutation detachment, golden E07/E08 checks |
| B03 isolated read-only principal | read session validates connection ID, database, user, time zone, charset, and the closed grant set | restricted-principal persistence checks; archive-connection-reuse rejection |
| B04 tenant/site/prefix descriptor | constructor validates exact descriptor keys, ASCII identifiers, site/blog equality, derived prefixes, and cross-schema isolation | identifier/cross-schema rejection; real database descriptor path |
| B05 physical manifest and fixed query plan | closed table/column/index manifests; deterministic count-first reads; `LIMIT ceiling + 1`; one combined post-read count; at most 32 statements | query-plan regression; combined post-read regression; real physical golden |
| B06 identity, roles, and groups | closed user/usermeta mapping, registered-role intersection, bounded descendant expansion | user/role/group mapping and hierarchy cycle/depth/count rejection |
| B07 tracked sets and enrollment | annual/new-hire tracked sets with direct, then group, then open precedence | full independent golden and source-record-version vectors |
| B08 status, quiz, score, and calculation | half-open cycle checks, exact completion agreement, decimal half-up score, no time-dependent calculation | quiz/score, duplicate activity, cycle boundary, and complete-E07 checks |
| B09 record IDs and versions | approved physical rows feed domain-separated deterministic record versions | record-ID sets, used/unused source-value, course-record golden |
| B10 certificate reference | retains only certificate post ID and source-record version; no acquisition | exact two-key certificate independent golden |
| B11 fingerprint parity | review and capture call the same concrete source, canonical document, and E08 digester | byte-identical E07 and E08 replay check |
| B12 transaction and fencing | isolated `REPEATABLE READ` read-only consistent snapshot; checkpoint before/after statements; unconditional rollback and close | mutation-before/after snapshot, elapsed budget, cancellation, rollback, and close checks |
| B13 closed failures | existing exception categories/reasons/contexts are emitted; fence throwable is rethrown after cleanup | exact negative paths and cleanup assertions |
| B14 minimization | closed columns, option/meta keys, serialized structure, and result document | unknown serialized key/object rejection and no-network/no-runtime boundary scans |
| B15 real concurrency | disposable restricted source schema and two independent connections | 4 persistence plus 7 concurrency checks in every database cell |
| B16 verification | exact supported PHP and database matrix below | 7,008 database checks plus runtime-only suites |
| B17 allowlist | all production and test changes are within B17 plus the approved narrow fake-signature, order/membership, snapshot-store, and matrix-runner amendments | changed-file and narrow-diff audit |
| B18 deferrals | no runtime composition, certificate acquisition, packet rendering, verification/finalization, or P3B3 behavior | 15 boundary checks and forbidden-surface scans |

## Database verification

Every cell used an actual supported PHP CLI, an approved disposable database whose name matched `^ghca_acd_archive_test_[A-Za-z0-9_]+$`, loopback only, process-local credentials, explicit destructive opt-in, a disposable `_source` schema, and a newly generated 32-character restricted-source password. Only the three authorized containers were inspected. Credential values were neither printed nor persisted.

The definitive `tests/archive/test-all.ps1` runner produced exit code zero and `ALL 6 P1/P2/P3A/P3B1/P3B2a/P3B2b MATRIX CELLS PASSED`.

Per-cell assertion composition:

- schema: 55
- P1 persistence: 358
- P2 side records: 263
- P3 digests: 9
- P3 worker: 67
- P3 storage/orphans: 88
- P3B task contracts: 173
- P3B Build Coordinator: 4
- P3B ledger handler: 7
- P3B ledger failure/crash/race: 27
- P3B2a evidence: 18
- P3B2a persistence: 33
- P3B2a concurrency: 4
- P3B2b source: 51
- P3B2b source persistence: 4
- P3B2b source concurrency: 7
- total: 1,168

| Runtime | MySQL 8.0.46 | MySQL 8.4.10 | MariaDB 10.6.27 |
|---|---:|---:|---:|
| PHP 8.3.30 | 1,168 PASS | 1,168 PASS | 1,168 PASS |
| PHP 8.5.7 | 1,168 PASS | 1,168 PASS | 1,168 PASS |

Database-matrix total: **7,008 executable checks**.

The restricted principal had only `USAGE` and source-schema `SELECT`. Tests proved that it could not write the source, issue DDL, or read archive tables. Archive persistence counts remained unchanged.

## Runtime-only verification

| Verification | PHP 8.3.30 | PHP 8.5.7 |
|---|---:|---:|
| Slice 1A kernel plus accepted digest vectors | 1,246 / 14 suites PASS | 1,246 / 14 suites PASS |
| Legacy baseline | 25 / 2 suites PASS | 25 / 2 suites PASS |
| P3/P3B boundary suite | 15 PASS | 15 PASS |
| Standalone P3 digests | 9 PASS | 9 PASS |
| P3B2a evidence with extensions disabled (`php -n`) | 18 PASS | 18 PASS |
| P3B2b source with extensions disabled (`php -n`) | 51 PASS | 51 PASS |
| Archive production/test lint | 89 files PASS | 89 files PASS |

Database matrix plus kernel, legacy, and boundary evidence totals **9,580 assertions**. Standalone digest and `php -n` reruns duplicate other coverage and are excluded from that aggregate. Lint covered **178 runtime/file combinations**.

PHP 8.4 remains optional and unavailable because no PHP 8.4 `php.exe` is installed. No PHP 8.4 result is claimed.

## Consistency, cancellation, and cleanup evidence

- A mutation committed before snapshot start was visible.
- Mutations committed by a second connection after snapshot start were not visible to later source queries.
- Every success, validation failure, elapsed-budget failure, and fenced cancellation rolled back and closed the isolated source connection.
- Rollback and close were each attempted when either or both operations threw. Rollback failure retained priority over close failure and over the original pending/fence result.
- Serialized references/recursion, depth 33, value 10,001, oversized strings, and excessive tracked-course IDs were rejected before follow-up evidence queries or course-dependent placeholders.
- Multi-course evidence retained numeric policy membership `["2","101"]` while preserving display order `["101","2"]`; missing, duplicate, and additional members failed closed.
- Combined grants, cross-database grants, table grants, and `WITH GRANT OPTION` were rejected before the evidence transaction.
- Missing, malformed, spoofed, and inconsistent certificate references all returned the exact certificate-gate tuple.
- Elapsed budgets 1,999 and 2,000 milliseconds were accepted; 2,001, zero, negative, string, and null values were rejected before source queries.
- Structural preflight mismatches remained operational-blocked, while thrown/returned execution failures used the retryable source-read tuple.
- The combined post-read count rechecked all selected row families inside the same snapshot.
- A fence raised immediately after transaction start still caused rollback because transaction ownership is recorded before the post-statement checkpoint.
- The exact fence throwable was rethrown only after cleanup; no partial E07 document escaped.
- Rollback or close failure discarded the prepared result and used the accepted operational failure tuple.
- Exceptions from both transaction-start statements were translated to the canonical sanitized `archive_source_read_failed` / `transaction_start` tuple. A throwing start attempt rolled back and closed; no driver, SQL, credential, or raw exception text escaped.
- Site/blog contradictions and mixed valid prefixes used `archive_build_binding_invalid` / `authoritative_load`, while invalid identifiers, cross-schema names, and unsupported engines retained `archive_source_schema_unsupported` / `source_preflight`.
- The elapsed ceiling was rechecked only after successful rollback and close. Deadline crossings during either cleanup phase returned `archive_source_query_failed` / `source_query` only when no cleanup or pending/fence failure had priority.

## Stable failure tuples

P3B2b adds no new failure code:

| Category | Reason | Context |
|---|---|---|
| invalid | `archive_build_binding_invalid` | `authoritative_load` |
| invalid | `archive_snapshot_invalid` | `source_validate` |
| invalid | `archive_evidence_prohibited` | `source_validate` |
| invalid | `archive_evidence_incomplete` | `pre_query_limit` or `normalize_limit` |
| retryable | `archive_source_read_failed` | `transaction_start` or `source_query` |
| retryable | `archive_source_query_failed` | `source_query` |
| operational blocked | `archive_source_transaction_failed` | `transaction_rollback` or `connection_close` |
| operational blocked | `archive_source_schema_unsupported` | `source_preflight` |
| existing lease-loss category | existing fence reason | fenced phase |

No SQL, bound value, table prefix, database name, hostname, user name, email, credential, serialized content, path, or stack trace is exposed through operational text.

## Static and repository evidence

- `git diff --check`: PASS.
- Plugin entrypoint archive references: zero.
- Runtime hook, cron, scheduler, CLI, controller, REST, and activation wiring: zero.
- `wp-load.php`, `wp-config.php`, global `$wpdb`, and current-site credential references: zero.
- Network calls and certificate acquisition: zero.
- Production schema DDL and source/archive mutation statements: zero.
- Debug output and hardcoded secret/key material: zero.
- Production implementation is constructor-injected and remains dark.
- The approved proposal commit was pushed to `origin`; the implementation remains uncommitted.
- Ending HEAD is unchanged at `7d6e5e0d68bf961ea5c2d9cb49a2479337e9328f`.
- Staged files: zero.
- `.claude/` remains the pre-existing untracked tree and was not accessed or modified.
- No current-site database, WordPress bootstrap, activation, deployment, P3B3 implementation, stage, or implementation commit/push occurred.

## Re-review status

The adapter behavior, definitive real-database runner matrix, concurrency checks, runtime checks, boundaries, lint, and static scans passed. The owner formally accepted P3B2b after independent re-review found no remaining actionable P1/P2 findings.
