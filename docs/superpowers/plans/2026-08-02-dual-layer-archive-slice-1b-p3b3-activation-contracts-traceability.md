# Slice 1B P3B3 Activation Contracts Traceability

Date: 2026-08-03
Status: **ready for formal re-review, not self-accepted**

## Scope and repository state

- Branch: `feature/dual-layer-archive-slice-1b-p3b3-activation-contracts-implementation`.
- Starting and ending HEAD: `de7a007755504649e32c32de240cf4efaa74d4ac`.
- Staged files: zero.
- Implementation remains unstaged and uncommitted.
- The pre-existing untracked `.claude/` tree was not accessed or modified.
- No current-site database, `wp-load.php`, `wp-config.php`, global `$wpdb`, activation, deployment, controlled testing, stage, commit, push, pull, fetch, merge, rebase, PR, or remote modification occurred.
- Only disposable databases whose names passed `^ghca_acd_archive_test_[A-Za-z0-9_]+$` were used. Credentials were recovered only from the three authorized disposable containers, remained process-local, and were neither printed nor persisted.

## Owner-approved A18 amendment

The 2026-08-03 amendment added the existing evidence-source contract, LearnDash adapter, read session, and the narrowly retained P3B2a fake-source test to A18. It authorized one review-read operation and one preflight-only operation without changing capture authority, B05 mappings, E07 bytes, E08, task/event/schema/retained-data contracts, or runtime activation. The approved proposal records the amendment verbatim.

## 2026-08-04 evidence-accounting remediation

This narrow pass modifies only `tests/archive/test-p3b3-activation-contracts.php` and this traceability report. It changes no production behavior. Bare non-deferred lists were replaced by exact requirement-to-runner-suite-to-assertion mappings and a local token-based validator that recognizes check IDs only inside real `archive_check()` calls. Four requirements without honest executable coverage were reclassified as deferred: exact worktree allowlist, cursor rotation specifically while flags are off, the compound schema/metadata/network requirement, and closed filesystem permissions. The proposal's earlier 53/22/47 implementation-note counts are historical and superseded for evidence accounting by this 52/19/51 record; the proposal was outside this remediation's authorization and was not modified.

## 2026-08-06 final evidence-semantics remediation

This final narrow pass modifies only `tests/archive/test-p3b3-activation-contracts.php`, `tests/archive/test-p3b3-activation-concurrency.php`, and this traceability report. It adds a dedicated attestation/admission-before-claim regression, maps database lease authority to the retained real two-connection race, maps no-cache substitution to the real-adapter mutation/drift regression, and restricts assertion discovery to one literal second top-level `archive_check()` argument. The condition-literal negative fixture proves that strings in the first argument or nested calls cannot satisfy a mapping. Classification remains 52/19/51 because the corrected requirements remain executable or retained evidence; no deferred requirement became a passing assertion.

## Changed and new files

Production:

- `includes/archive/application/class-archive-review-intake.php` (new)
- `includes/archive/class-archive-module.php`
- `includes/archive/contracts/class-archive-evidence-source.php`
- `includes/archive/infrastructure/class-learndash-archive-evidence-source.php`
- `includes/archive/infrastructure/class-wordpress-archive-runtime-descriptor.php`
- `includes/archive/infrastructure/class-wpdb-archive-evidence-read-session.php`
- `includes/archive/bootstrap.php` (manifest only for the new class)

Tests:

- `tests/archive/bootstrap.php`
- `tests/archive/test-all.ps1`
- `tests/archive/test-p3-boundaries.php`
- `tests/archive/test-p3b2a-evidence-persistence.php` (only the two retained fake source implementations)
- `tests/archive/test-p3b3-activation-gates.php`
- `tests/archive/test-p3b3-multisite.php`
- `tests/archive/test-p3b3-runtime-composition.php`
- `tests/archive/test-p3b3-worker-runtime.php`
- `tests/archive/test-p3b3-activation-contracts.php` (new)
- `tests/archive/test-p3b3-activation-persistence.php` (new)
- `tests/archive/test-p3b3-activation-concurrency.php` (new)

Documentation:

- `docs/superpowers/plans/2026-08-01-dual-layer-archive-slice-1b-p3b3-activation-contracts-proposal.md`
- `docs/superpowers/plans/2026-08-02-dual-layer-archive-slice-1b-p3b3-activation-contracts-traceability.md` (new)

The resulting set is the exact amended A18 allowlist: 7 production files, 11 test files, and 2 documents. No schema, metadata, plugin-entrypoint, or other retained-suite file changed.

## A01-A20 decision-to-code-to-test mapping

