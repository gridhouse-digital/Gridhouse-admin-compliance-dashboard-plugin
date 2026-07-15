# Development Handoff: Dual-Layer Archive Event-Sourcing

**Status:** Ready for delegated development, subject to the blocking decision gate below  
**Date:** 2026-07-13  
**Repository:** `C:\laragon\www\Gridhouse-Healthcare-Academy\wp-content\plugins\gridhouse-admin-compliance-dashboard`  
**Initial delivery:** Phase 0 decision closure, then Slice 1A pure event kernel only  
**Production/runtime enablement:** Not authorized by this handoff

## 1. Mission

Implement the approved Event Sourcing foundation for the Dual-Layer Archive without changing current plugin behavior.

Work in small reviewable slices. The first coding slice is a pure, deterministic event kernel in dark mode. It must not connect the new model to WordPress hooks, the Mark Reviewed endpoint, LearnDash mutations, PDF generation, background workers, the existing site database, or reset execution.

Do not attempt the full feature in one change.

## 2. Sources of truth, in priority order

Read each document completely before changing files:

1. `docs/superpowers/plans/PRD-Dual-Layer-Archive-State-Machine-Event-Log.md` — authoritative lifecycle, invariants, event meanings, and acceptance criteria.
2. `docs/superpowers/plans/2026-07-13-dual-layer-archive-event-sourcing-technical-design.md` — authoritative PHP boundaries, 13-table schema, canonical formats, transaction model, task fencing, projections, reset enforcement, tests, and gates.
3. `docs/superpowers/plans/PRD-Dual-Layer-Archive.md` — product context only where it does not conflict with items 1–2.
4. `docs/superpowers/plans/2026-07-10-dual-layer-archive-system.md` — earlier plan; superseded wherever it proposes mutable lifecycle status or conflicts with items 1–2.
5. The current plugin source — source of truth for integration behavior and compatibility, not for the new archive lifecycle.

If the parent Architecture PRD and technical design appear inconsistent, stop and report the exact sections. Do not resolve an immutable contract by personal judgment.

## 3. Authority and non-authority

This handoff authorizes local development and tests for the approved slices after their prerequisites pass. It does not authorize:

- production or current-site database migration;
- enabling archive/reset feature flags;
- destructive LearnDash reset calls;
- changing the current Mark Reviewed behavior;
- deployment, commit, push, pull request, or release;
- deleting or rewriting existing user work; or
- inventing unresolved persisted identities, event formats, retention rules, or data policies.

Do not modify unrelated files or the user-owned untracked `.claude/` directory. Preserve the two architecture documents even though they are currently untracked.

## 4. Blocking decision gate

Before creating persisted schema/domain contracts, obtain and record explicit approval for all six foundational decisions from Technical Design Section 21. Recommended answers are shown for decision-making; they are not silently approved by this handoff.

| Decision | Recommended answer | Gate |
|---|---|---|
| Case/cycle identity | Use the configured calendar-year or employee-anniversary audit policy through the single `GHCA_ACD_Archive_Cycle_Resolver`; remove fixed-365 logic from the archive path; freeze timezone, boundary inclusivity, policy key/version, and canonical cycle-key format. | Must be approved before Slice 1A case-key/cycle code. |
| Tenant model | One operational tenant per WordPress blog/site, with one immutable generated tenant UUID stored in `ghca_acd_archive_tenant_id`; LearnDash groups remain authorization scopes, not tenants. | Must be approved before case-key/schema code. |
| Data classification | Employment/training PII only; no PHI or PCI. If PHI is in scope, stop for a separate security/privacy design. | Must be approved before snapshot/storage code. |
| Snapshot completeness | Adopt Technical Design Section 10 as snapshot v1: explicit nulls, no current-time substitution, fixed policy/cycle, all required certificate bytes sealed before snapshot commit, and both layers bound to one snapshot digest. Establish explicit byte/item/depth limits from representative data before persistence. | Must be approved before snapshot/event payload validators are frozen. |
| Private storage | Require `GHCA_ACD_ARCHIVE_PRIVATE_DIR` outside the public document root for production; no uploads-directory production fallback and no public URLs/absolute paths in database records. | Must be approved before artifact/storage implementation. |
| Database support floor | Recommended feature floor: MySQL 8.0+ or MariaDB 10.6+, InnoDB, tested with the exact 13-table manifest. The schema still avoids MySQL-8-only features. | Must be approved before migration code is connected to runtime. |

