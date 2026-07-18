# P3A PHP 8.3 Compatibility-Floor Decision

**Status:** owner-approved, implemented, verified, and formally accepted
**Decision:** C2
**Supersedes:** C1 before implementation
**Scope:** compatibility policy, plugin metadata, required verification runtimes, and P3A/H1 traceability only
**Runtime effect:** none; P3A remains dark and unwired

## Approved contract

- Distributed plugin and Dual-Layer Archive minimum: PHP 8.3.
- Required runtimes: exact PHP 8.3.30 and PHP 8.5.7 CLIs.
- Required database matrix: both runtimes against MySQL 8.0, MySQL 8.4, and MariaDB 10.6.
- Required database-cell result: 840 assertions; required total: 5,040.
- Additional per-runtime results: 1,246 kernel, 25 legacy, 11 boundary, and lint of all 68 archive/test PHP files.
- PHP 8.4 is optional and must be reported unavailable while no CLI exists.
- Historical PHP 7.4 evidence remains unchanged.
- Raising the floor authorizes no PHP 8.3 refactor, archive behavior change, schema change, runtime wiring, activation, deployment, staging, commit, push, or P3B work.

## Distribution effect

`README.md` declares PHP 8.3+ and the plugin header declares `Requires PHP: 8.3`. This applies to the complete distributed plugin: WordPress installations below PHP 8.3 must upgrade PHP before normal activation/update eligibility for a release carrying this metadata.

## Verification evidence

The exact local CLIs were verified before execution:

- `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`: PHP 8.3.30, `mysqli` available.
- `C:\laragon\bin\php\php-8.5.7-nts-Win32-vs17-x64\php.exe`: PHP 8.5.7, `mysqli` available.
- `C:\laragon\bin\php\php-8.4.12-nts-Win32-vs17-x64\php.exe`: absent; PHP 8.4 remains optional and unavailable, and no result is claimed.

One definitive 897.1-second runner invocation passed all six disposable database cells:

| Runtime | MySQL 8.0.46 (`127.0.0.1:33061`) | MySQL 8.4.10 (`127.0.0.1:33062`) | MariaDB 10.6.27 (`127.0.0.1:33063`) |
|---|---:|---:|---:|
| PHP 8.3.30 | 840 PASS | 840 PASS | 840 PASS |
| PHP 8.5.7 | 840 PASS | 840 PASS | 840 PASS |

Each cell ran 55 schema, 358 P1 persistence, 263 P2 side-record, 9 P3 digest, 67 P3 worker, and 88 P3 storage/orphan assertions. The definitive database total is 5,040 assertions. Every target used the disposable `ghca_acd_archive_test_db` name and environment-supplied credentials; neither current-site configuration nor the current-site database was loaded.

Additional required verification passed independently on both runtimes:

| Gate | PHP 8.3.30 | PHP 8.5.7 |
|---|---:|---:|
| Kernel | 1,246 PASS | 1,246 PASS |
| Legacy | 25 PASS | 25 PASS |
| P3A boundaries | 11 PASS | 11 PASS |
| Archive production/test lint | 68 files PASS | 68 files PASS |

`git diff --check` passed. Static scans found zero changed-surface runtime hooks, cron/Action Scheduler/WP-CLI/REST wiring, network calls, current-site bootstrap/credential use, debug output, hardcoded/runtime-loaded cursor keys, immutable-row mutations, or schema-file changes. The plugin entrypoint remains free of archive references; its only C2 change is distribution metadata.

No PHP 8.0 runtime was downloaded, installed, or executed. No PHP 8.3 refactor or archive behavior change was introduced. The branch remained `feature/dual-layer-archive-slice-1b-p3-workers` at `aa64119f3256e2e681f9f6e96654450d33a97fb2` through acceptance verification, with zero staged files and no commit or push at that checkpoint. Independent owner review formally accepted Decision C2 as part of P3A.