| Decision | Implemented code-enforceable contract | Primary regression evidence |
|---|---|---|
| A01 | Exact protected authorization mode, tenant, role, window, and controlled-test bounds in the runtime descriptor; no activation path | activation contracts, activation gates |
| A02 | Existing non-configurable code attestor remains authoritative and is called before preflight/admission | activation contracts, activation gates |
| A03 | Existing tenant/archive/source descriptors remain distinct; preflight reuses exact identity and grant grammar | activation contracts, activation persistence, multisite |
| A04 | Private/public containment, closed permissions, cursor-key presence, and exact `free_bytes >= 1090519040` controlled threshold | activation contracts |
| A05 | Module checkpoints before private source composition and injects a fresh source plus accepted UoW into the bounded review intake | activation contracts, activation persistence |
| A06 | Review and capture share one LearnDash read, normalization, calculation, canonical JSON, and validation path; review derives policy while capture still requires and compares retained policy. The real disposable adapter proves review/capture E07/E08 parity, drift, and cancellation cleanup | activation contracts, activation persistence, P3B2b source, P3B2a evidence |
| A07 | Certificate acquisition remains absent and blocked | boundaries, activation contracts |
| A08 | Packet handler/renderer remains absent and blocked | boundaries, activation contracts |
| A09 | Verification/finalization remains absent and blocked | boundaries, activation contracts |
| A10 | Production admission remains blocked on unresolved D16; existing original-task recovery is unchanged | worker runtime, activation contracts |
| A11 | No scheduler or command registration is installed; controlled one/production five policy is frozen without a new lock | activation concurrency, worker runtime, boundaries |
| A12 | No invocation or logging surface was activated; stdout/stderr contracts remain host/activation gates | activation contracts, boundaries |
| A13 | Flag-off preflight requires exact zero flags; live admission requires exact one flags and re-resolves authority before sensitive work. Both paths require code-frozen calculation policy `time-independent` version `1` before evidence-source composition or task claim | activation gates, activation contracts, worker runtime |
| A14 | Existing direct-blog multisite authority remains; no network loop or network activation was added | multisite, boundaries |
| A15 | No ordinary telemetry or evidence export was added; restricted operator evidence remains external | activation contracts, boundaries |
| A16 | Closed stable failures and source rollback/close/fence priority are retained through preflight and review | activation contracts, activation persistence |
| A17 | No new retained-row mutation or object disposition exists; disabling leaves immutable data untouched | boundaries, digests, activation contracts |
| A18 | Exact amended allowlist, bootstrap manifest, runner inclusion, and constructed-dark boundary are mechanically checked | boundaries, runtime composition, runner uniqueness |
| A19 | Production remains blocked pending certificate, packet, verify/finalize, D16, and accepted operator evidence | activation contracts, worker runtime |
| A20 | Exact canonical authorization grammar, protected-path checks, re-read, tenant/mode/time binding, and three separate approvals remain enforced/gated | activation contracts, activation gates |

## Disposable database matrix

The definitive matrix was rerun from the beginning on 2026-08-06 after the final evidence-semantics remediation. Each cell ran the same 23 suites and **1,321 checks**:

- schema 55;
- P1 persistence 358;
- P2 side records 263;
- P3 digests 9;
- P3 worker 67;
- P3 storage/orphans 88;
- P3B1 task contracts 173;
- P3B1 build coordinator 4;
- P3B1 ledger vectors 7;
- P3B1 failure/crash/race 27;
- P3B2a evidence 18, persistence 33, concurrency 4;
- P3B2b source 52, persistence 4, concurrency 7;
- P3B3 runtime composition 25, activation gates 30, worker runtime 29, multisite 19;
- P3B3 activation contracts 34, persistence 9, concurrency 6.

| Runtime | MySQL 8.0.46 | MySQL 8.4.10 | MariaDB 10.6.27 |
|---|---:|---:|---:|
| PHP 8.3.30 | 1,321 PASS | 1,321 PASS | 1,321 PASS |
| PHP 8.5.7 | 1,321 PASS | 1,321 PASS | 1,321 PASS |

Current database-matrix total: **7,926 checks PASS**. The runner reported all six PHP/database cells passed. The real `TASK-TWO-CONNECTION-LEASE-RACE` check passed in every cell. Credentials were filtered only from `ghca-mysql-8.0`, `ghca-mysql-8.4`, and `ghca-mariadb-10.6`, verified identical with a database name matching the approved safety prefix, and existed only in the matrix PowerShell process; no credential value was printed or persisted.

## Runtime-only verification

| Verification | PHP 8.3.30 | PHP 8.5.7 |
|---|---:|---:|
| Slice 1A kernel plus accepted digest vectors | 1,246 / 14 suites PASS | 1,246 / 14 suites PASS |
| Legacy baseline | 25 / 2 suites PASS | 25 / 2 suites PASS |
| P3/P3B boundary suite | 18 PASS | 18 PASS |
| Standalone P3 digests | 9 PASS | 9 PASS |
| P3B3 runtime composition, normal / `php -n` | 25 / 25 PASS | 25 / 25 PASS |
| P3B3 activation gates, normal / `php -n` | 30 / 30 PASS | 30 / 30 PASS |
| P3B3 worker runtime, normal / `php -n` | 29 / 29 PASS | 29 / 29 PASS |
| P3B3 activation contracts, normal / `php -n` | 34 / 34 PASS | 34 / 34 PASS |
| P3B3 activation concurrency, normal / `php -n` | 6 / 6 PASS | 6 / 6 PASS |
| Retained P3B2b source, normal / `php -n` | 52 / 52 PASS | 52 / 52 PASS |
| Retained P3B2a evidence, normal / `php -n` | 18 / 18 PASS | 18 / 18 PASS |
| Archive production/test lint | 103 files PASS | 103 files PASS |