Record approved answers in a dated implementation decision record under `docs/superpowers/plans/`. If any answer remains unresolved, complete read-only discovery and the decision record proposal, then stop before coding the affected persisted contract.

The proposed operating baseline remains:

- read/write ratio approximately 100:1;
- validation envelope of 100 reads/second and 5 lifecycle writes/second per site;
- up to 25,000 employees, 250,000 cases, 5 million events, and five artifact workers per site;
- PII sensitivity tier;
- lifecycle API availability target 99.9%;
- command p99 at or below 1,000 ms, excluding asynchronous artifact work;
- zero RPO for acknowledged primary event commits;
- disaster-recovery RPO at most 15 minutes and RTO at most 4 hours; and
- named Product Owner and Site Operations Owner required before production enablement.

If those assumptions change materially, stop for design review.

## 5. Repository facts and preflight

The current plugin:

- requires WordPress 6.0+, PHP 7.4+, and LearnDash 4.0+;
- uses manually loaded, global `final GHCA_*` classes and static `init()` bridges;
- has no Composer runtime or PHPUnit configuration;
- currently uses standalone PHP test scripts;
- has no custom archive tables, migration runner, durable queue, or event store;
- currently registers only roles in its activation hook; and
- must remain behaviorally unchanged during Slice 1A.

Current local PHP installations are 8.3, 8.4, and 8.5. PHP 7.4 is not locally installed, so PHP 7.4 compatibility requires an approved CI/container runner before the slice can be called complete.

`rg.exe` may fail with Access Denied in this Windows environment. Fall back immediately to `Get-ChildItem`, `Get-Content`, and `Select-String`.

Before changing anything:

1. run `git status --short` inside the plugin and preserve all pre-existing/untracked work;
2. read all sources in Section 2;
3. inspect the current plugin bootstrap, roles, AJAX handlers, audit calculator, data provider, PDF code, and tests;
4. run the existing standalone tests with an available PHP runtime;
5. record exact commands and results; and
6. verify the six decisions in Section 4 are approved.

Known baseline on PHP 8.3 at handoff:

- `tests/test-course-lifespans.php`: all 11 checks passed;
- `tests/test-audit-pdf-jobs.php`: all 14 checks passed.

The equivalent PowerShell commands are:

```powershell
& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' 'tests\test-course-lifespans.php'
& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' 'tests\test-audit-pdf-jobs.php'
```

Do not weaken or rewrite existing tests to accommodate the new work.

## 6. Slice 1A — pure event kernel

### 6.1 Scope

Implement only the pure deterministic classes and their tests. Create the smallest file set needed from Technical Design Section 6:

- `includes/archive/domain/class-archive-case-key.php`
- `includes/archive/domain/class-archive-cycle.php`
- `includes/archive/domain/class-archive-actor.php`
- `includes/archive/domain/class-archive-command.php`
- `includes/archive/domain/class-archive-event.php`
- `includes/archive/domain/class-archive-event-types.php`
- `includes/archive/domain/class-archive-event-catalog.php`
- `includes/archive/domain/class-archive-case.php`
- `includes/archive/domain/class-archive-reset-scope.php`
- `includes/archive/domain/class-archive-transition-exception.php`
- `includes/archive/contracts/interface-archive-clock.php`
- `includes/archive/contracts/interface-archive-id-generator.php`
- `includes/archive/infrastructure/class-archive-canonical-json.php`
- `includes/archive/infrastructure/class-archive-digester.php`
- a test-only archive bootstrap and fixtures under `tests/archive/`.

Add files only when needed by a failing test. Preserve PHP 7.4 syntax: no enums, readonly properties, constructor property promotion, union types, attributes, or PHP 8-only functions.

Keep the production plugin Composer-free. Use the existing standalone-test style for Slice 1A unless a dev-only PHPUnit/Composer toolchain is separately proposed and approved; never load a development autoloader from the plugin runtime.

### 6.2 Dependency rule

The Slice 1A subtree must not call or depend on:

- WordPress functions/constants other than a test bootstrap constant if strictly necessary;
- LearnDash, WooNinjas, Uncanny, BuddyBoss, or FluentCRM;
- `$wpdb` or any database connection;
- filesystem, HTTP, PDF, cron, queue, transient, option, or user-meta APIs;
- current dashboard/provider/calculator classes; or
- the main plugin entrypoint.

