# Slice 1B Schema Checkpoint Traceability

**Status:** implementation and self-review PASS; ready for owner review. Persistence repositories, Unit of Work, projections, tasks, runtime hooks, and feature enablement have not started.

## Scope and isolation

- Migration: `0001_create_archive_schema_v1`.
- Schema: the exact ordered 13-table Technical Design Section 9 manifest.
- WordPress DDL engine: the real isolated `wp-admin/includes/upgrade.php::dbDelta()`; no local `dbDelta()` mock.
- Bootstrap excludes `wp-load.php` and `wp-config.php` and never reads current-site credentials.
- Destructive execution requires external credentials, explicit opt-in, a database name matching `ghca_acd_archive_test_*`, and one of the declared loopback Docker ports.
- Both the standard `wp_` prefix and an independent `custom_wp_` prefix are exercised.
- Production remains disconnected: the plugin entrypoint contains zero archive references.

## Exact verification command

Credentials are deliberately external and are not recorded in this artifact.

```powershell
$env:GHCA_TEST_DB_USER='<external-admin-user>'
$env:GHCA_TEST_DB_PASSWORD='<external-admin-password>'
$env:GHCA_TEST_DB_NAME='ghca_acd_archive_test_db'
$env:GHCA_TEST_DESTRUCTIVE_OPT_IN='1'
$env:GHCA_TEST_RESTRICTED_DB_USER='<external-disposable-readonly-user>'
$env:GHCA_TEST_RESTRICTED_DB_PASSWORD='<external-disposable-readonly-password>'
& tests\archive\test-all.ps1
```

Final uninterrupted run: exit code `0`; 200.6 seconds; 9/9 cells pass.

| PHP | Port | Database target | Checks | Exit |
|---|---:|---|---:|---:|
| 7.4.33 | 33061 | MySQL 8.0.46 | 55 | 0 |
| 7.4.33 | 33062 | MySQL 8.4.10 | 55 | 0 |
| 7.4.33 | 33063 | MariaDB 10.6.27 | 55 | 0 |
| 8.3.30 | 33061 | MySQL 8.0.46 | 55 | 0 |
| 8.3.30 | 33062 | MySQL 8.4.10 | 55 | 0 |
| 8.3.30 | 33063 | MariaDB 10.6.27 | 55 | 0 |
| 8.5.7 | 33061 | MySQL 8.0.46 | 55 | 0 |
| 8.5.7 | 33062 | MySQL 8.4.10 | 55 | 0 |
| 8.5.7 | 33063 | MariaDB 10.6.27 | 55 | 0 |

Total schema assertions: **495**. PHP 8.4 remains unavailable because the configured directory contains no CLI executable; no PHP 8.4 pass is claimed.

## Requirement-to-test evidence