The former 10,450-assertion aggregate predates the mapping remediation and is not presented as a current acceptance total. Classification coverage and executable assertion totals are different measures: 122 requirements are classified, 71 have mechanically resolved mappings, 51 remain deferred, and the focused activation-contract process executes 34 assertions.

PHP 8.4 remains optional and unavailable: one installed Laragon PHP 8.4 directory exists but contains no `php.exe`. No PHP 8.4 pass is claimed.

## Concurrency, authorization, grants, storage, and cleanup evidence

- Admission-change regressions prove `cli_run()` supplies a fresh admission callback, admission runs before composition and again after composition, the second admission precedes `run_once()`, and an attestation/admission failure on that second call prevents the worker call.
- Controlled concurrency is one active invocation; production policy is no more than five, while existing database leases/fencing remain authoritative and no new lock was added.
- Authorization tests cover exact canonical bytes/order/types, missing/extra/reordered fields, tenant/mode/role/time changes, expiry/not-yet-valid windows, BOM/overlength, unsafe public/temp/plugin/private roots, symlink leaf/parent where supported, and file replacement during read.
- The authorization document is bounded, canonical, re-read, never created or modified by production code, and never included in output.
- Preflight reuses the accepted `CURRENT_USER()`, eight-row exact grant, table, column, and index validators. It performs no B05 evidence-data query, mutation, or evidence transaction and always closes the isolated connection.
- Close failure retains priority; checkpoint/fence/cancellation throwables survive successful cleanup unchanged; closed-session reuse fails closed.
- Storage tests accept threshold equality and one byte above, reject one byte below, enforce private/public disjointness, and preserve report-only orphan behavior.
- Review derives the policy digest from the normalized policy constituent. Caller policy authority is rejected; independently composed real-adapter review/capture E07 bytes and E08 fingerprints match; controlled mutation reaches the fenced drift decision; cancellation preserves the exact throwable, closes the source session, and leaves no lifecycle residue.
- Production code freezes calculation policy key `time-independent` and version integer `1`; a negative child-process regression proves a mismatch blocks admission before evidence reads or task claims.
- Review receipt replay is idempotent; caller fingerprints are rejected with zero event residue; exact fence failure leaves zero lifecycle residue.
- Disposable multisite cleanup removes its temporary flags, tenant options, source user, source fixtures, authorization file, and private root. Both archive feature flags remain off after the complete suite.

## Static, boundary, and repository evidence

- Entrypoint exact bootstrap statement count: one; remaining case-insensitive entrypoint archive references after removing it: zero.
- Production hook/filter/activation/deactivation registrations: zero.
- Production REST, AJAX, admin/controller registration: zero.
- Production WP-Cron, Action Scheduler, scheduler installation, and `WP_CLI::add_command()` registration: zero.
- Changed production current-site bootstrap (`wp-load.php`, `wp-config.php`, global `$wpdb`) references: zero.
- Added HTTP/socket/network operations: zero.
- Added hardcoded database credentials, authorization documents, or cursor-HMAC keys in production: zero.
- Added debug output and raw exception/path output: zero.
- Added SQL mutation statements: zero; the sole full-file match is the retained tenant-provisioning insert, unchanged by this slice.
- Feature-flag writes/enabling in changed production: zero.
- Changed schema/migrator files: zero.
- Immutable event/snapshot/artifact/ledger mutation surface added: zero.
- Each of the three new P3B3 runner suites occurs exactly once.
- All 122 approved evidence identifiers are unique and classified exactly once: 52 `EXECUTED_PASS`, 19 `RETAINED_PASS`, and 51 `DEFERRED_OPERATOR_EVIDENCE`. Deferred items have no executable mapping and are not executable-pass assertions.
- UTF-8 without BOM, trailing-whitespace checks, and `git diff --check`: PASS.

## Approved evidence-manifest classification

The closed manifest in `test-p3b3-activation-contracts.php` contains all 122 unique A01-A20 identifiers exactly once. Classification coverage is not an executable assertion count:

| Classification | Count | Meaning |
|---|---:|---|
| `EXECUTED_PASS` | 52 | The requirement maps to an exact assertion in a current P3B3 runner suite. |
| `RETAINED_PASS` | 19 | The requirement maps to an exact assertion in a retained runner suite. |
| `DEFERRED_OPERATOR_EVIDENCE` | 51 | No executable-pass mapping is claimed; the requirement contributes zero passing assertions. |