The aggregate accepts values and emits events. It performs no I/O and never claims an external action succeeded.

### 6.3 Test-first order

1. Write canonical JSON v1 golden-vector tests before the encoder.
2. Write `ghca-case-key-v1`, `ghca-idempotency-v1`, `ghca-command-v1`, `ghca-source-fingerprint-v1`, snapshot, item, and `ghca-event-hash-v1` digest-vector tests before digest implementation.
3. Write catalog schema tests for all 35 Architecture PRD events before event factories/validators.
4. Write table-driven legal/illegal transition tests for all lifecycle dimensions before aggregate handlers.
5. Write deterministic replay tests from an empty case through identical final aggregate state.
6. Write tamper tests showing any envelope, payload, metadata, predecessor, sequence, actor, or case-key change breaks verification.
7. Write early-reset tests: a request can be deferred/rejected before a snapshot exists, while `ResetAuthorized` requires an exact active finalized archive and snapshot.
8. Write correction/revocation/replacement and reset authorization/claim race tests.
9. Implement the minimum pure code needed to pass each group.
10. Produce a traceability matrix mapping every event, invariant, and relevant acceptance criterion to validator, apply handler, and test.

### 6.4 Slice 1A acceptance

Slice 1A is complete only when:

- all 35 event types have explicit payload-schema validation;
- unknown event/type/schema versions fail closed;
- canonical bytes match frozen golden vectors on PHP 7.4 and the current supported PHP runtime;
- JSON rejects invalid UTF-8, duplicate object keys, floats, invalid integer ranges, unexpected fields, excess depth/size, and non-canonical stored encodings;
- aggregate replay is deterministic and independent of wall clock/WordPress state;
- every permitted and prohibited transition in the Architecture PRD has a named test;
- multi-event decisions are emitted as one complete batch;
- no generic `StatusUpdated` event or overloaded mutable status exists;
- reset claim cannot occur without an exact authorization/snapshot/active revision;
- a static dependency scan and manual review prove the pure subtree has no WordPress/LearnDash/I/O calls;
- existing tests remain green; and
- the main plugin bootstrap and runtime behavior are unchanged.

Stop after Slice 1A and request review. Do not continue automatically to persistence.

## 7. Slice 1B — dark-mode schema and persistence

This slice requires separate approval after Slice 1A review. Build the schema/migration and persistence components, but keep them disconnected from the current activation hook and `plugins_loaded` until disposable-database testing passes.

### 7.1 Initial migration

Implement one idempotent numbered migration: `0001_create_archive_schema_v1`.

Create and postflight the 13 tables in this order:

1. streams;
2. events;
3. commands;
4. snapshots;
5. artifacts;
6. ledger items;
7. tasks;
8. case state;
9. revision state;
10. reset state;
11. reset authorizations;
12. projection heads; and
13. integrity checkpoints.

Use the exact Technical Design Section 9 manifest. Do not add foreign keys, cascades, native JSON, enums, triggers, generated columns, `CHECK`, or `SKIP LOCKED` dependencies.

`dbDelta()` is allowed only for initial creation and proven-safe additive changes. Its output is diagnostic, not proof. Verify the actual result using `information_schema` or `SHOW CREATE TABLE`, including:

- all 13 tables;
- InnoDB engine;
- WordPress table charset/collation;
- per-column ASCII/binary machine-key collations;
- exact types, lengths, nullability, and defaults;
- primary and unique constraints;
- secondary-index column order/prefixes; and
- usable required indexes.

Update `ghca_acd_archive_schema_version` only after every postflight check succeeds. A partial DDL failure must leave both feature flags off and the schema version unadvanced. Rerunning the migration must converge without destructive repair.

### 7.2 Persistence scope

After schema tests exist, implement only the dark-mode persistence components required for:

- append-only stream/event storage;
- insert-once command receipts;
- expected-version and head-digest concurrency;
- receipt-first lost-response idempotency;
- atomic multi-event batches;
- synchronous case/revision/reset projections and per-projector heads;
- durable task insertion with schema version and dedupe identity;
- lease-token fencing and compare-and-set task updates; and
- integrity/replay verification.

Do not expose a controller, worker, archive button, download, certificate capture, artifact renderer, mutation guard, correction UI, or reset gateway.

### 7.3 Database safety

Never point database tests at the existing `wp-config.php` credentials or current Laragon site database.

Until explicit deployment approval and verified backup/restore readiness, do not run against the existing site:

