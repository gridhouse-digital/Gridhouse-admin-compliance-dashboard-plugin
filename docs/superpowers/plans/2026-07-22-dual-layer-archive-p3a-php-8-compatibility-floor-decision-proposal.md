# P3A PHP 8.0 Compatibility-Floor Decision Proposal

**Status:** superseded before implementation by owner-approved Decision C2
**Scope:** compatibility policy, distribution metadata, verification runner, and P3A/H1 traceability only
**Runtime effect before approval:** none
**Production/archive behavior change:** none authorized

## Supersession record

The repository owner superseded Decision C1 before any PHP 8.0 compatibility file was modified and directed that PHP 8.0 must not be downloaded, installed, or executed. C1 is retained below as historical decision context only. Owner-approved Decision C2 establishes PHP 8.3 as the distributed plugin and Dual-Layer Archive minimum, with PHP 8.3.30 and PHP 8.5.7 as the required verification runtimes.

## Confirmed acceptance gate

P3A and H1 pass re-review with no remaining actionable correctness or security finding. Formal acceptance is blocked only because the current authoritative floor requires PHP 7.4 execution and no PHP 7.4.33 CLI is available. PHP 8.3 execution is not evidence that PHP 8.0 or PHP 7.4 works.

The client hosting floor is PHP 8.0. Aligning the project and plugin-package floor with that supported environment is the smallest durable resolution, provided the complete suite passes on an actual PHP 8.0 CLI.

## Decision C1: raise the minimum supported PHP version to 8.0

### Recommended contract

1. Raise the Dual-Layer Archive and distributed plugin minimum from PHP 7.4 to PHP 8.0.
2. Require an actual PHP 8.0 CLI for acceptance. The process must verify `PHP_MAJOR_VERSION === 8` and `PHP_MINOR_VERSION === 0`; PHP 8.3 is not a substitute.
3. Preserve mandatory verification on PHP 8.3.30 and PHP 8.5.7.
4. Continue reporting PHP 8.4 as unavailable unless a real `php.exe` is found. PHP 8.4 is not added to the required matrix.
5. Preserve all existing database, storage, concurrency, dark-mode, and safety requirements. Raising the floor does not authorize PHP 8-only refactors or archive behavior changes.
6. Locate an existing trusted PHP 8.0 CLI first. If none exists, stop and obtain separate authorization before downloading or executing an external runtime or pulling a Docker image.

### Definitive acceptance matrix

Run PHP 8.0, 8.3, and 8.5 against each disposable loopback target:

| Database target | Port | Required database family |
|---|---:|---|
| `ghca-mysql-8.0` | 33061 | MySQL 8.0 |
| `ghca-mysql-8.4` | 33062 | MySQL 8.4 |
| `ghca-mariadb-10.6` | 33063 | MariaDB 10.6 |

Every one of the nine cells must pass:

- schema: 55;
- P1 persistence: 358;
- P2 side records: 263;
- P3 digests: 9;
- P3 worker/concurrency: 67; and
- P3 storage/orphans: 88.

The frozen total is 840 assertions per cell and 7,560 database-matrix assertions overall. Every database name must retain the `ghca_acd_archive_test_` safety prefix and use only environment-supplied disposable credentials.

On PHP 8.0, 8.3, and 8.5, also require 1,246 kernel checks, 25 legacy checks, 11 boundary checks, lint of all 68 archive/test PHP files, `git diff --check`, and the existing forbidden runtime-wiring, current-site bootstrap/credential, schema, network, debug, immutable-row mutation, and hardcoded/runtime-loaded cursor-key scans.

## Authoritative PHP 7.4 inventory

### Active compatibility contracts to amend after approval