The activation-contract suite emits `ACTIVATION_EVIDENCE_MANIFEST=EXECUTED_PASS:52,RETAINED_PASS:19,DEFERRED_OPERATOR_EVIDENCE:51`. The validator proves proposal-set equality, uniqueness, exact runner occurrence, suite existence, and exact `archive_check()` assertion membership. Its tokenizer accepts only a single literal supplied as the second top-level argument of `archive_check()`; first-argument condition strings, nested-call strings, comments, unrelated literals, mapping-array text, and the validator's own meta-checks cannot serve as evidence.

### `EXECUTED_PASS` mappings

| Requirement | Suite | Executed check ID |
|---|---|---|
| `ACTIVATION-ATTEST-CONFIG-SPOOF-REJECTED` | `tests/archive/test-p3b3-runtime-composition.php` | `P3B3-ATTEST-REJECTS-ORDINARY-OPTION-ENV-REQUEST-TASK-DB-GLOBAL-FILTER-SPOOF` |
| `ACTIVATION-ATTEST-EXACT-TUPLE` | `tests/archive/test-p3b3-runtime-composition.php` | `P3B3-ATTEST-EXACT-WP-LD-PLUGIN-TUPLE` |
| `ACTIVATION-ATTEST-RECHECK-BEFORE-CLAIM` | `tests/archive/test-p3b3-activation-concurrency.php` | `ACTIVATION-ATTEST-RECHECK-BEFORE-CLAIM` |
| `ACTIVATION-AUTHORIZATION-CANONICAL-DOCUMENT-EXACT` | `tests/archive/test-p3b3-activation-contracts.php` | `ACTIVATION-AUTHORIZATION-EXACT-BLOG-WINDOW` |
| `ACTIVATION-AUTHORIZATION-EXACT-BLOG-WINDOW` | `tests/archive/test-p3b3-activation-contracts.php` | `ACTIVATION-AUTHORIZATION-EXACT-BLOG-WINDOW` |
| `ACTIVATION-AUTHORIZATION-EXPIRES` | `tests/archive/test-p3b3-activation-contracts.php` | `ACTIVATION-AUTHORIZATION-NOT-YET-VALID-REJECTED` |
| `ACTIVATION-AUTHORIZATION-MISSING-EXTRA-REORDERED-REJECTED` | `tests/archive/test-p3b3-activation-contracts.php` | `ACTIVATION-AUTHORIZATION-MISSING-EXTRA-REORDERED-REJECTED` |
| `ACTIVATION-AUTHORIZATION-MODE-SITE-BLOG-MISMATCH-REJECTED` | `tests/archive/test-p3b3-activation-contracts.php` | `ACTIVATION-AUTHORIZATION-MODE-SITE-BLOG-MISMATCH-REJECTED` |
| `ACTIVATION-AUTHORIZATION-NONEXPOSURE` | `tests/archive/test-p3b3-activation-gates.php` | `P3B3-CLI-OUTPUT-CONTAINS-NO-PII-SECRET-SQL-PATH-URL-OR-ID` |
| `ACTIVATION-AUTHORIZATION-NOT-YET-VALID-REJECTED` | `tests/archive/test-p3b3-activation-contracts.php` | `ACTIVATION-AUTHORIZATION-NOT-YET-VALID-REJECTED` |
| `ACTIVATION-AUTHORIZATION-UNSAFE-FILE-REJECTED` | `tests/archive/test-p3b3-activation-contracts.php` | `ACTIVATION-AUTHORIZATION-UNSAFE-FILE-BEHAVIOR` |
| `ACTIVATION-BOOTSTRAP-MANIFEST-EXACT` | `tests/archive/test-p3b3-runtime-composition.php` | `P3B3-BOOTSTRAP-LITERAL-55-FILE-MANIFEST-AND-DIGEST` |
| `ACTIVATION-CERTIFICATE-NETWORK-PROHIBITED` | `tests/archive/test-p3b3-runtime-composition.php` | `P3B3-NO-NETWORK-OR-CERTIFICATE-ACQUISITION` |
| `ACTIVATION-CERTIFICATE-PRODUCTION-GATE` | `tests/archive/test-p3b3-activation-gates.php` | `P3B3-PRODUCTION-ACTIVATION-BLOCKED-BY-CERTIFICATE-PACKET-VERIFY-D16` |
| `ACTIVATION-CONTROLLED-NO-FINALIZATION` | `tests/archive/test-p3b3-worker-runtime.php` | `P3B3-HANDLER-REGISTRY-EXACT-CAPTURE-AND-LEDGER` |
| `ACTIVATION-CONTROLLED-TASK-REGISTRY-CLOSED` | `tests/archive/test-p3b3-worker-runtime.php` | `P3B3-HANDLER-REGISTRY-EXACT-CAPTURE-AND-LEDGER` |
| `ACTIVATION-CURSOR-KEY-NONEXPOSURE` | `tests/archive/test-p3b3-worker-runtime.php` | `P3B3-CURSOR-KEY-NOT-REUSED-OR-EXPOSED` |
| `ACTIVATION-D16-CONTROLLED-RETRY-REJECTED` | `tests/archive/test-p3b3-worker-runtime.php` | `P3B3-D16-RETRY-TASK-NOT-CLAIMED` |
| `ACTIVATION-D16-PRODUCTION-BLOCKED` | `tests/archive/test-p3b3-activation-gates.php` | `P3B3-PRODUCTION-ACTIVATION-BLOCKED-BY-CERTIFICATE-PACKET-VERIFY-D16` |
| `ACTIVATION-D16-RETRY-TASK-NOT-INSTALLED` | `tests/archive/test-p3b3-worker-runtime.php` | `P3B3-D16-RETRY-TASK-NOT-CLAIMED` |
| `ACTIVATION-ENTRYPOINT-REFERENCE-UNCHANGED` | `tests/archive/test-p3b3-runtime-composition.php` | `P3B3-ENTRYPOINT-ONE-ARCHIVE-REFERENCE` |
| `ACTIVATION-FLAGS-NO-INTERMEDIATE-ADMISSION` | `tests/archive/test-p3b3-activation-gates.php` | `P3B3-FLAGS-EXACT-STRING-VALUES` |
| `ACTIVATION-FLAGS-OFF-PREFLIGHT-NONREGISTERING` | `tests/archive/test-p3b3-activation-contracts.php` | `ACTIVATION-FLAGS-OFF-PREFLIGHT-NONREGISTERING` |
| `ACTIVATION-FLAGS-RECHECK-BEFORE-CLAIM` | `tests/archive/test-p3b3-activation-concurrency.php` | `ACTIVATION-FLAGS-REREAD-BEFORE-CLAIM` |
| `ACTIVATION-KILL-SWITCH-NO-DATA-MUTATION` | `tests/archive/test-p3b3-activation-gates.php` | `P3B3-ROLLBACK-PRESERVES-RETAINED-DATA` |
| `ACTIVATION-MULTISITE-NO-NETWORK-ACTIVATION` | `tests/archive/test-p3b3-activation-gates.php` | `P3B3-NETWORK-ACTIVATION-REMAINS-DARK` |
| `ACTIVATION-MULTISITE-NO-SWITCH-LOOP` | `tests/archive/test-p3b3-multisite.php` | `P3B3-MIXED-BLOG-OR-SWITCHED-CONTEXT-REJECTED` |
| `ACTIVATION-MULTISITE-PER-BLOG-AUTHORITY` | `tests/archive/test-p3b3-multisite.php` | `P3B3-BLOG-ID-PREFIX-TABLE-DESCRIPTOR-EXACT` |
| `ACTIVATION-NO-CERTIFICATE-PACKET-D16-HANDLER` | `tests/archive/test-p3b3-worker-runtime.php` | `P3B3-HANDLER-REGISTRY-EXACT-CAPTURE-AND-LEDGER` |
| `ACTIVATION-OUTPUT-RAW-PATH-EXCEPTION-NOT-LEAKED` | `tests/archive/test-p3b3-activation-gates.php` | `P3B3-STDERR-EXCEPTION-AND-PATH-LEAKAGE-NOT-EXPORTED` |
| `ACTIVATION-PACKET-HANDLER-ABSENT` | `tests/archive/test-p3b3-worker-runtime.php` | `P3B3-HANDLER-REGISTRY-EXACT-CAPTURE-AND-LEDGER` |
| `ACTIVATION-PARITY-DEPENDENCY-DESCRIPTORS-EXACT` | `tests/archive/test-p3b3-activation-persistence.php` | `ACTIVATION-PARITY-DEPENDENCY-DESCRIPTORS-EXACT` |
| `ACTIVATION-PARITY-E07-BYTES-EXACT` | `tests/archive/test-p3b3-activation-persistence.php` | `ACTIVATION-PARITY-E07-BYTES-EXACT` |
| `ACTIVATION-PARITY-E08-DIGEST-EXACT` | `tests/archive/test-p3b3-activation-persistence.php` | `ACTIVATION-PARITY-E08-DIGEST-EXACT` |
| `ACTIVATION-PARITY-MUTATION-DRIFT-FENCED` | `tests/archive/test-p3b3-activation-persistence.php` | `ACTIVATION-PARITY-MUTATION-DRIFT-FENCED` |
| `ACTIVATION-PREFLIGHT-CODE-ENFORCEABLE-GATES-ONLY` | `tests/archive/test-p3b3-activation-contracts.php` | `ACTIVATION-PREFLIGHT-CODE-ENFORCEABLE-GATES-ONLY` |
| `ACTIVATION-PREFLIGHT-HOST-EVIDENCE-OUTSIDE-MODULE` | `tests/archive/test-p3b3-activation-contracts.php` | `ACTIVATION-PREFLIGHT-CODE-ENFORCEABLE-GATES-ONLY` |
| `ACTIVATION-RESET-FLAG-OFF` | `tests/archive/test-p3b3-activation-gates.php` | `P3B3-RESET-FLAG-ABSENT-OR-ZERO` |
| `ACTIVATION-REVIEW-CALLER-FINGERPRINT-REJECTED` | `tests/archive/test-p3b3-activation-persistence.php` | `ACTIVATION-REVIEW-CALLER-FINGERPRINT-REJECTED` |
| `ACTIVATION-REVIEW-FENCE-PRESERVED` | `tests/archive/test-p3b3-activation-persistence.php` | `ACTIVATION-REVIEW-FENCE-PRESERVED` |
| `ACTIVATION-REVIEW-INTAKE-OWNS-READ-DIGEST-UOW-CHECKPOINTS` | `tests/archive/test-p3b3-activation-persistence.php` | `ACTIVATION-REVIEW-INTAKE-SERVER-FACTS-AND-CHECKPOINTS` |
| `ACTIVATION-REVIEW-MODULE-CALLS-PRIVATE-COMPOSER` | `tests/archive/test-p3b3-worker-runtime.php` | `P3B3-REVIEW-CAPTURE-ONE-PRIVATE-COMPOSITION-RECIPE` |
| `ACTIVATION-REVIEW-MODULE-OWNS-PRECOMPOSE-CHECKPOINT` | `tests/archive/test-p3b3-activation-contracts.php` | `ACTIVATION-REVIEW-MODULE-PRECOMPOSE-CHECKPOINT-ORDER` |
| `ACTIVATION-REVIEW-NO-CACHE-SUBSTITUTION` | `tests/archive/test-p3b3-activation-persistence.php` | `ACTIVATION-PARITY-MUTATION-DRIFT-FENCED` |
| `ACTIVATION-REVIEW-PRIVATE-COMPOSER-NOT-EXPOSED` | `tests/archive/test-p3b3-worker-runtime.php` | `P3B3-REVIEW-CAPTURE-ONE-PRIVATE-COMPOSITION-RECIPE` |
| `ACTIVATION-REVIEW-USES-C11-COMPOSITION` | `tests/archive/test-p3b3-activation-contracts.php` | `ACTIVATION-REVIEW-USES-C11-COMPOSITION` |
| `ACTIVATION-STDOUT-CANONICAL-NINE-FIELDS` | `tests/archive/test-p3b3-activation-gates.php` | `P3B3-CLI-OUTPUT-EXACT-BOUNDED-SHAPE` |
| `ACTIVATION-STDOUT-EXACT-ONE-LINE` | `tests/archive/test-p3b3-activation-gates.php` | `P3B3-HOST-REJECTS-MULTIPLE-STDOUT-LINES` |
| `ACTIVATION-STDOUT-WARNING-CONTAINED` | `tests/archive/test-p3b3-activation-gates.php` | `P3B3-WORKER-INJECTED-WARNING-DOES-NOT-CONTAMINATE-STDOUT` |
| `ACTIVATION-STORAGE-THRESHOLD-ABOVE-ACCEPTED` | `tests/archive/test-p3b3-activation-contracts.php` | `ACTIVATION-STORAGE-THRESHOLD-ABOVE-ACCEPTED` |
| `ACTIVATION-STORAGE-THRESHOLD-EQUALITY-ACCEPTED` | `tests/archive/test-p3b3-activation-contracts.php` | `ACTIVATION-STORAGE-THRESHOLD-EQUALITY-ACCEPTED` |
| `ACTIVATION-STORAGE-THRESHOLD-ONE-BYTE-BELOW-REJECTED` | `tests/archive/test-p3b3-activation-contracts.php` | `ACTIVATION-STORAGE-THRESHOLD-ONE-BYTE-BELOW-REJECTED` |

