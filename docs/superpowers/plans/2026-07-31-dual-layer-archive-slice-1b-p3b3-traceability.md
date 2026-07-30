# Slice 1B-P3B3 Constructed-Dark Runtime Composition Traceability

**Date:** 2026-07-31
**Status:** formally accepted for the constructed-dark P3B3 scope
**Branch:** `feature/dual-layer-archive-slice-1b-p3b3-runtime-composition`
**Proposal checkpoint and implementation starting/ending HEAD:** `d9d819bbede0371e09a88ab2b09f2b05080dc80b`

## Scope delivered

P3B3 adds the owner-approved C23 composition root, validated archive bootstrap, direct runtime authorities, isolated source-account grant validation, closed worker-result grammar, and dormant operator-command boundary. The entrypoint constructs the archive module in dark mode only. It performs no current-site read or write, opens no database or network connection, registers no hook or command, and returns `blocked/archive_runtime_disabled/flags`.

The implementation does not enable controlled testing or production activation. Review/capture parity, certificate acquisition, packet materialization, verification/finalization, D16 partial-artifact retry, host-cron evidence, representative installed-code/storage/secret evidence, current-site testing, and production activation remain separately owner-gated.

## Owner-approved retained-test amendment

After the first definitive matrix attempt exposed two retained entrypoint assertions that still required zero archive references, the owner authorized changes only to `tests/archive/test-persistence.php` and `tests/archive/test-side-record-persistence.php`.

Each retained assertion now:

- requires `substr_count()` of the exact statement `require_once __DIR__ . '/includes/archive/bootstrap.php';` to equal one;
- removes that exact statement and requires zero remaining case-insensitive archive references;
- preserves its existing test identifier; and
- preserves every prohibition on hooks, filters, activation hooks, cron, schedulers, transients, `wp-load.php`, `wp-config.php`, current-site bootstrap, and enabled feature flags.

No other part of either retained test changed.

## Formal-review remediation

The first formal review withheld acceptance on three findings. This pass makes only these root-cause corrections:

- `cli_run()` now enters one private executable path that performs admission before composition, constructs the approved worker graph once, performs admission again immediately before work, and calls `run_once()` exactly once. The production admission probe still blocks on the retained parity/D16 gates, so constructed-dark behavior is unchanged. The retained named regression invokes the actual private module path and proves two admissions, one composition, one worker call, and zero worker calls when the second admission fails.
- Tenant provisioning now requires the exact single-column, non-prefix, unique BTREE `option_name` index before reading or inserting. A failed insert is accepted as a race only when the same connection's closed `SHOW ERRORS` diagnostic is error 1062; every other insert failure fails closed. Disposable regressions remove/restore the index, inject a non-duplicate trigger failure, and hold a real table read lock until two independent PHP processes are simultaneously blocked at their tenant `INSERT`. Releasing the lock proves one immutable winner and duplicate-key readback.
- The exact C02 graph now constructs and retains one `GHCA_ACD_Archive_Orphan_Reconciler` from the validated private store, artifact repository, and clock. It remains report-only; no call, handler, hook, command, scheduler, controller, or other surface was added.

## Files added

- `includes/archive/bootstrap.php`
- `includes/archive/class-archive-module.php`
- `includes/archive/infrastructure/class-system-archive-clock.php`
- `includes/archive/infrastructure/class-random-archive-id-generator.php`
- `includes/archive/infrastructure/class-archive-code-version-attestor.php`
- `includes/archive/infrastructure/class-wordpress-archive-runtime-descriptor.php`
- `tests/archive/test-p3b3-runtime-composition.php`
- `tests/archive/test-p3b3-activation-gates.php`
- `tests/archive/test-p3b3-worker-runtime.php`
- `tests/archive/test-p3b3-multisite.php`
- `docs/superpowers/plans/2026-07-31-dual-layer-archive-slice-1b-p3b3-traceability.md`

## Files modified

- `docs/superpowers/plans/2026-07-30-dual-layer-archive-slice-1b-p3b3-runtime-composition-decisions-proposal.md`
- `gridhouse-admin-compliance-dashboard.php`
- `includes/archive/infrastructure/class-wpdb-archive-evidence-read-session.php`
- `tests/archive/bootstrap.php`
- `tests/archive/persistence-bootstrap.php`
- `tests/archive/test-all.ps1`
- `tests/archive/test-p3-boundaries.php`
- `tests/archive/test-p3b2b-evidence-source.php`
- `tests/archive/test-p3b2b-evidence-source-persistence.php`
- `tests/archive/test-persistence.php`
- `tests/archive/test-side-record-persistence.php`

All 22 files are inside C23 plus the owner-approved two-file retained-test amendment. The allowlisted `tests/archive/test-p3b2b-evidence-source-concurrency.php` did not require modification.

## Decision-to-code-to-test mapping