| Requirement | Named executable evidence |
|---|---|
| Real isolated WordPress DDL and declared target | `SCHEMA-ENV-REAL-DBDELTA`, `SCHEMA-ENV-TARGET` |
| Fresh install, version advancement, exact table set | `SCHEMA-FRESH-MIGRATE`, `SCHEMA-FRESH-VERSION`, `SCHEMA-FRESH-TABLES`, `SCHEMA-FRESH-POSTFLIGHT` |
| Standard and arbitrary prefixes | `SCHEMA-PREFIX-WP`, `SCHEMA-PREFIX-CUSTOM` |
| True repeat no-op | `SCHEMA-NOOP-RESULT`, `SCHEMA-NOOP-NO-MUTATION` |
| Proven-safe additive `dbDelta()` repair | `SCHEMA-DBDELTA-INDEX-REPAIR` |
| Partial-install recovery without destructive repair | `SCHEMA-PARTIAL-STATE`, `SCHEMA-PARTIAL-CONVERGENCE`, `SCHEMA-PARTIAL-NONDESTRUCTIVE` |
| Failure leaves version unadvanced and flags off | `SCHEMA-FAIL-PARTIAL`, `SCHEMA-FAIL-VERSION`, `SCHEMA-FAIL-FLAGS`, `SCHEMA-VERSION-READ-FAIL-CLOSED` |
| Exact archive table namespace | `SCHEMA-POST-EXTRA-TABLE` |
| Engine and table/column collations | `SCHEMA-POST-ENGINE`, `SCHEMA-POST-TABLE-COLLATION`, `SCHEMA-POST-MACHINE-COLLATION`, `SCHEMA-POST-HUMAN-COLLATION` |
| Columns: set, order, type/length/signedness, nullability, defaults, auto-increment, generated state | `SCHEMA-POST-MISSING-COLUMN`, `SCHEMA-POST-EXTRA-COLUMN`, `SCHEMA-POST-COLUMN-ORDER`, `SCHEMA-POST-LENGTH`, `SCHEMA-POST-SIGNEDNESS`, `SCHEMA-POST-NULLABILITY`, `SCHEMA-POST-DEFAULT`, `SCHEMA-POST-AUTO-INCREMENT`, `SCHEMA-POST-GENERATED` plus `SCHEMA-FRESH-POSTFLIGHT` |
| Indexes: set, uniqueness, sequence, prefix, visibility/usability, type | `SCHEMA-POST-MISSING-INDEX`, `SCHEMA-POST-EXTRA-INDEX`, `SCHEMA-POST-INDEX-UNIQUE`, `SCHEMA-POST-INDEX-ORDER`, `SCHEMA-POST-INDEX-PREFIX`, `SCHEMA-POST-INDEX-USABLE` plus `SCHEMA-FRESH-POSTFLIGHT` |
| Prohibited database objects | `SCHEMA-POST-FOREIGN-KEY`, `SCHEMA-POST-CHECK`, `SCHEMA-POST-TRIGGER`, `SCHEMA-POST-GENERATED` |
| Every mutation restores the fixed manifest | `SCHEMA-POST-RESTORED`, `SCHEMA-FINAL-POSTFLIGHT` |
| Atomic cross-connection installer exclusion | `SCHEMA-CONCURRENCY-LOCK` using two independent `wpdb` connections and `GET_LOCK()`/`RELEASE_LOCK()` |
| Approved vendors/versions only | `SCHEMA-VENDOR-MYSQL`, `SCHEMA-VENDOR-MARIADB`, `SCHEMA-VENDOR-MYSQL-OLD`, `SCHEMA-VENDOR-MARIADB-OLD`, `SCHEMA-VENDOR-UNKNOWN` |
| Real insufficient-privilege behavior | `SCHEMA-PRIVILEGE-REAL`, `SCHEMA-PRIVILEGE-SAFE-STATE` using a disposable SELECT-only user |
| Retained uninstall behavior | `SCHEMA-UNINSTALL-RETAINED` proves there is no production archive-table deletion path and all 13 tables remain |
| Sanitized legacy safety and no fabricated history | `SCHEMA-LEGACY-UNCHANGED`, `SCHEMA-LEGACY-NO-FABRICATION` |

All negative postflight tests assert both rejection and the exact stable failure code. The test process converts PHP warnings/notices/deprecations into failures and exits nonzero on failed assertions or unchecked setup SQL errors.

## Regression and static verification

| Verification | Result |
|---|---|
| Slice 1A kernel, PHP 7.4.33 | 1,242 checks across 14 processes, exit 0 |
| Slice 1A kernel, PHP 8.3.30 | 1,242 checks across 14 processes, exit 0 |
| Slice 1A kernel, PHP 8.5.7 | 1,242 checks across 14 processes, exit 0 |
| Legacy plugin baseline, each runtime | 25 checks, exit 0 |
| New PHP lint on 7.4.33, 8.3.30, 8.5.7 | PASS |
| PowerShell matrix parser | PASS |
| Production debug output / transient lock / hardcoded credentials | zero hits |
| Plugin entrypoint archive references | zero hits |

## Self-review verdict

No known P1/P2 correctness, safety, compatibility, or evidence defect remains in the schema checkpoint. The schema phase is ready for owner review; this document does not authorize starting persistence automatically.