### `RETAINED_PASS` mappings

| Requirement | Suite | Executed check ID |
|---|---|---|
| `ACTIVATION-CERTIFICATE-REFERENCE-B10-UNCHANGED` | `tests/archive/test-p3b2b-evidence-source.php` | `P3B2B-CERTIFICATE-REFERENCE-INDEPENDENT-GOLDEN` |
| `ACTIVATION-D16-ORIGINAL-TASK-RECOVERY-PRESERVED` | `tests/archive/test-p3b2a-evidence-persistence.php` | `P3B2A-ATTEMPT5-DETERMINISTIC-RECOVERY` |
| `ACTIVATION-DISABLE-COMMITTED-OBJECTS-UNCHANGED` | `tests/archive/test-p3-storage.php` | `ARTIFACT-COMMIT-NEVER-OVERWRITES` |
| `ACTIVATION-DISABLE-ORPHAN-REPORT-ONLY` | `tests/archive/test-p3-storage.php` | `ORPHAN-REPORT-ONLY-NO-MUTATION` |
| `ACTIVATION-DISABLE-RETAINED-ROWS-UNCHANGED` | `tests/archive/test-p3b3-activation-gates.php` | `P3B3-ROLLBACK-PRESERVES-RETAINED-DATA` |
| `ACTIVATION-ORPHAN-REMAINS-REPORT-ONLY` | `tests/archive/test-p3-storage.php` | `ORPHAN-REPORT-ONLY-NO-MUTATION` |
| `ACTIVATION-RETAINED-P3B3-SUITE-NAMES-EXACT` | `tests/archive/test-p3b3-activation-gates.php` | `P3B3-RUNNER-SUITES-EXACTLY-ONCE` |
| `ACTIVATION-ROLLBACK-LEASE-FENCE-PRESERVED` | `tests/archive/test-p3b3-worker-runtime.php` | `P3B3-STALE-WORKER-CANNOT-OUTCOME-OR-COMPLETE` |
| `ACTIVATION-ROLLBACK-NO-LIFECYCLE-INVENTION` | `tests/archive/test-p3b2a-evidence-persistence.php` | `P3B2A-OPERATIONAL-FAILURES-NO-LIFECYCLE` |
| `ACTIVATION-ROLLBACK-RECEIPT-REPLAY` | `tests/archive/test-p3b2a-evidence-persistence.php` | `P3B2A-RESPONSE-LOSS-REPLAY` |
| `ACTIVATION-SCHEDULER-DATABASE-LEASES-AUTHORITATIVE` | `tests/archive/test-p3-worker.php` | `TASK-TWO-CONNECTION-LEASE-RACE` |
| `ACTIVATION-SOURCE-ARCHIVE-CONNECTIONS-DISTINCT` | `tests/archive/test-p3b3-runtime-composition.php` | `P3B3-EVIDENCE-CONNECTION-DISTINCT` |
| `ACTIVATION-SOURCE-ARCHIVE-READS-DENIED` | `tests/archive/test-p3b2b-evidence-source-persistence.php` | `P3B2B-RESTRICTED-SOURCE-PRINCIPAL-CANNOT-WRITE-OR-READ-ARCHIVE` |
| `ACTIVATION-SOURCE-CONNECTION-CLOSED` | `tests/archive/test-p3b3-activation-contracts.php` | `ACTIVATION-SOURCE-CONNECTION-CLOSED` |
| `ACTIVATION-SOURCE-GRANTS-EXACT-EIGHT` | `tests/archive/test-p3b2b-evidence-source.php` | `P3B2B-EXACT-USAGE-AND-SEVEN-TABLE-SELECT-GRANTS-ONLY` |
| `ACTIVATION-SOURCE-GRANT-SHAPES-REJECTED` | `tests/archive/test-p3b2b-evidence-source.php` | `P3B2B-EXACT-USAGE-AND-SEVEN-TABLE-SELECT-GRANTS-ONLY` |
| `ACTIVATION-SOURCE-PREFLIGHT-NO-EVIDENCE-QUERY` | `tests/archive/test-p3b3-activation-contracts.php` | `ACTIVATION-SOURCE-PREFLIGHT-NO-EVIDENCE-QUERY` |
| `ACTIVATION-SOURCE-WRITES-DENIED` | `tests/archive/test-p3b2b-evidence-source-persistence.php` | `P3B2B-RESTRICTED-SOURCE-PRINCIPAL-CANNOT-WRITE-OR-READ-ARCHIVE` |
| `ACTIVATION-STORAGE-PRIVATE-PUBLIC-DISJOINT` | `tests/archive/test-p3b3-worker-runtime.php` | `P3B3-PRIVATE-ROOT-OUTSIDE-ALL-PUBLIC-ROOTS` |