| Decision | Implementation | Principal executable evidence |
|---|---|---|
| C01 | Constructed-dark module state and retained activation blockers | default-dark, production-blocker, and no-active-surface checks |
| C02 | One `GHCA_ACD_Archive_Module` composition root, private dependency recipe, and dormant report-only orphan reconciler | single-root, one archive connection, distinct evidence connection, reconciler graph, and no-container checks |
| C03 | One entrypoint require and validate-all-before-require 55-file bootstrap | manifest/digest, path/symlink, load-order, and exact-entrypoint checks |
| C04 | Direct option-table flag/schema authority and migrator postflight | exact flag, direct-read, schema, stale-cache, and filter-spoof checks |
| C05 | Fail-closed ordered gates and pre-claim kill switch | executable two-admission module path, second-gate rejection, any-gate-fails, and in-flight-disable checks |
| C06 | Bounded installed-code attestor for the frozen version tuple | exact tuple, token grammar, spoof, path, size, and source-query ordering checks |
| C07 | Current-blog prefix resolution and immutable tenant authority | exact unique-index preflight, non-duplicate insert rejection, real two-process blocked-insert race, exact descriptor, and malformed/replacement/mixed-blog checks |
| C08 | Deployment-constant source credentials plus normalized unquoted account and exact eight grant rows | account representation, exact seven-table SELECT, denial, grant rejection, and secret-boundary checks |
| C09 | Explicit archive connection and private/public storage construction | one archive connection, no uploads fallback, root containment/change checks |
| C10 | Exact H1 cursor-key injection and rotation behavior | missing/invalid, non-exposure, exact injection, and rotation checks |
| C11 | One private review/capture recipe with independent instances | separate-invocation E07/E08 parity and production-intake blocker checks |
| C12 | Existing time-independent calculation v1 only | no-clock and versioned-amendment checks |
| C13 | Dormant one-task operator command, existing fencing, bounded worker call | executable module callback proves two admissions, one composition, exactly one `run_once()`, timeout, stale-worker, and host-output checks |
| C14 | Literal `capture_evidence` and `materialize_ledger` handler registry | exact registry and available/expired installed-type filtering checks |
| C15 | No self-bootstrap or caller-supplied tenant/table authority | no-current-site-bootstrap and task-authority checks |
| C16 | Only the dormant approved WP-CLI surface exists | boundary checks reject REST, admin, AJAX, WP-Cron, Action Scheduler, controllers, and other registration |
| C17 | Database transport only; certificate/network work deferred | no-network-or-certificate checks |
| C18 | D16 remains activation-blocking and retry tasks remain untouched | D16 not-claimed and production-blocker checks |
| C19 | Exact canonical nine-field result and isolated stdout/stderr boundary | bounded shape, counts, duration, warning/throwable, malformed/multiple-line, and leakage checks |
| C20 | Ordered canary/rollback/emergency-disable contracts remain dark | canary-order, retained-data rollback, and no-lifecycle emergency-disable checks |
| C21 | Per-blog isolation; network activation remains dark | multisite descriptor, switched-context, network-option, and network-activation checks |
| C22 | Existing task, lease, timeout, and one-work-unit ceilings reused | runner, fencing, query-budget, and concurrency checks |
| C23 | Exact mechanical allowlist plus the recorded retained-test amendment | changed-file audit and narrow two-file diff |
| C24 | 101 approved named regressions plus two formal-review provisioning regressions, with phase-aware negative oracles | all proposal names occur in executable tests; unique-index, non-duplicate failure, real-race, and negative-oracle checks |
| C25 | Permanent six-cell runner includes each P3B3 suite once | runner count scan and matrix below |
| C26 | Sanitized evidence shape implemented; real activation evidence remains unresolved | stdout privacy, secret/path leakage, and activation-blocker checks |
| C27 | Constructed-dark authorization only | zero current-site access, activation, deployment, stage, commit, or implementation push |

## Bootstrap and result-contract evidence

- Proposal and code manifests each contain 55 unique entries.
- Their LF-joined UTF-8 bytes are identical.
- Both produce SHA-256 `d2b3b45a3e421f3565e651348acac496805a349e272eae2b64aa9bee4570b094`.
- Bootstrap resolves and validates every path before its first `require_once`.
- The result encoder accepts only the exact ordered nine fields.
- `GHCA_ACD_Archive_Module::RESULT_MAP` is the sole closed runtime tuple catalog.
- Unknown pre-admission failures map to `blocked/archive_runtime_load_failed/load`.
- Unknown post-admission worker outcomes map to `blocked/archive_runtime_internal_failure/worker`.
- Lease expiry or worker death never creates a lifecycle event.

## Database verification

Every cell used an actual supported PHP CLI and one of the three authorized disposable containers. The database name matched `^ghca_acd_archive_test_[A-Za-z0-9_]+$`; credentials were process-local, identical container values were validated without disclosure, destructive opt-in was explicit, and restricted-source passwords were newly generated. No credential was printed, logged, persisted, or written to documentation.

