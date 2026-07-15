# Dual-Layer Archive Implementation Decision Record

**Status:** Approved for Slice 1A pure event-kernel development  
**Date:** 2026-07-13  
**Approval evidence:** The repository owner explicitly confirmed owner approval in the delegated Codex development task on 2026-07-13. This approval applies to the six recommended foundational decisions in the development handoff.  
**Runtime effect:** None. Archive and reset behavior remain disabled and unwired.

## Approved foundational decisions

### DLA-001 — Case and cycle identity

- One future `GHCA_ACD_Archive_Cycle_Resolver` is the only archive-cycle authority.
- It consumes the configured `calendar_year` or `employee_start_date` policy. The dashboard's fixed-365 calculation is not an archive identity input.
- Boundaries use the configured WordPress IANA site timezone, are start-inclusive and end-exclusive, and are persisted in UTC with seconds precision.
- A calendar cycle begins at local January 1 00:00:00 and ends at the following local January 1 00:00:00. An anniversary cycle begins at the local anniversary boundary and ends at the following anniversary boundary. A February 29 anniversary resolves to February 28 in non-leap years.
- The frozen canonical cycle key is:

  `v1|{policy_key}|{policy_version}|{start_gmt}|{end_gmt}|{iana_timezone}|[)`

  UTC values use `YYYY-MM-DDTHH:MM:SSZ`. Policy keys and versions are immutable machine identifiers. Display labels do not participate in identity.
- The case key is tenant UUID + WordPress site ID + employee user ID + program key + canonical cycle key, encoded and digested as `ghca-case-key-v1`.

### DLA-002 — Tenant model

- One operational tenant exists per WordPress blog/site.
- Its immutable tenant identifier is 32 lowercase hexadecimal characters and will later be generated once into `ghca_acd_archive_tenant_id`.
- LearnDash groups are authorization scopes, not tenant identities.
- Multisite uses one tenant per blog and each blog's site ID. A network-global tenant remains out of scope.

### DLA-003 — Data classification

- Archive data is employment/training PII and compliance evidence only.
- PHI and PCI are out of scope. Discovery of either is a mandatory stop for a separate privacy/security design.
- Production database, private artifacts, and backups require encryption and access logging appropriate to PII before enablement.

### DLA-004 — Snapshot completeness v1

- Technical Design Section 10 is approved as snapshot v1.
- Missing evidence is explicit `null`; current time is never substituted.
- The resolved cycle and policy are fixed at request/capture.
- Every completeness-required certificate byte stream must be sealed and identified before snapshot commit.
- Ledger and packet are bound to the same snapshot digest.
- Slice 1A freezes conservative canonical-document safety ceilings: 1 MiB encoded bytes, depth 32, 10,000 total values, and 262,144 UTF-8 bytes per string. Phase 2 must measure representative evidence and approve equal or lower snapshot/item-specific limits before persistence or capture is enabled.

### DLA-005 — Private artifact storage

- Production requires `GHCA_ACD_ARCHIVE_PRIVATE_DIR` outside the public document root, or a separately approved private object-store adapter.
- There is no production uploads-directory fallback.
- Database records contain only an adapter key and relative immutable storage key; public URLs and absolute paths are prohibited.

### DLA-006 — Database support floor

- The feature floor is MySQL 8.0+ or MariaDB 10.6+, with InnoDB and the exact 13-table manifest.
- The schema remains portable and avoids MySQL-8-only features.
- No migration may be wired to runtime until disposable-database tests pass on both approved families.

## Approved operating baseline

- Approximately 100 projection/history reads per lifecycle write.
- Validation envelope: 100 reads/second and 5 lifecycle writes/second per site.
- Up to 25,000 employees, 250,000 cases, 5 million events, and five artifact workers per site.
- Lifecycle API availability target: 99.9% monthly.
- Lifecycle command p99: at most 1,000 ms, excluding asynchronous artifact work.
- Primary RPO: zero for acknowledged event commits.
- Disaster-recovery RPO: at most 15 minutes; RTO: at most 4 hours.
- A named Product Owner and Site Operations Owner remain mandatory before production enablement.

## Scope authorized by this record

Only Slice 1A pure deterministic classes, standalone tests, fixtures, and traceability documentation are authorized. The main plugin bootstrap, WordPress hooks, current Mark Reviewed behavior, LearnDash mutations, PDF paths, workers, database/schema code, feature flags, and reset execution remain unchanged and disabled.