### `DEFERRED_OPERATOR_EVIDENCE` requirements

- `ACTIVATION-ALLOWLIST-EXACT`
- `ACTIVATION-APPROVALS-THREE-SEPARATE`
- `ACTIVATION-ATTEST-FILE-DIGEST-EVIDENCE`
- `ACTIVATION-AUTHORIZATION-DIGEST-BINDS-HUMAN-APPROVAL`
- `ACTIVATION-AUTHORIZATION-REMOVED-ON-ROLLBACK`
- `ACTIVATION-BACKUP-RESTORE-DATABASE-OBJECT-CONSISTENCY`
- `ACTIVATION-CONTROLLED-25-REVISION-CEILING`
- `ACTIVATION-CONTROLLED-CERTIFICATE-CASES-EXCLUDED`
- `ACTIVATION-CONTROLLED-ONE-BLOG-ONLY`
- `ACTIVATION-CONTROLLED-ONE-WORKER`
- `ACTIVATION-CONTROLLED-STOP-CONDITIONS`
- `ACTIVATION-CONTROLLED-WINDOW-BOUND`
- `ACTIVATION-CURSOR-ROTATION-FLAGS-OFF`
- `ACTIVATION-DECISION-APPROVAL-NO-SITE-AUTHORITY`
- `ACTIVATION-EVIDENCE-CANONICAL-64K`
- `ACTIVATION-EVIDENCE-FIELD-ALLOWLIST`
- `ACTIVATION-EVIDENCE-PII-SECRET-PATH-ABSENT`
- `ACTIVATION-EVIDENCE-RAW-STDERR-ABSENT`
- `ACTIVATION-EVIDENCE-RETAINED-PAYLOAD-ABSENT`
- `ACTIVATION-FLAGS-ORDER-DISABLE`
- `ACTIVATION-FLAGS-ORDER-ENABLE`
- `ACTIVATION-MANUAL-PREFLIGHT-SCHEDULER-DISABLED`
- `ACTIVATION-MULTISITE-SEQUENTIAL-CANARY`
- `ACTIVATION-NO-SCHEMA-METADATA-NETWORK`
- `ACTIVATION-PACKET-GOLDEN-VECTOR-GATE`
- `ACTIVATION-PACKET-RENDERER-OWNER-GATE`
- `ACTIVATION-PACKET-SEALED-INPUTS-ONLY`
- `ACTIVATION-PRODUCTION-CERTIFICATE-PACKET-VERIFY-GATED`
- `ACTIVATION-PRODUCTION-CONTROLLED-EVIDENCE-INSUFFICIENT`
- `ACTIVATION-PRODUCTION-D16-GATED`
- `ACTIVATION-PRODUCTION-EVIDENCE-COMPLETE`
- `ACTIVATION-PRODUCTION-RPO-RTO-EVIDENCE`
- `ACTIVATION-ROLLBACK-REAUTHORIZATION-REQUIRED`
- `ACTIVATION-ROLLBACK-TRIGGER-CLOSED`
- `ACTIVATION-SCHEDULER-110-SECOND-TIMEOUT`
- `ACTIVATION-SCHEDULER-24-HOUR-SOAK`
- `ACTIVATION-SCHEDULER-BACKLOG-FIVE-RUNS`
- `ACTIVATION-SCHEDULER-CONTROLLED-ONE-ACTIVE`
- `ACTIVATION-SCHEDULER-EXACT-COMMAND`
- `ACTIVATION-SCHEDULER-MISSED-THREE-MINUTES`
- `ACTIVATION-SCHEDULER-ONE-MINUTE`
- `ACTIVATION-SCHEDULER-PRODUCTION-MAX-FIVE-ACTIVE`
- `ACTIVATION-SCHEDULER-URL-IS-BOOTSTRAP-SELECTOR`
- `ACTIVATION-STDERR-ORDINARY-LOG-EXCLUDED`
- `ACTIVATION-STORAGE-CONTROLLED-THRESHOLD-EXACT`
- `ACTIVATION-STORAGE-LOW-DISK-BLOCKED`
- `ACTIVATION-STORAGE-PERMISSIONS-CLOSED`
- `ACTIVATION-STORAGE-PRODUCTION-CAPACITY-GATED`
- `ACTIVATION-VERIFY-FINALIZE-ATOMIC`
- `ACTIVATION-VERIFY-RECEIPT-REPLAY`
- `ACTIVATION-VERIFY-SEALED-AUTHORITY-RELOAD`