| File | Current declaration | Approved Phase 2 amendment |
|---|---|---|
| `docs/superpowers/plans/2026-07-13-dual-layer-archive-event-sourcing-technical-design.md` | Lines 66, 920, and 1534 preserve PHP 7.4 syntax/runtime coverage and require PHP 7.4 in CI | Replace the minimum and minimum-runtime matrix member with PHP 8.0; retain current-runtime coverage |
| `docs/superpowers/plans/2026-07-13-dual-layer-archive-development-handoff.md` | Lines 76, 84, 133, 169, 294, and 327 declare PHP 7.4 support, syntax, golden-vector, stop-gate, and reporting requirements | Add a clear C1 amendment and update those active compatibility requirements to PHP 8.0 and actual-runtime evidence |
| `README.md` | Line 6 declares `PHP 7.4+` | Change to `PHP 8.0+` |
| `gridhouse-admin-compliance-dashboard.php` | The plugin header has no `Requires PHP` field | Add metadata-only `Requires PHP: 8.0` |
| `tests/archive/test-all.ps1` | Runtime list starts with `%TEMP%\ghca-php-7.4.33-nts-x64\php.exe`; a PHP 7.4-specific invocation branch supplies `mysqli` | Replace the first runtime with the verified PHP 8.0 CLI and use the verified PHP 8.0 invocation needed by that CLI |
| `tests/archive/test-p3-digests.php` | The approved runtime assertion accepts `7.4`, `8.3`, and `8.5` | Replace `7.4` with `8.0` |
| `docs/superpowers/plans/2026-07-18-dual-layer-archive-slice-1b-p3-worker-decisions-proposal.md` | Lines 162 and 309 require P3 vectors/negative tests on PHP 7.4.33, 8.3.30, and 8.5.7 | Replace the required minimum runtime with the actual PHP 8.0 CLI while retaining 8.3/8.5 |
| `docs/superpowers/plans/2026-07-19-dual-layer-archive-slice-1b-p3-traceability.md` | Current status, compatibility statement, tables, and unresolved-contract section retain PHP 7.4 as the acceptance blocker | Record C1 approval, the actual 3x3 results, PHP 8.4 unavailability, and formal re-review status |
| `docs/superpowers/plans/2026-07-21-dual-layer-archive-slice-1b-p3a-cursor-authentication-decision-proposal.md` | Lines 64 and 125 cite PHP 7.4 availability/verification | Amend the compatibility statement and record the actual PHP 8.0 H1 results |
| `docs/superpowers/plans/2026-07-22-dual-layer-archive-p3a-php-8-compatibility-floor-decision-proposal.md` | This pending proposal | Mark approved and append exact runtime/matrix evidence only after every gate passes |

No archive production behavior file, schema file, migration, event contract, persisted row, artifact byte, or cursor format needs modification.

### Historical evidence to retain unchanged

These records truthfully describe earlier PHP 7.4 executions or portability work and must remain historical rather than being rewritten:

- `docs/superpowers/plans/2026-07-13-dual-layer-archive-slice-1a-traceability.md` lines 242, 245, and 295-301;
- `docs/superpowers/plans/2026-07-15-dual-layer-archive-slice-1b-traceability.md` lines 33-35, 74, and 78;
- `docs/superpowers/plans/2026-07-16-dual-layer-archive-slice-1b-p1-traceability.md` line 49; and
- `docs/superpowers/plans/2026-07-17-dual-layer-archive-slice-1b-p2-traceability.md` lines 70, 83, and 102.

The Architecture PRD contains no PHP compatibility floor: its `7.4` occurrence is the heading `7.4 Verification and finalization`, not a version declaration. The implementation-decisions and P1-contract-decisions records also contain no PHP floor.

## Distribution and WordPress implications

- `Requires PHP: 8.0` applies to the whole plugin package, not only the dark archive subtree. WordPress will treat the release as incompatible on PHP versions below 8.0 and prevent normal activation/update eligibility there.
- Existing sites below PHP 8.0 must upgrade PHP before receiving/activating a release carrying this metadata. This is consistent with the stated client hosting floor but is a release-policy change and requires owner approval.
- `README.md` is the only current human-readable package requirement. There is no WordPress.org `readme.txt`, Composer platform constraint, PHPUnit/PHPCS configuration, GitHub Actions workflow, Travis configuration, CircleCI configuration, or AppVeyor configuration to update.
- No runtime version guard, hook, activation routine, compatibility shim, dependency, or service container is needed. WordPress metadata plus the verified runner is sufficient.
- The plugin version is not changed in P3A; release/versioning remains outside this checkpoint.

## Retained-data and operational impact

This decision changes compatibility and release policy only. It changes no schema, event, snapshot, task, descriptor, ledger item, artifact key, artifact byte, HMAC input, or lifecycle behavior. Historical PHP 7.4 verification remains valid evidence for the code state that was tested, but PHP 7.4 is no longer a required acceptance runtime after C1 approval.

Lowering the floor later would require a new compatibility decision and real lower-runtime verification. Merely changing metadata back would not prove support.

## Phase 2 stop conditions

Stop without modifying compatibility files if:

- the branch or HEAD differs;
- no trusted local PHP 8.0 CLI exists and external-runtime authorization has not been granted;
- the located CLI is not actually PHP 8.0.x or lacks the required `mysqli` capability;
- any of the nine database cells fails or reports a count other than 840;
- any kernel, legacy, boundary, lint, diff, or forbidden-surface gate fails; or
- testing would require current-site credentials, current-site database access, runtime wiring, activation, staging, commit, push, or P3B work.

## Historical owner decision request — superseded

**Approve raising the Dual-Layer Archive compatibility floor from PHP 7.4 to PHP 8.0 and replacing PHP 7.4 with an actual PHP 8.0 runtime in the mandatory verification matrix.**

This request is no longer active. Decision C2 superseded it before implementation.