The definitive `tests/archive/test-all.ps1` runner completed all six cells with exit code zero. Each of the four P3B3 suites occurs exactly once.

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
- P3B2b source: 52
- P3B2b source persistence: 4
- P3B2b source concurrency: 7
- P3B3 runtime composition: 25
- P3B3 activation gates: 30
- P3B3 worker runtime: 29
- P3B3 multisite: 19
- total: 1,272

| Runtime | MySQL 8.0.46 | MySQL 8.4.10 | MariaDB 10.6.27 |
|---|---:|---:|---:|
| PHP 8.3.30 | 1,272 PASS | 1,272 PASS | 1,272 PASS |
| PHP 8.5.7 | 1,272 PASS | 1,272 PASS | 1,272 PASS |

Database-matrix total: **7,632 executable checks**.

Two-connection and multi-process evidence included deterministic claim order, one lease winner, live-lease protection, stale-worker fencing, source snapshot consistency, tenant provisioning races, and isolated source/archive principals. The matrix emitted a single non-null lease token in every real two-connection lease-race cell.

## Runtime-only verification

| Verification | PHP 8.3.30 | PHP 8.5.7 |
|---|---:|---:|
| Slice 1A kernel plus accepted digest vectors | 1,246 / 14 suites PASS | 1,246 / 14 suites PASS |
| Legacy baseline | 25 / 2 suites PASS | 25 / 2 suites PASS |
| P3/P3B boundary suite | 17 PASS | 17 PASS |
| Standalone P3 digests | 9 PASS | 9 PASS |
| P3B2a evidence under `php -n` | 18 PASS | 18 PASS |
| P3B2b source under `php -n` | 52 PASS | 52 PASS |
| P3B3 runtime composition under `php -n` | 25 PASS | 25 PASS |
| P3B3 activation gates under `php -n` | 30 PASS | 30 PASS |
| P3B3 worker runtime under `php -n` | 29 PASS | 29 PASS |
| Archive production/test lint | 99 files PASS | 99 files PASS |

Database matrix plus kernel, legacy, and boundary evidence totals **10,208 assertions**. Standalone digest and `php -n` reruns duplicate retained coverage and are excluded from that aggregate. Lint covered **198 runtime/file combinations**.

PHP 8.4 remains optional and unavailable because no PHP 8.4 CLI exists. No PHP 8.4 result is claimed.

## Static and boundary evidence

- Exact entrypoint bootstrap statement count: one.
- Remaining case-insensitive entrypoint archive references after removing that statement: zero.
- Production WordPress hook/filter/activation registrations: zero.
- Production WP-Cron and Action Scheduler calls: zero.
- Production REST/admin/AJAX/controller surfaces: zero.
- Dormant `WP_CLI::add_command()` calls: exactly one; bootstrap never calls the registration method.
- `wp-load.php`, `wp-config.php`, global `$wpdb`, current-site request/environment authority, and current-site self-bootstrap references: zero.
- Production HTTP/socket/network calls and certificate acquisition: zero.
- Uploads-directory fallback or implicit public path: zero.
- Changed schema or migrator files: zero.
- New archive-row `UPDATE` or `DELETE` paths: zero.
- Debug output and hardcoded test/database credential names in production: zero.
- Each P3B3 runner suite occurs exactly once.
- All 101 approved regression names and both formal-review provisioning regressions occur in executable tests.
- `git diff --check`: PASS.

## Unresolved activation contracts

Constructed-dark implementation is complete, but controlled testing and production activation remain blocked by:

1. representative deployed-file C06 attestation evidence;
2. deployment-secret, endpoint, database, grant-denial, storage-root, and cursor-key evidence;
3. an approved review-time intake producer using the exact private C11 recipe;
4. certificate acquisition for certificate-required evidence;
5. packet materialization and verification/finalization handlers;
6. D16 partial-artifact retry resolution;
7. host scheduler reliability and strict stdout/stderr handling evidence;
8. separate owner authorization for current-site testing; and
9. separate owner authorization for production activation.

No retained business, event, digest, persistence, evidence, fencing, artifact, schema, or immutable-data contract was changed.

## Repository state

- Branch: `feature/dual-layer-archive-slice-1b-p3b3-runtime-composition`.
- Starting and ending HEAD: `d9d819bbede0371e09a88ab2b09f2b05080dc80b`.
- Staged files: zero.
- Implementation changes are unstaged and uncommitted.
- No implementation commit, push, fetch, pull, merge, rebase, deployment, activation, or current-site access occurred.
- `.claude/` remains the pre-existing untracked tree and was not accessed or modified.

## Re-review status

Status is **formally accepted for the constructed-dark P3B3 scope**. Controlled testing, production activation, and every later activation gate remain with the owner.