- `dbDelta()`;
- `CREATE`, `ALTER`, `DROP`, or `RENAME`;
- plugin deactivate/reactivate after migration wiring;
- runtime schema upgrade checks;
- WP-CLI migration commands;
- tenant/schema option writes;
- fixture seeding, backfill, replay, or repair;
- uninstall cleanup; or
- DB-backed integration tests.

Read-only `SELECT`, `SHOW`, and `information_schema` inspection is acceptable.

Use disposable databases for:

- clean install and second-run no-op;
- every upgrade path;
- custom WordPress table prefixes;
- arbitrary partial-failure recovery;
- concurrent installer attempts;
- approved MySQL and MariaDB versions;
- wrong/missing index, collation, engine, privilege, and unsupported-version failures;
- sanitized legacy-schema clones proving reviewed user meta remains untouched and no events are fabricated;
- uninstall/rollback proving archive data remains retained; and
- schema-version-not-advanced assertions for every injected failure.

Use two independent real database connections for first-stream, expected-version, idempotency, projection, and task-lease races.

## 8. Later slices — excluded from the initial delegation

Do not begin these without a new handoff/review:

- evidence read sessions and source fingerprint capture;
- certificate-byte acquisition and immutable asset manifests;
- snapshots, ledger/packet materialization, private artifact storage, and downloads;
- durable workers and operational scheduling;
- replacement of Mark Reviewed;
- mutation-unit-of-work integration into current record/audit/group paths;
- Vault UI and reporting;
- correction/revocation/replacement workflows;
- source-drift/unprotected-reset detectors; and
- reset gateway, execution claim, reconciliation, or destructive LearnDash calls.

Reset must remain disabled until a named gateway proves exact scope, stable operation identity, idempotency/recovery, pre/post fingerprints, and zero/partial/complete reconciliation.

## 9. Mandatory stop conditions

Stop and report rather than guessing when:

- any foundational Section 4 decision is unresolved;
- source documents conflict;
- a change would alter a frozen identity/event/digest contract after vectors exist;
- supported PHP 7.4 or approved database-matrix testing is unavailable;
- a required source table is non-InnoDB;
- a WordPress/LearnDash helper implicitly commits, uses another connection, or performs unbounded external work;
- an implementation requires event/snapshot/artifact update or deletion;
- existing tests fail for an unrelated reason;
- the worktree contains overlapping user changes; or
- the requested next action would touch the current site database or enable feature behavior without explicit approval.

## 10. Forbidden shortcuts

- Mutable archive lifecycle truth in status columns or user meta.
- Generic audit messages in place of specific domain events.
- Projection-only authorization of a destructive or authoritative transition.
- Rehydrating the aggregate from mutable projections in v1.
- Event/snapshot/artifact mutation or stored-event upcasting.
- Transients or browser-driven PDF jobs for official archive work.
- Live-source rereads during ledger/packet rendering.
- External I/O while holding a lifecycle/source-mutation database transaction.
- Time-, task-expiry-, or worker-death-derived lifecycle changes without an event.
- Unfenced task completion or retry under a stale lease.
- Browser cookies, disabled TLS verification, public artifact URLs, or absolute paths in archive records.
- Claiming one LearnDash hook covers third-party, CSV, custom-code, or direct-SQL resets.
- Changing tests to preserve an implementation that violates the PRD.

## 11. Required delegated-agent report

At the end of each slice, return:

1. exact files/classes/tables added or modified;
2. approved decision-record references and unresolved gates;
3. implemented events/transitions and a PRD invariant-to-test matrix;
4. migration/schema version, if applicable;
5. every command run with exact pass/fail/skip counts;
6. PHP 7.4 and current-PHP golden-vector results;
7. real-database concurrency/failure-injection/replay evidence, if applicable;
8. proof feature flags remain off and no runtime endpoint/hook behavior changed;
9. deviations from the technical design, each treated as a review blocker;
10. concise `git status` and diff summary; and
11. an explicit statement of what was not enabled or run.

Do not claim completion when a required test or environment matrix item was skipped. Do not commit, push, create a pull request, deploy, or migrate the current database unless the user separately requests and authorizes that action.

## 12. Immediate instruction to the delegated agent

Begin with repository preflight and the six-decision record. If all six decisions are already explicitly approved, implement Slice 1A only, test it, and stop for review. If they are not approved, provide the decision record with recommendations and stop before writing persisted-contract code.