## Evidence-accounting remediation verification

| Verification | PHP 8.3.30 | PHP 8.5.7 |
|---|---:|---:|
| Activation contracts | 34 PASS | 34 PASS |
| Activation contracts under `php -n` | 34 PASS | 34 PASS |
| Activation concurrency | 6 PASS | 6 PASS |
| Activation concurrency under `php -n` | 6 PASS | 6 PASS |
| P3/P3B boundaries | 18 PASS | 18 PASS |
| P3 digests | 9 PASS | 9 PASS |
| Changed PHP lint | 2 PASS | 2 PASS |

All eight evidence-oracle meta-regressions passed: missing check, missing suite, duplicate runner occurrence, self-reference, condition literal, deferred-not-counted, duplicate requirement, and exact 122-member classification. An independent static comparison found 71 identical requirement/suite/check mappings in the test and this report, 51 identical deferred requirements, exactly one runner occurrence for each of 12 referenced suites, zero self-referential supporting checks, and zero deferred executable mappings. `git diff --check`, UTF-8, and trailing-whitespace checks passed. The current definitive database matrix passed all six cells at 1,321 assertions per cell and 7,926 assertions overall.

## Remaining production and operator gates

Constructed-dark implementation is complete, but controlled current-site testing and production activation remain blocked by:

1. separate exact current-site controlled-test authorization and supplied protected-file/operator/window facts;
2. deployed code attestation, endpoint, grants, storage root/ACL/capacity, cursor key, and representative sanitized evidence;
3. operator-owned host scheduler, overlap ceiling, alert, restricted incident stderr, backup, restore, RPO, and RTO evidence;
4. certificate acquisition, packet materialization, and verification/finalization contracts and accepted implementations;
5. an owner-selected and compatibility-reviewed D16 lifecycle model;
6. A19 evidence acceptance; and
7. separate exact production authorization per blog.

There are no implementation deviations or unresolved code-contract contradictions inside the authorized constructed-dark A18 scope. No formal acceptance is claimed by this report.
