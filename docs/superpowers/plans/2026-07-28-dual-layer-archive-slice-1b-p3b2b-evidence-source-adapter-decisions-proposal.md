# Slice 1B-P3B2b Evidence Source Adapter Decisions Proposal

Date: 2026-07-28
Status: owner-approved; P3B2b implementation authorized within B17
Scope: proposal-only checkpoint for a production, read-only WordPress/LearnDash evidence-source adapter

## 1. Decision checkpoint

P3B2a deliberately stopped at a constructor-injected `GHCA_ACD_Archive_Evidence_Source` contract backed by test fakes. It proved the dark worker flow, validation, preparation, fingerprint comparison, and fenced outcome behavior; it did not prove any production source query.

P3B2b may be implemented only after the owner approves the exact physical mappings and operational contracts in this record. Until then:

- no production source adapter is authorized;
- no current-site database may be accessed;
- no runtime connection or secret source is authorized;
- no WordPress or LearnDash helper may be called merely because it currently returns a useful value;
- no inferred physical mapping may be substituted for an unresolved decision below.

This proposal adds no schema, runtime wiring, hook, scheduler, controller, activation path, network access, certificate acquisition, packet work, lifecycle retry policy, or P3B3 configuration.

### Formal-review remediation

The first review did not approve B01–B18. This revision:

- preserves the accepted E07 shape by using `time_spent_seconds = "0"` and the exact two-key certificate reference;
- removes the circular `ArchiveRequested.occurred_at_gmt` proposal and defers production calculation-time authority to P3B3;
- adds a complete E07 construction matrix and independent literal-vector requirements;
- restores the accepted E14 lifecycle routing and exact integrity contexts;
- freezes descriptor grammar, site/blog equality, required indexes, cancellation checkpoints, and disposable-source test setup;
- distinguishes the distributed PHP 8.3+ floor from the two required verification binaries.
- freezes the exact injected three-field source-version descriptor while leaving non-spoofable production code-version attestation activation-blocked for P3B3.

### Owner approval record

The owner formally approved this proposal as written:

> **Approve P3B2b Decisions B01–B18 as written, including the PHP 8.3+ distribution floor, exact source-version tuple and immutable three-field descriptor, P3B3 non-configurable code-version-attestation gate, complete E07/policy-digest mapping, descriptor and index manifests, record-version domains, time-independent calculation v1 with its P3B3 calculation-time gate, E06 unconditional-rollback amendment, fenced checkpoint, elapsed-only timeout contract, disposable restricted-source test setup, implementation allowlist, and all P3B3/runtime/certificate deferrals.**

This approval authorizes implementation only within B17. All B18 deferrals and dark-mode boundaries remain binding.

## 2. Mandatory preflight evidence

Read-only preflight was performed before this file was created:

| Check | Result |
|---|---|
| Repository | `C:\laragon\www\Gridhouse-Healthcare-Academy\wp-content\plugins\gridhouse-admin-compliance-dashboard` |
| Branch | `feature/dual-layer-archive-slice-1b-p3b2b-evidence-source-adapter` |
| HEAD | `54ff365e95cacd380161f86ae63e1a5399c2ef7e` |
| Staged files | zero |
| Tracked modifications | zero |
| Untracked files | only the pre-existing `.claude/` |

`.claude/` was neither opened nor modified. No database, `wp-config.php`, `wp-load.php`, global `$wpdb`, current-site credential, WP-CLI command, REST request, network request, or Docker mutation was used.

## 3. Authoritative contracts reviewed

The following were read as binding inputs:

1. `docs/superpowers/plans/2026-07-26-dual-layer-archive-slice-1b-p3b2a-evidence-capture-decisions-proposal.md`
2. `docs/superpowers/plans/2026-07-27-dual-layer-archive-slice-1b-p3b2a-traceability.md`
3. `docs/superpowers/plans/2026-07-13-dual-layer-archive-event-sourcing-technical-design.md`
4. `docs/superpowers/plans/2026-07-13-dual-layer-archive-development-handoff.md`
5. `includes/archive/contracts/class-archive-evidence-source.php`
6. The P3B2a evidence validator, snapshot preparer, task handler, worker coordinator, and their tests.

P3B2b inherits without amendment:

- the exact E07 evidence-document shape and field allowlists;
- the E08 fingerprint domain `ghca-source-fingerprint-v1\n`;
- canonical JSON and normalization rules;
- independent 10,000-value ceilings for the E07 and snapshot documents;
- the P3B2a closed failure taxonomy unless this proposal explicitly reuses a listed tuple;
- the requirement that review and capture use identical physical mapping, normalization, adapter key/version, E07 bytes, and E08 domain;
- the prohibition on lifecycle facts for operational source failures;
- constructor injection and dark-mode execution only.

## 4. Local discovery evidence

Installed source was examined only as read-only discovery evidence. It does not authorize runtime loading.

### 4.1 Installed versions

| Product | Locally proven version | Evidence |
|---|---:|---|
| WordPress | `7.0.2` | `wp-includes/version.php`, `$wp_version` |
| LearnDash LMS | `5.1.6.1` | `sfwd-lms/sfwd_lms.php`, plugin header |
| Gridhouse Admin Compliance Dashboard | `1.2.0` | plugin entrypoint header and version constant |

### 4.2 WordPress storage evidence

- Core columns for options, posts, postmeta, users, and usermeta are defined in `wp-admin/includes/schema.php`.
- Users and usermeta are network-global multisite tables; options, posts, and postmeta use the resolved blog prefix.
- `wpdb::get_blog_prefix()` uses `base_prefix` for blog 1 and `base_prefix . blog_id . '_'` for later blogs, subject to installed configuration: `wp-includes/class-wpdb.php`.
- A site's capability row is `${blog_prefix}capabilities`; role names are derived from capability-map keys: `wp-includes/class-wp-user.php`.
- The site's registered role definitions are stored in `${blog_prefix}user_roles`.

These facts prove the default physical model, but they do not authorize deriving a table name from an unchecked blog ID at runtime.

### 4.3 LearnDash storage evidence

- `learndash_user_activity` contains `activity_id`, `user_id`, `post_id`, `course_id`, `activity_type`, `activity_status`, `activity_started`, `activity_completed`, and `activity_updated`: `sfwd-lms/includes/admin/classes-data-upgrades-actions/class-learndash-admin-data-upgrades-user-activity-db-table.php`.
- `learndash_user_activity_meta` contains `activity_meta_id`, `activity_id`, `activity_meta_key`, and `activity_meta_value` in the same schema definition.
- LearnDash builds activity and ProQuiz table names from the active blog prefix and permits installed constants/filters to change subprefixes: `sfwd-lms/includes/class-ldlms-db.php`. Consequently, P3B2b must receive exact validated table names; it must not reconstruct them from defaults.
- Group membership is stored in usermeta keys beginning `learndash_group_users_`; hierarchical group expansion follows group-post descendants when the LearnDash hierarchy option is enabled: `sfwd-lms/includes/ld-groups.php`.
- Group-to-course access is represented by course postmeta keys `learndash_group_enrolled_{group_id}`: `sfwd-lms/includes/ld-groups.php`.
- Legacy direct course access and access timestamps are stored in usermeta keys such as `course_{course_id}_access_from`: `sfwd-lms/includes/ld-users.php`.
- Open enrollment uses course postmeta `_ld_price_type = open`: `sfwd-lms/includes/course/ld-course-functions.php`.
- Course certificate assignment is normalized as `_ld_certificate`; the referenced post must be the LearnDash certificate post type: `sfwd-lms/includes/admin/classes-posts-edits/class-learndash-admin-course-edit.php`, `sfwd-lms/includes/ld-certificates.php`.
- Course and quiz activity-meta values are written through LearnDash serialization helpers: `sfwd-lms/includes/course/ld-activity-functions.php`.
- LearnDash course-status helpers may mark data complete on some paths: `sfwd-lms/includes/course/ld-course-progress.php`. They are therefore forbidden inside this read-only adapter.

No ProQuiz question, answer, statistic, essay, free-text, or response table is required by the proposed mapping.

### 4.4 Dashboard storage evidence

- Audit mapping option `ghca_acd_audit_mapping` contains `odp_category`, `oltl_category`, `credit_hours`, `sort_order`, and `is_orientation`: `includes/class-audit-mapping.php`.
- Course lifespan and warning options are `ghca_acd_course_lifespans` and `ghca_acd_warning_days`: `includes/class-course-lifespans.php`.
- Annual-cycle, new-hire-group, and new-hire-deadline settings are registered by `includes/class-settings.php`.
- Existing dashboard reads identify group grants, course status/progress, `course_completed_{course_id}`, `course_{course_id}_access_from`, and certificate assignment: `includes/class-compliance-program.php`.
- Branding option `ghca_dashboard_brand` uses `org_name` and otherwise falls back to the site name: `includes/class-branding.php`.
- Existing display code falls back from first/last name to display name and then user login. P3B2b does not approve the user-login fallback because it is unnecessary retained identity data: `includes/class-data-provider.php`.

## 5. Proposed owner decisions

Every numbered decision is implementation-blocking until approved.

### B01 — Supported source versions

**Recommendation**

The distributed plugin compatibility floor remains:

```text
PHP >= 8.3
```

PHP 8.3.30 and PHP 8.5.7 are mandatory verification binaries. They are not the only supported patch/minor runtimes within the PHP 8.3+ distribution declaration. PHP 8.4 remains a supported PHP 8.3+ family member but is reported unverified while no actual PHP 8.4 CLI exists.

The first source-layout adapter supports exactly:

- WordPress `7.0.2`;
- LearnDash LMS `5.1.6.1`;
- Gridhouse Admin Compliance Dashboard `1.2.0`;
- MySQL `8.0.x`, MySQL `8.4.x`, and MariaDB `10.6.x`.

The three application versions form a closed compatibility tuple. A different major, minor, patch, development, or malformed version is rejected before any evidence query with:

```text
category: operational_blocked
reason: archive_source_schema_unsupported
context: source_preflight
```

Adding a source-version tuple later requires:

1. local source review;
2. updated physical-schema fixtures;
3. all P3B2b mapping, consistency, and parity regressions on that tuple;
4. an adapter-version decision under B02.

**Why**

The plugin header and README declare PHP 8.3+, while the release matrix deliberately tests its oldest required binary and the current PHP 8.5 binary. Only the listed application versions were locally available for physical mapping discovery; declaring a WordPress/LearnDash/dashboard range without source fixtures would infer source-layout compatibility.

**Retained-data effect**

Operational policy only while the mapping and normalized output remain byte-identical. Any mapping change invokes B02.

### B02 — Adapter identity and compatibility changes

**Recommendation**

Freeze:

```text
adapter_key: learndash-local
adapter_version: 1.0.0
```

`adapter_key` identifies this physical WordPress/LearnDash/dashboard mapping. `adapter_version` is part of E07 and therefore the E08 fingerprint.

Keep `1.0.0` only for changes proven to preserve, for every accepted source fixture:

- selected rows;
- retained field meanings;
- ordering;
- record IDs;
- record-version bytes;
- canonical E07 bytes;
- E08 fingerprints.

Any compatibility or implementation change capable of changing one of those values requires a new semantic adapter version and new independent golden vectors. A bug fix that changes previously retained evidence also requires a new adapter version; it must not reinterpret an existing `1.0.0` fingerprint.

**Retained-data effect**

Changing the adapter version changes E07 and E08 retained bytes and is a retained-data contract change.

### B03 — Dedicated read-only principal and isolated connection

**Recommendation**

P3B2b accepts only a constructor-injected source connection, immutable expected-connection/table descriptor, and immutable source-version descriptor. It performs no runtime credential, version, or connection loading.

The source-version descriptor is a separate exact three-key object containing only:

```text
wordpress_version = "7.0.2"
learndash_version = "5.1.6.1"
plugin_version = "1.2.0"
```

Constructor validation occurs before the source connection is opened and before any evidence/preflight query. The exact key set and exact string values are mandatory:

- a missing or extra key is rejected;
- a non-string, empty, trimmed-different, differently cased, prefixed/suffixed, or otherwise malformed value is rejected;
- any value other than the exact B01 value is rejected, including a syntactically valid spoofed version;
- the adapter detaches its own immutable copy, and caller mutation after construction cannot change retained source versions;
- `source.wordpress_version`, `source.learndash_version`, and `source.plugin_version` come only from that validated detached descriptor.

Every rejection is `archive_source_schema_unsupported/source_preflight`, occurs with zero evidence queries, and produces no partial E07 document.

The connection must:

- be a distinct connection object and server session from archive persistence;
- use a dedicated database principal whose only source grants are `SELECT`;
- have no `INSERT`, `UPDATE`, `DELETE`, `CREATE`, `ALTER`, `DROP`, `TRIGGER`, `EVENT`, `EXECUTE`, `FILE`, `PROCESS`, `SUPER`, replication, or grant privileges;
- have no access to archive persistence tables;
- select the exact injected source database;
- use UTF-8 MB4 and UTC session time;
- use native prepared statements or the existing database driver's equivalent parameter binding;
- be closed after every read attempt.

The adapter compares `CURRENT_USER()`, `DATABASE()`, and `CONNECTION_ID()` with the injected expected descriptor and the separately injected archive-connection identity. Passwords, grants, and connection strings are never placed in evidence, errors, or logs.

P3B2b tests inject the exact immutable descriptor directly.

Production activation remains blocked until P3B3 defines and tests an authoritative code-version attestation source. That source must derive versions from installed code/deployment provenance and must not be replaceable by an ordinary site option, environment/configuration claim, request/task value, source-database row, global, filter, or caller-supplied descriptor. P3B3 must prove that attested code versions equal the closed descriptor before constructing the adapter; a missing, unreadable, ambiguous, or mismatched attestation fails closed before evidence queries.

Production principal creation, credential storage, multisite connection factories, code-version attestation, and secret rotation remain P3B3 work.

**Failure**

Any mismatch is `archive_source_schema_unsupported/source_preflight`. A connection or statement failure uses the accepted source-read tuples in B13.

**Retained-data effect**

Connection policy is operational. The validated version values are retained in E07 and therefore affect E08; changing them follows B02.

### B04 — Multisite tenant, site, and table resolution

**Recommendation**

One WordPress site/blog is one operational archive tenant. LearnDash groups are authorization/compliance scopes, not tenants.

P3B2b receives one constructor-injected, closed table descriptor:

```text
tenant_id
site_id
blog_id
source_database
base_prefix
blog_prefix
users_table
usermeta_table
options_table
posts_table
postmeta_table
learndash_user_activity_table
learndash_user_activity_meta_table
capabilities_meta_key
user_roles_option_name
```

Rules:

- archive `site_id` is the WordPress blog ID, not the multisite network ID; its canonical unsigned-decimal value must equal the injected `blog_id` byte-for-byte after canonical decimal normalization;
- `tenant_id` and `site_id` must equal their authoritative capture bindings;
- `blog_id` must be a positive integer representable as the same canonical decimal `site_id`;
- `users_table` and `usermeta_table` must use the injected network base mapping.
- site-scoped tables and role/capability keys must use the same injected `blog_prefix`.
- every table identifier must exactly equal the descriptor value validated at construction; capture/task/source values may never become identifiers.
- all tables must resolve to the one injected `source_database`.
- default WordPress/LearnDash prefix formulas are evidence used to validate a descriptor, not a runtime discovery mechanism.
- no switch-to-blog call, global `$wpdb`, WordPress bootstrap, filter, constant lookup, or current-site option may resolve the descriptor.

Identifier grammar is closed and ASCII-only:

| Value | Exact grammar | Maximum bytes |
|---|---|---:|
| `source_database` and every table name | `^[A-Za-z_][A-Za-z0-9_]{0,63}$` | 64 |
| `base_prefix` and `blog_prefix` | `^[A-Za-z_][A-Za-z0-9_]{0,31}$` | 32 |
| capability meta key and role option name | `^[A-Za-z_][A-Za-z0-9_]{0,63}$` | 64 |

Validation occurs before any identifier influences SQL. Dots, backticks, quotes, NUL/control bytes, whitespace, wildcards, slashes, backslashes, colons, semicolons, comments, Unicode, qualification, and empty identifiers are rejected. The adapter quotes a validated identifier with one pair of backticks and never accepts a caller-supplied quoted form.

Descriptor consistency is mechanical:

- every site-scoped table begins with the exact `blog_prefix`;
- users/usermeta begin with the exact `base_prefix`;
- activity tables begin with the exact `blog_prefix`;
- `capabilities_meta_key` is exactly `blog_prefix + "capabilities"`;
- `user_roles_option_name` is exactly `blog_prefix + "user_roles"`;
- `blog_prefix` equals `base_prefix` when `blog_id = 1`, otherwise it equals `base_prefix + canonical_blog_id + "_"`;
- no table may match an archive table from the separately injected archive-table manifest;
- duplicate physical table names, mixed prefixes, a site/blog mismatch, or a qualified identifier reject the complete descriptor.

P3B3 must supply the descriptor from an approved runtime source. P3B2b tests inject it directly.

**Failure**

Contradictory bindings or mixed prefixes use `archive_build_binding_invalid/authoritative_load`. Invalid, missing, cross-schema, or unsupported physical tables use `archive_source_schema_unsupported/source_preflight`.

**Retained-data effect**

Tenant/site bindings are retained. Prefix/table mechanics are operational unless they change selected source rows.

### B05 — Exact physical tables, columns, keys, joins, filters, and order

**Recommendation**

The adapter may read only these tables and columns:

| Table role | Permitted columns |
|---|---|
| WordPress users | `ID`, `user_email`, `user_registered`, `display_name` |
| WordPress usermeta | `umeta_id`, `user_id`, `meta_key`, `meta_value` |
| WordPress options | `option_id`, `option_name`, `option_value` |
| WordPress posts | `ID`, `post_title`, `post_status`, `post_type`, `post_modified_gmt`, `post_parent` |
| WordPress postmeta | `meta_id`, `post_id`, `meta_key`, `meta_value` |
| LearnDash user activity | `activity_id`, `user_id`, `post_id`, `course_id`, `activity_type`, `activity_status`, `activity_started`, `activity_completed`, `activity_updated` |
| LearnDash activity meta | `activity_meta_id`, `activity_id`, `activity_meta_key`, `activity_meta_value` |

No other table or column is allowed. In particular, ProQuiz, comments, commentmeta, options not listed below, arbitrary meta, and LearnDash statistic/answer tables are excluded.

The `information_schema.statistics` preflight requires this exact index manifest. Column order is significant; additional indexes are allowed.

| Table role | Required index name | Required indexed columns |
|---|---|---|
| users | `PRIMARY` | `ID` |
| usermeta | `PRIMARY` | `umeta_id` |
| usermeta | `user_id` | `user_id` |
| usermeta | `meta_key` | `meta_key` |
| options | `PRIMARY` | `option_id` |
| options | `option_name` unique | `option_name` |
| posts | `PRIMARY` | `ID` |
| posts | `type_status_date` | `post_type`, `post_status`, `post_date`, `ID` |
| postmeta | `PRIMARY` | `meta_id` |
| postmeta | `post_id` | `post_id` |
| postmeta | `meta_key` | `meta_key` |
| LearnDash user activity | `PRIMARY` | `activity_id` |
| LearnDash user activity | `user_id` | `user_id` |
| LearnDash user activity | `post_id` | `post_id` |
| LearnDash user activity | `course_id` | `course_id` |
| LearnDash user activity | `activity_status` | `activity_status` |
| LearnDash user activity | `activity_type` | `activity_type` |
| LearnDash user activity | `activity_started` | `activity_started` |
| LearnDash user activity | `activity_completed` | `activity_completed` |
| LearnDash user activity | `activity_updated` | `activity_updated` |
| LearnDash activity meta | `PRIMARY` | `activity_meta_id` |
| LearnDash activity meta | `activity_id` | `activity_id` |
| LearnDash activity meta | `activity_meta_key` | `activity_meta_key` |

`PRIMARY` and `option_name` must be unique. A source prefix index on `meta_key` or `activity_meta_key` is accepted only when its first indexed column is the named key and its indexed prefix is at least 191 characters. No proposed query may depend on an unlisted index. A missing, reordered, non-unique-when-required, or too-short required index is `archive_source_schema_unsupported/source_preflight` before the evidence transaction.

Permitted option names:

```text
blogname
ghca_dashboard_brand
ghca_acd_audit_mapping
ghca_acd_course_lifespans
ghca_acd_warning_days
ghca_acd_annual_cycle
ghca_new_hire_group_ids
ghca_new_hire_deadline_days
learndash_settings_groups_management_display
{injected user_roles_option_name}
```

Permitted exact or bounded meta-key families:

```text
first_name
last_name
{injected capabilities_meta_key}
learndash_group_users_{unsigned_group_id}
course_{unsigned_course_id}_access_from
course_completed_{unsigned_course_id}
_ld_price_type
_ld_certificate
learndash_group_enrolled_{unsigned_group_id}
```

Permitted activity types are exactly `course` and `quiz`. For quiz activity, permitted activity-meta keys are exactly:

```text
pass
percentage
```

The implementation uses a fixed statement plan:

1. validate connection identity and source database;
2. preflight required base tables, engines, columns, and required indexes through `information_schema`;
3. set `REPEATABLE READ`;
4. start the consistent, read-only snapshot;
5. fetch the closed option set;
6. fetch the one subject user;
7. count then fetch permitted subject/group/direct/completion usermeta;
8. count then fetch required group/course/certificate posts;
9. count then fetch permitted course/group/certificate postmeta;
10. count then fetch course and quiz activity;
11. count then fetch the two permitted quiz-meta keys;
12. perform one combined post-read count check inside the same snapshot;
13. roll back;
14. close the source connection.

All multi-row reads use a count first and then `LIMIT approved_ceiling + 1`. No result is silently truncated. The complete plan, including session and transaction statements, must remain at or below 32 SQL statements.

Deterministic data-query order is:

| Rows | Required order |
|---|---|
| options | `option_name ASC, option_id ASC` |
| usermeta | `meta_key ASC, umeta_id ASC` |
| posts | `post_type ASC, ID ASC` |
| postmeta | `post_id ASC, meta_key ASC, meta_id ASC` |
| activity | `activity_type ASC, course_id ASC, activity_completed ASC, post_id ASC, activity_id ASC` |
| activity meta | `activity_id ASC, activity_meta_key ASC, activity_meta_id ASC` |

The only joins are equality joins against already validated unsigned identifiers:

- usermeta `user_id = users.ID`;
- postmeta `post_id = posts.ID`;
- activity `user_id = users.ID` and `course_id = tracked course ID`;
- activity meta `activity_id = activity.activity_id`.

The implementation may perform these as separate bounded queries rather than one large join. That is preferred because it preserves row identities and gives each cardinality an independent bound.

Duplicate singleton options or singleton meta keys, contradictory activity rows, missing required rows, invalid serialization, or rows outside the closed filters fail closed as `archive_evidence_incomplete/normalize_limit` or `archive_snapshot_invalid/source_validate`, as specified in B13. They are never resolved using an unordered “first” row.

**Retained-data effect**

The table/column/key selection, filters, and order determine retained evidence. Changing them requires the B02 compatibility rule.

### B06 — User identity, roles, groups, and hierarchy

**Recommendation**

Subject mapping:

| E07 field | Physical mapping |
|---|---|
| `employee_user_id` | `users.ID`, exact task/case binding |
| `email` | `users.user_email`, trimmed and lowercase under E07 rules |
| `registered_at_gmt` | `users.user_registered`, strict UTC normalization |
| `display_name` | non-empty `trim(first_name + " " + last_name)`; otherwise `users.display_name`; no `user_login` fallback |
| `external_employee_key` | `null`; no approved physical source exists |

Role mapping:

1. decode only the exact `${blog_prefix}capabilities` usermeta row;
2. decode only the exact `${blog_prefix}user_roles` option;
3. include a role key only when the capability map has that key with strict truth and the same key exists in the registered-role map;
4. sort role keys by binary string order;
5. individual capabilities are not roles and are discarded.

Group mapping:

1. read only `learndash_group_users_{id}` usermeta keys for the bound user;
2. require the value to equal the unsigned group ID encoded in the key;
3. retain direct group IDs once, sorted unsigned ascending;
4. if `learndash_settings_groups_management_display.group_hierarchical_enabled` is exactly `yes`, expand descendants through `posts.post_parent`;
5. reject cycles, a missing parent, cross-site group posts, non-published groups, depth above 64, or more than 10,000 visited groups;
6. `source.group_ids` and `subject.group_ids` contain the effective direct-plus-descendant set in unsigned order.

Group posts must have the exact LearnDash group post type and `post_status = publish`.

**Owner-gated physical facts**

- Approve the LearnDash usermeta key as the sole membership authority for adapter v1.
- Approve descendant expansion, not ancestor expansion, because that is the direction used by the installed LearnDash access helper.
- Approve `external_employee_key = null`; no plugin-local column or meta key was found.

**Retained-data effect**

Identity, roles, and group IDs are retained and fingerprinted. Any mapping change invokes B02.

### B07 — Program course set and enrollment precedence

**Recommendation**

Only program keys already accepted by the archive case contract may reach this adapter. For adapter v1:

- the annual tracked-course set is the unsigned course-ID key set of `ghca_acd_audit_mapping`;
- the new-hire tracked-course set is the intersection of:
  - courses granted to the configured `ghca_new_hire_group_ids`, including approved descendant expansion; and
  - courses present in `ghca_acd_audit_mapping`.

A group-granted course missing from audit mapping is incomplete policy, not an implicitly synthesized course.

Enrollment is the union of:

1. **direct** — a valid `course_{course_id}_access_from` usermeta row exists;
2. **group** — a validated effective group has course postmeta `learndash_group_enrolled_{group_id}`;
3. **open** — course postmeta `_ld_price_type` is exactly `open`.

When more than one applies, the retained provenance precedence is:

```text
direct > group > open
```

The chosen source affects the course record version. All applicable source row IDs are still included in the closed record-version projection so a provenance change cannot be hidden.

`enrollment_status` is:

- `enrolled` when at least one source applies;
- `not_enrolled` otherwise.

No LearnDash enrollment helper is called. No inferred enrollment is created from course progress, completion, role, or a registration-date fallback.

**Owner-gated physical facts**

- Approve audit mapping as the annual tracked-course authority.
- Approve the new-hire intersection above. The installed code can also obtain group courses dynamically, but it does not freeze which source is authoritative for archive evidence.
- Approve the direct/group/open precedence. LearnDash computes a union but does not provide a retained provenance priority.

**Retained-data effect**

The course set, enrollment value, and provenance are retained. Any change invokes B02.

### B08 — Course status, timestamps, quiz, score, time, and calculations

**Recommendation**

#### B08.1 Course activity

- Course rows are published `sfwd-courses` posts in the approved tracked set.
- There may be at most one `activity_type = course` row per bound user/course in adapter v1. More than one is contradictory source state and fails closed.
- `activity_started`, `activity_completed`, and `activity_updated` are non-negative Unix seconds.
- `course_completed_{course_id}` is an optional cross-check only.
- A LearnDash activity completion and a non-zero completion-meta timestamp must agree exactly. Disagreement fails closed.
- `started_at_gmt` comes from `activity_started`; if absent, it may come from the one direct-access timestamp only. It is never inferred from registration or current time.
- `completed_at_gmt` comes from a completed course activity and its agreeing completion meta.
- `completion_status` is `completed` only when the activity status and completion timestamp agree; it is `in_progress` when a valid start exists without completion; otherwise it is `not_started`.

All retained course/quiz timestamps must fall within the resolved cycle half-open interval `[cycle.start_at_gmt, cycle.end_at_gmt)`. A completion inside the interval with only a pre-cycle start is not clamped or invented; it fails `archive_evidence_incomplete/source_validate`. Pre-cycle completion does not become a completion in the new cycle.

This cycle rule is an implementation-blocking owner decision because the installed source exposes current LearnDash progress, not an archive-specific per-cycle history.

#### B08.2 Quiz mapping

- Only `activity_type = quiz` rows for the bound user and tracked course are eligible.
- An attempt is retained only when `activity_completed` is within the cycle.
- Order is `(activity_completed ASC, activity_id ASC)`.
- `attempt_ordinal` is the zero-based list index in that order, matching the accepted E07 validator.
- `attempted_at_gmt` is the normalized activity completion.
- `passed` is the strict boolean decoded from activity-meta `pass` and must agree with `activity_status`.
- `score_basis_points` is derived only from activity-meta `percentage`.
- Accepted percentage grammar is unsigned decimal `0` through `100` with at most six fractional digits.
- Conversion to basis points uses decimal-string arithmetic and round-half-up to the nearest basis point; PHP floating-point arithmetic is forbidden.
- Duplicate `pass` or `percentage` rows, missing required values, disagreement, or an out-of-range value fails closed.

The installed dashboard has no proven archive policy field defining whether quiz passage is globally required. Adapter v1 therefore recommends:

```text
policy.quiz_policy.required = false
```

Quiz attempts and scores remain retained evidence, but `pass_state` is `not_applicable` and quiz score does not decide compliance while the policy is false. If the owner wants quiz passage to affect compliance, an exact physical policy source and per-course applicability mapping must be approved before implementation.

#### B08.3 Time spent

No locally proven source provides bounded, deterministic, course-level elapsed time without relying on additional plugins or heuristic aggregation. Adapter v1 therefore recommends:

```text
time_spent_seconds = "0"
```

The value is the canonical unsigned-decimal string required by E07. Uncanny activity-time data, HTTP/API helpers, and LearnDash ProQuiz timing are not approved sources. Consequently `calculated.total_training_seconds` is also `"0"`.

#### B08.4 Ordering and policy values

- `course_order` is the validated integer `sort_order` from audit mapping.
- `category_order` is `0` for adapter v1 because the local mapping stores category keys but no independent category-order contract.
- `course_stable_key` is `null`; no approved immutable non-ID source exists.
- course title is `posts.post_title`, decoded/normalized under E07; HTML, URLs, paths, and control characters remain prohibited.
- `certificate_required` and reference follow B10.
- `credit_hours` is accepted only when the decoded source value is finite, non-negative, and an exact quarter hour. It is immediately converted to integer credit minutes; no float enters E07.
- lifespan and warning values are retained as policy evidence but do not drive P3B2b calculation v1 without the deferred calculation-time authority below.

#### B08.5 Calculation-time authority is deferred to P3B3

The earlier proposal to use `ArchiveRequested.occurred_at_gmt` was circular: review must compute `reviewed_source_fingerprint` before the Unit of Work creates that event timestamp. It is withdrawn.

P3B2b makes E07 calculation v1 time-independent:

- course validity requires approved enrollment, in-cycle completion, and the approved quiz rule;
- lifespan expiry and warning windows are not evaluated;
- no wall clock, request-event timestamp, snapshot-capture timestamp, database current-time function, or current-site time is read;
- review and capture therefore use the same pure calculation for identical physical source rows.

P3B3 is activation-blocked until the owner either:

1. approves this time-independent calculation as the production rule; or
2. approves an authoritative calculation timestamp that exists before review, is retained byte-identically through the request command/event, and is supplied unchanged to capture.

Option 2 requires a separate command/event/UoW/handler contract amendment and new golden vectors; it is not authorized by P3B2b. P3B2b does not change `GHCA_ACD_Archive_Evidence_Source` for calculation time.

A course is calculation-valid under the proposed time-independent rule when it is enrolled, completed in-cycle, and satisfies the approved quiz policy. Category booleans are true only when every tracked course assigned to that category is valid. Overall `compliant` is true only when every tracked course is valid. Completed course IDs and credit minutes count only valid courses. Empty tracked sets or categories are incomplete configuration, not compliant.

#### B08.6 Complete E07 construction matrix

The adapter returns exactly the accepted E07 document. No key may be added or omitted.

| E07 path | Exact source/construction |
|---|---|
| `schema_version` | literal integer `1` |
| `canonical_format` | literal `ghca-cjson-1` |
| `case.tenant_id` | authoritative `capture_identity.case_key.tenant_id` |
| `case.site_id` | authoritative `capture_identity.case_key.site_id_decimal`, which must equal B04 `blog_id` |
| `case.employee_user_id` | authoritative `capture_identity.case_key.employee_user_id_decimal` and `users.ID` |
| `case.program_key` | authoritative `capture_identity.case_key.program_key` |
| `case.cycle_key` | authoritative `capture_identity.case_key.cycle_key` |
| `cycle` | byte-for-byte detached copy of `capture_identity.resolved_cycle`; no source reconstruction |
| `organization.site_name` | normalized non-empty `blogname` option |
| `organization.agency_name` | normalized non-empty `ghca_dashboard_brand.org_name`; otherwise the same normalized `blogname` |
| `organization.tenant_id` | exact `case.tenant_id` |
| `subject` | B06 exact identity/role/group mapping |
| `policy.tracked_course_ids` | B07 selected course IDs, canonical unsigned ascending |
| `policy.audit_mapping` | for every tracked ID, exact normalized `category_order`, `course_order`, integer-minutes decimal string, strict `is_orientation`, nullable normalized ODP key, and nullable normalized OLTL key |
| `policy.course_lifespan_rules` | for every tracked ID, canonical decimal `lifespan_days` from `ghca_acd_course_lifespans` and canonical decimal `warning_days` from `ghca_acd_warning_days` |
| `policy.completeness_policy` | literal `snapshot_v1_complete` |
| `policy.quiz_policy` | exact object: `attempt_selection = latest_completed`, `required = false`, `score_scale = basis_points` |
| `policy.relevant_settings.annual_cycle` | exact validated `ghca_acd_annual_cycle` value |
| `policy.relevant_settings.new_hire_deadline_days` | canonical decimal from `ghca_new_hire_deadline_days` |
| `policy.relevant_settings.warning_days` | canonical decimal from `ghca_acd_warning_days` |
| `policy.policy_digest` | exact policy digest defined below and equal to `capture_identity.policy_digest` |
| `courses` | one B07 tracked course each, ordered `(category_order, course_order, unsigned course_id)` and constructed by B08.1–B08.4, B09, and B10 |
| `completeness.missing_fields` | empty list; any missing field rejects instead of emitting E07 |
| `completeness.observed_count` | integer count of emitted courses |
| `completeness.policy_code` | literal `snapshot_v1_complete` |
| `completeness.policy_version` | literal integer `1` |
| `completeness.required_count` | integer count of `policy.tracked_course_ids` |
| `completeness.result` | literal `complete` |
| `completeness.warnings` | empty list; warnings do not weaken completeness |
| `calculated.calculation_version` | literal integer `1` |
| `calculated.categories` | one key for each non-null ODP category in audit mapping; each value contains canonical unsigned-ascending valid `completed_course_ids` and the sum of their `credit_minutes` |
| `calculated.matrix.odp` | every non-null ODP key mapped to true only when every tracked course assigned to it is calculation-valid |
| `calculated.matrix.oltl` | every non-null OLTL key mapped to true only when every tracked course assigned to it is calculation-valid |
| `calculated.compliance_status` | `compliant` only when every tracked course is calculation-valid; otherwise `non_compliant` |
| `calculated.exceptions` | empty list; invalid/incomplete source state throws instead of becoming an exception string |
| `calculated.total_course_count` | integer count of emitted courses |
| `calculated.total_training_seconds` | literal canonical decimal string `"0"`, equal to the sum of all course `time_spent_seconds` |
| `source.wordpress_version` | injected source-version descriptor, proven equal to B01 WordPress version during preflight |
| `source.learndash_version` | injected source-version descriptor, proven equal to B01 LearnDash version during preflight |
| `source.plugin_version` | injected source-version descriptor, proven equal to B01 dashboard version during preflight |
| `source.source_adapter_key` | literal `learndash-local` |
| `source.source_adapter_version` | literal `1.0.0` |
| `source.source_record_ids` | B09 exact closed ID object |

Every course field is exact:

| E07 course field | Construction |
|---|---|
| `category_order`, `course_order` | normalized audit mapping |
| `certificate_reference`, `certificate_required` | B10 |
| `completed_at_gmt`, `completion_status`, `started_at_gmt` | B08.1 |
| `course_id` | canonical decimal tracked post ID |
| `course_stable_key` | `null` |
| `course_title` | normalized post title |
| `enrollment_status` | B07 |
| `pass_state` | `not_applicable` while quiz-required is false |
| `quiz_attempts`, `quiz_score_basis_points` | B08.2; selected score is the latest completed attempt or `null` when none |
| `source_provenance` | B09 |
| `time_spent_seconds` | literal `"0"` |

The policy digest is:

```text
lowerhex(
  SHA-256(
    "ghca-archive-policy-v1\n"
    + canonical_json(
        {
          "audit_mapping": <exact E07 audit_mapping>,
          "completeness_policy": "snapshot_v1_complete",
          "course_lifespan_rules": <exact E07 lifespan rules>,
          "quiz_policy": <exact E07 quiz policy>,
          "relevant_settings": <exact E07 relevant settings>,
          "tracked_course_ids": <exact E07 tracked IDs>
        }
      )
  )
)
```

The review producer computes this constituent before `RequestArchive`; capture recomputes it from the same source mapping and requires equality with `capture_identity.policy_digest`. A mismatch is `archive_build_binding_invalid/authoritative_load`; it is not overwritten by the adapter.

Independent literal golden vectors are mandatory on PHP 8.3.30 and 8.5.7 for:

1. the policy constituent bytes and `ghca-archive-policy-v1` digest;
2. each B09 course/certificate record-version constituent and digest;
3. the complete E07 canonical bytes;
4. the E08 `ghca-source-fingerprint-v1` digest;
5. organization fallback and source-version injection;
6. completeness, categories, both matrix branches, exceptions, and total fields.

**Owner-gated physical facts**

- Approve the one-course-activity rule and completion cross-check.
- Approve the half-open cycle filter and no pre-cycle carry-forward.
- Approve non-required quiz policy for v1, or provide an exact physical policy source.
- Approve `time_spent_seconds = "0"`.
- Approve zero category order and null stable key.
- Approve time-independent calculation v1 and the P3B3 activation gate for any lifespan/time-dependent replacement.
- Approve the complete E07 matrix and `ghca-archive-policy-v1` policy-digest contract.

**Retained-data effect**

All mappings except the separately stored snapshot capture time determine retained E07/E08 bytes. Any change invokes B02.

### B09 — Source record IDs and record-version derivation

**Recommendation**

`source.source_record_ids` contains only rows actually used by the normalized document:

| Field | Exact IDs |
|---|---|
| `user_id` | bound `users.ID` |
| `group_ids` | effective validated group post IDs |
| `course_post_ids` | tracked course post IDs |
| `course_activity_ids` | retained course activity IDs |
| `quiz_activity_ids` | retained quiz activity IDs |

Every list is unique and unsigned ascending.

`course.source_provenance.record_id` is:

- the decimal course activity ID when a course activity exists;
- otherwise the decimal course post ID.

`record_version` is exactly 64 lowercase hexadecimal characters:

```text
lowerhex(
  SHA-256(
    "ghca-source-record-version-v1\n"
    + canonical_json(
        {
          "adapter_key": "learndash-local",
          "adapter_version": "1.0.0",
          "kind": <closed kind>,
          "record": <closed normalized source projection>
        }
      )
  )
)
```

Closed kind `course_evidence` includes, in exact canonical field order:

```text
course_post
audit_mapping
lifespan_days
enrollment_sources
course_activity
completion_meta
quiz_activities
quiz_meta
certificate_assignment
certificate_post
```

Each object contains only the permitted physical row ID and columns from B05 after scalar normalization. Missing optional records are JSON `null`; lists use B05 order. Raw serialized bytes, unused meta, names, emails, answers, and arbitrary source values are excluded.

Closed kind `certificate_reference` contains only:

```text
assignment_meta_id
course_id
certificate_post_id
certificate_post_type
certificate_post_status
certificate_post_modified_gmt
```

The implementation must add independent literal golden vectors for both kinds. It must use the existing canonical JSON implementation and PHP `hash('sha256', ..., false)`; no new digest abstraction is authorized.

`record_version` is a source-record version, not an archive event ID, command ID, artifact digest, or E08 fingerprint.

**Owner-gated physical facts**

LearnDash does not expose a single source record version. The domain and closed projections above are therefore a new retained contract requiring explicit approval.

**Retained-data effect**

Record IDs and versions are retained. Any formula or projection change invokes B02 and new golden vectors.

### B10 — Certificate assignment and reference

**Recommendation**

For each course:

1. read the one `_ld_certificate` course postmeta row;
2. empty or zero means `certificate_required = false` and `certificate_reference = null`;
3. a positive unsigned ID requires one published LearnDash certificate post;
4. emit:

```text
certificate_reference:
  certificate_post_id: <unsigned ID>
  source_record_version: <B09 certificate_reference digest>
```

Those are the only two keys permitted by E07. Certificate source identity is bound inside `source_record_version`; no third `source_record_id` key is emitted.

Wrong post type/status, duplicate assignment rows, missing certificate posts, or malformed IDs use `archive_certificate_invalid/certificate_gate`.

This mapping captures assignment/reference only. It does not read certificate HTML/content, render, download, acquire, resolve a public URL, or write an artifact.

The accepted P3B2a preparer continues to block certificate-required evidence until certificate acquisition is separately implemented. P3B2b must not weaken that gate.

**Retained-data effect**

The reference and version are retained and invoke B02 if changed.

### B11 — Review-time and capture-time fingerprint parity

**Recommendation**

The review producer and capture task must call the same concrete adapter version with:

- the same B04 descriptor and source-version tuple;
- the same capture bindings and program/cycle inputs;
- the same B08 time-independent calculation v1;
- the same query plan and closed source/field allowlists;
- the same normalization and calculation code;
- the same E07 validator;
- the same canonical JSON implementation;
- the same E08 domain, adapter key, and adapter version.

Neither path may reimplement normalization or compute a fingerprint from a different intermediate document.

Review stores only the resulting E08 fingerprint in the existing request contract. Capture regenerates E07 and compares its E08 fingerprint before snapshot preparation. A mismatch remains `archive_source_drift/fingerprint_compare`.

P3B2b may expose and test the adapter call used by both paths. Runtime review-producer composition remains activation-blocked until P3B3 proves the identical dependency graph. No review controller or hook is authorized here.

P3B3 must also resolve calculation-time authority before enabling any lifespan/expiry calculation. It may not use `ArchiveRequested.occurred_at_gmt` for pre-request review, silently read a wall clock in either path, or claim parity from two different timestamps. Until that decision, P3B2b parity covers only the time-independent E07 contract in B08.

Named activation gate:

```text
P3B2B-REVIEW-CAPTURE-FINGERPRINT-PARITY-GATED
```

**Retained-data effect**

Parity determines the retained fingerprint and snapshot; mapping changes invoke B02.

### B12 — E06 transaction, query, elapsed-budget, rollback, and cancellation contract

**Recommendation**

Each adapter call follows this exact state machine:

1. open the injected isolated source connection;
2. validate the B03/B04 identity and B05 schema/engine preflight;
3. execute `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ`;
4. execute the portable equivalent of `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY`;
5. run only the B05 fixed, bounded, ordered read plan;
6. invoke the injected checkpoint and check the elapsed budget before and after every statement and before normalization;
7. unconditionally `ROLLBACK`, including after a successful read;
8. close the source connection in an unconditional cleanup path;
9. only after successful rollback and closure, normalize and validate outside the transaction.

This proposal intentionally amends P3B2a E06's success-path `COMMIT` to an unconditional `ROLLBACK`. The transaction is read-only; rollback avoids presenting commit as an authoritative outcome and gives success/failure one cleanup path. A prepared result is unusable unless rollback and closure both succeed.

Preflight requires:

- every permitted source relation is a `BASE TABLE`;
- every permitted table is `InnoDB`;
- required columns and indexes exist;
- all resolved tables use the one source schema;
- no view, temporary table, federated table, mixed engine, or archive table is present.

Bounds:

- maximum 32 SQL statements for the whole attempt, including session, transaction, preflight, count, rollback, and cleanup statements;
- maximum 2,000 milliseconds monotonic elapsed time from connection-ready to successful connection close;
- count-first and `LIMIT ceiling + 1` for every multi-row family;
- each E07/snapshot value ceiling remains independent;
- no database transaction contains canonicalization, hashing, snapshot writing, filesystem work, network work, or lifecycle work.

Timeout/cancellation:

- the evidence-source contract is amended only to accept the handler's existing fenced heartbeat as a third argument:

```php
read_consistent_evidence(
    array $capture_identity,
    array $limits,
    callable $checkpoint
): array
```

- `GHCA_ACD_Archive_Evidence_Task_Handler::prepare()` passes its existing `$heartbeat` callable unchanged; no second token, cancellation service, or event bus is introduced;
- the adapter invokes that checkpoint before connection work, before and after every SQL statement, before rollback, and before returning normalized evidence;
- if the checkpoint throws, the adapter issues no further evidence query, unconditionally attempts rollback and close, discards buffered rows, and rethrows the original fencing/cancellation exception after successful cleanup;
- a rollback or close failure instead reports its exact operational-blocked cleanup tuple; a stale worker still cannot submit an outcome or mutate its task;
- P3B2b approves the two seconds as an elapsed budget, checked cooperatively between statements;
- the adapter may stop issuing further statements once the budget is exceeded, then roll back and close;
- P3B2b does not claim it can interrupt an already executing vendor statement;
- driver/server statement cancellation, kill-query privileges, socket timeouts, or vendor session timeout variables remain owner-gated and deferred unless a portable mechanism is independently proven across all three database engines;
- no background cancellation thread, second control connection, or elevated privilege is authorized.

P3B2b defines no independent user cancellation API. Cooperative cancellation is exactly the existing fenced heartbeat/checkpoint failure above. An elapsed-budget cancellation generated by the adapter is `archive_source_query_failed/source_query`. Cancellation after the authoritative source document has been returned is outside this adapter.

**Failure**

- transaction start or in-transaction connection failure: `archive_source_read_failed/transaction_start` or `/source_query`;
- query timeout/elapsed exhaustion: `archive_source_query_failed/source_query`;
- rollback failure: `archive_source_transaction_failed/transaction_rollback`;
- close failure: `archive_source_transaction_failed/connection_close`;
- unsupported table/engine/schema/index/descriptor: `archive_source_schema_unsupported/source_preflight`.

The adapter emits no event. The coordinator applies B13/E14 after authoritative recovery: approved permanent invalid failures may produce the existing closed lifecycle outcome; operational-blocked, integrity, unknown, and lease-loss failures remain event-free.

**Retained-data effect**

Operational except where a different snapshot/query plan changes selected evidence.

### B13 — Closed failure mappings

**Recommendation**

P3B2b adds no failure code and does not classify by message text. The adapter throws; only the accepted coordinator recovery path may decide a lifecycle outcome.

| Condition | Category | Reason | Exact context | Accepted lifecycle route after authoritative recovery |
|---|---|---|---|---|
| capture/tenant/user/program/cycle binding mismatch | invalid | `archive_build_binding_invalid` | `authoritative_load` | closed `ArchiveFailed` only after success/failure replay finds no match and permanent recovery fails |
| malformed or contradictory normalized evidence | invalid | `archive_snapshot_invalid` | `source_validate` | closed `ArchiveFailed` after the same recovery rule |
| prohibited source key/value/structure | invalid | `archive_evidence_prohibited` | `source_validate` | closed `ArchiveFailed` using existing event code `archive_evidence_incomplete` |
| review/capture fingerprint mismatch | invalid | `archive_source_drift` | `fingerprint_compare` | exact atomic `DetectSourceDrift` decision, never generic `ArchiveFailed` |
| pre-query row bound exceeded | invalid | `archive_evidence_incomplete` | `pre_query_limit` | closed `ArchiveFailed` after recovery |
| normalization/value/cardinality bound exceeded | invalid | `archive_evidence_incomplete` | `normalize_limit` | closed `ArchiveFailed` after recovery |
| snapshot byte bound exceeded | invalid | `archive_evidence_incomplete` | `snapshot_byte_limit` | closed `ArchiveFailed` after recovery |
| invalid/missing certificate reference | invalid | `archive_certificate_invalid` | `certificate_gate` | closed `ArchiveFailed` after recovery |
| connection/transaction start failure | retryable | `archive_source_read_failed` | `transaction_start` | no event before attempt five; `archive_build_attempts_exhausted` only after final recovery fails with no matching outcome |
| connection failure during source query | retryable | `archive_source_read_failed` | `source_query` | same exact attempt-five rule |
| statement error or elapsed-budget exhaustion | retryable | `archive_source_query_failed` | `source_query` | same exact attempt-five rule |
| rollback failure | operational_blocked | `archive_source_transaction_failed` | `transaction_rollback` | no lifecycle event at any attempt |
| connection-close failure | operational_blocked | `archive_source_transaction_failed` | `connection_close` | no lifecycle event at any attempt |
| version/table/column/index/engine/principal/descriptor mismatch | operational_blocked | `archive_source_schema_unsupported` | `source_preflight` | no lifecycle event at any attempt |
| proven retained snapshot contradiction | integrity | `archive_immutable_conflict` | `authoritative_recovery` | no additional lifecycle event; manual integrity review |
| proven immutable conflict while committing the snapshot | integrity | `archive_immutable_conflict` | `snapshot_commit` | no additional lifecycle event; manual integrity review |
| unknown/unclassified throwable | operational_blocked | `task_handler_failed` | exact current accepted phase | retry/dead-letter only; no lifecycle event at any attempt |
| fenced heartbeat/checkpoint failure | existing lease-loss category | existing fence reason | fenced phase | stale worker performs no retry/dead/outcome mutation and emits no event |

Operational error text remains sanitized and bounded by the P3A contract. It may contain the adapter key, stage, database family, and stable reason/context. It must not contain SQL text, bound values, table prefixes, database names, hostnames, user names, emails, source values, credentials, serialized bytes, paths, or stack traces.

Attempt-five handling remains accepted E14 exactly: replay matching success first, replay matching failure second, perform the category-appropriate deterministic recovery, and only then apply the lifecycle route above. Response loss after a committed outcome replays that outcome and never becomes `ArchiveFailed`. Operational-blocked, integrity, unknown, and lease-loss failures remain event-free.

**Retained-data effect**

Failure codes are persisted operational contracts. Changing them affects retained operational data.

### B14 — Data minimization and prohibited values

**Recommendation**

The closed table/column/option/meta allowlists in B05 are the primary minimization control.

Permitted retained PII is limited to:

- employee display name;
- employee email;
- employee user ID and approved role/group IDs;
- training course titles, status, dates, quiz pass/score, and approved policy values.

Prohibited:

- PHI;
- PCI/card data;
- passwords, hashes, tokens, session keys, cookies, secrets, IP addresses;
- arbitrary usermeta, postmeta, options, comments, notes, answers, essays, question text, uploaded paths, URLs, HTML, certificate body content;
- source SQL, credentials, host/schema/prefix values, and debug traces.

This is enforced mechanically:

1. select only B05 columns;
2. filter only B05 option/meta keys;
3. use strict decoders accepting arrays and scalar primitives only, with no PHP objects or classes;
4. reject unknown decoded keys before normalization;
5. reject HTML, URLs, paths, control characters, non-finite numbers, resources, references, and structurally forbidden values under the existing E07 validator;
6. never attempt a semantic PHI/PCI classifier.

Serialized WordPress/LearnDash values may be decoded only with classes disabled. Arbitrary PHP objects remain rejected. The audit-mapping `credit_hours` source float is accepted only at the trust boundary under B08's exact quarter-hour rule and is immediately converted to integer minutes.

**Retained-data effect**

Field allowlists define retained evidence; broadening them requires an owner decision and B02 review.

### B15 — Two-connection and failure-injection regressions

**Recommendation**

P3B2b implementation is not reviewable without real, two-connection tests against each approved database family:

- one adapter read connection;
- one independent mutation/control connection;
- archive persistence remains a third logically isolated connection where an end-to-end test needs it.

Tests must prove:

- a mutation committed before snapshot start is visible;
- a mutation committed after snapshot start is invisible;
- multiple related queries see one consistent snapshot;
- a rollback always occurs on success, normalization failure, timeout, cancellation, and query failure;
- connection closure occurs after rollback;
- rollback failure and close failure produce their exact accepted tuples;
- no partial E07 document escapes after cleanup failure;
- no source or archive row is changed by the adapter;
- no unbounded query, unordered selection, or second source transaction occurs;
- the adapter principal can `SELECT` the disposable source tables;
- that principal cannot write or alter source tables and cannot read archive-persistence tables;
- the mutation/control principal is never injected into the adapter.

Every negative regression asserts the exact exception class/category/reason/context, zero lifecycle/event/snapshot/artifact residue, zero source mutation, and no credential/source-value leakage.

### B16 — Runtime/database verification

**Recommendation**

The implementation checkpoint must run every P3B2b unit, physical mapping, transaction, parity, failure-injection, and two-connection regression on:

| Runtime | MySQL 8.0 | MySQL 8.4 | MariaDB 10.6 |
|---|---:|---:|---:|
| PHP 8.3.30 | required | required | required |
| PHP 8.5.7 | required | required | required |

Use only disposable databases named with the approved `ghca_acd_archive_test_` prefix and environment-supplied credentials. PHP 8.4 remains optional/unavailable unless an actual CLI is found.

Disposable source setup is exact:

| Process-local variable | Contract |
|---|---|
| `GHCA_TEST_DB_HOST` | explicit disposable host; no default |
| `GHCA_TEST_DB_PORT` | explicit disposable port; no default |
| `GHCA_TEST_DB_NAME` | archive test schema matching `^ghca_acd_archive_test_[A-Za-z0-9_]+$` |
| `GHCA_TEST_DB_USER` / `GHCA_TEST_DB_PASSWORD` | disposable setup/control credentials supplied by the environment |
| `GHCA_TEST_SOURCE_DB_NAME` | distinct source schema matching `^ghca_acd_archive_test_[A-Za-z0-9_]+_source$` |
| `GHCA_TEST_SOURCE_DB_USER` / `GHCA_TEST_SOURCE_DB_PASSWORD` | new per-cell restricted adapter principal and random secret |
| `GHCA_TEST_DESTRUCTIVE_OPT_IN` | exact string `true` before setup or teardown |

Rules:

1. Every variable is process-local and required. A missing, empty, malformed, or equal archive/source schema value stops the cell before any connection.
2. There is no fallback to WordPress constants, environment files, global `$wpdb`, `wp-config.php`, `wp-load.php`, a default database, or the current site.
3. The setup/control connection creates only the uniquely named disposable source schema, the exact B05 tables/indexes, and bounded synthetic fixtures.
4. It creates the unique source principal with only:

```sql
GRANT SELECT ON `<disposable_source_schema>`.* TO `<restricted_source_principal>`
```

5. `SHOW GRANTS` must contain only implicit `USAGE` and that exact schema-scoped `SELECT`; the test separately proves source `INSERT`/`UPDATE`/`DELETE`/DDL and archive-schema `SELECT` are denied.
6. The adapter receives only the restricted source credentials. The independent mutation connection uses setup/control credentials solely for two-connection tests.
7. Teardown closes all sessions, drops only the exact restricted principal and validated disposable source schema, and refuses cleanup unless the opt-in and safety regex still pass.
8. Credentials are never printed, persisted, documented as values, or inherited outside the one test process.

Each cell also runs the complete existing schema, P1, P2, P3A, P3B1, and P3B2a persistence suites plus the new P3B2b suites. Each runtime also runs kernel, legacy, boundary, digest, `php -n` evidence where applicable, lint, `git diff --check`, and forbidden-surface scans.

No assertion total is frozen in this proposal because the exact new tests do not yet exist. The traceability report must state exact named counts rather than preserve a stale expected total.

### B17 — Mechanical implementation allowlist

**Recommendation**

After approval, P3B2b implementation may add:

```text
includes/archive/infrastructure/class-wpdb-archive-evidence-read-session.php
includes/archive/infrastructure/class-learndash-archive-evidence-source.php
tests/archive/test-p3b2b-evidence-source.php
tests/archive/test-p3b2b-evidence-source-persistence.php
tests/archive/test-p3b2b-evidence-source-concurrency.php
docs/superpowers/plans/2026-07-29-dual-layer-archive-slice-1b-p3b2b-traceability.md
```

It may modify only:

```text
includes/archive/contracts/class-archive-evidence-source.php
includes/archive/application/class-archive-evidence-task-handler.php
tests/archive/bootstrap.php
tests/archive/test-p3-boundaries.php
tests/archive/test-all.ps1
docs/superpowers/plans/2026-07-28-dual-layer-archive-slice-1b-p3b2b-evidence-source-adapter-decisions-proposal.md
```

The evidence-source interface and handler modifications are limited to B12's third-argument fenced checkpoint; the handler passes its existing heartbeat unchanged. No calculation timestamp, runtime descriptor loader, or cancellation service is added. Boundary changes may permit only the two named source/read-session production files and must continue rejecting runtime wiring, hooks, schedulers, controllers, activation, additional handlers, and entrypoint references.

The proposal may be modified after owner review only to record the exact approval or a dated owner amendment. No other production, test, schema, metadata, entrypoint, accepted traceability, or documentation file is in scope.

If implementation evidence proves another file is necessary, work stops for a narrow owner allowlist amendment.

### B18 — Explicit deferrals

P3B2b does not authorize:

- runtime source-connection or secret provisioning;
- production code-version attestation or construction of the source-version descriptor;
- multisite runtime descriptor discovery;
- global `$wpdb`, `switch_to_blog()`, WordPress bootstrap, or plugin entrypoint wiring;
- hooks, cron, Action Scheduler, registered WP-CLI, REST, admin controllers, or worker wake-up;
- feature activation or current-site migration;
- certificate acquisition, rendering, storage, or download;
- packet rendering;
- verification/finalization handlers or outcomes;
- D16 lifecycle retry/recovery changes;
- P3B3 concurrency/runtime values or registration;
- reset, projection rebuild, or source-drift controller execution;
- network access;
- schema changes;
- deployment.

## 6. Implementation-blocking owner decisions

The owner must decide each item; local source evidence does not resolve it safely:

1. **B01:** preserve the distributed PHP 8.3+ floor, require PHP 8.3.30/8.5.7 verification, and approve the exact closed WordPress/LearnDash/dashboard source-layout tuple.
2. **B02:** approve `learndash-local` / `1.0.0` and the byte-affecting compatibility rule.
3. **B03:** approve a distinct SELECT-only principal/connection, the exact immutable three-field source-version descriptor, zero-query mismatch rejection, and the P3B3 non-spoofable code-attestation gate.
4. **B04:** approve one site/blog as one tenant and the exact injected multisite table descriptor.
5. **B05:** approve the closed table/column/option/meta allowlists, required-index manifest, and fixed bounded query plan.
6. **B06:** approve capabilities-plus-role-definition mapping, descendant group expansion, and `external_employee_key = null`.
7. **B07:** approve annual/new-hire tracked-course authorities and `direct > group > open` provenance.
8. **B08:** approve the one-course-activity rule, cycle behavior, quiz-not-required policy, `"0"` time spent, null stable key, zero category order, complete E07 construction matrix/policy digest, time-independent calculation v1, and P3B3 calculation-time gate.
9. **B09:** approve the new source record ID/version domains and closed projections.
10. **B10:** approve reference-only certificate mapping and retention of the P3B2a acquisition gate.
11. **B11:** approve identical review/capture adapter composition as an activation gate.
12. **B12:** approve unconditional rollback, the 32-statement/two-second budgets, reuse of the fenced heartbeat as the source checkpoint, and deferral of in-flight hard cancellation.
13. **B13:** approve reuse of the exact closed failure tuples without new codes.
14. **B14:** approve mechanical source/field minimization and no semantic PHI/PCI detector.
15. **B15:** approve the mandatory two-connection and cleanup/failure-injection tests.
16. **B16:** approve the PHP 8.3.30/8.5.7 by three-database matrix and exact disposable source-schema/restricted-grant setup.
17. **B17:** approve the mechanical implementation allowlist.
18. **B18:** approve every explicit deferral.

## 7. Exact approval wording

Implementation may begin only after the owner states:

> **Approve P3B2b Decisions B01–B18 as written, including the PHP 8.3+ distribution floor, exact source-version tuple and immutable three-field descriptor, P3B3 non-configurable code-version-attestation gate, complete E07/policy-digest mapping, descriptor and index manifests, record-version domains, time-independent calculation v1 with its P3B3 calculation-time gate, E06 unconditional-rollback amendment, fenced checkpoint, elapsed-only timeout contract, disposable restricted-source test setup, implementation allowlist, and all P3B3/runtime/certificate deferrals.**

Any qualified approval must be recorded as a dated amendment and must resolve every affected blocker before implementation.

## 8. Unresolved blockers pending that approval

- No source evidence proves compatibility beyond WordPress 7.0.2, LearnDash 5.1.6.1, and dashboard 1.2.0.
- Production code-version provenance is not yet defined; P3B3 must provide non-configurable code attestation before it may construct the exact P3B2b source-version descriptor.
- LearnDash exposes an enrollment union but no archive provenance precedence.
- The dashboard exposes category keys and course sort order but no separate category order.
- No approved physical setting makes quiz passage mandatory for archive compliance.
- No approved bounded source supplies deterministic course-level time spent.
- LearnDash current progress is not an archive-specific cycle history; pre-cycle carry-forward requires the explicit B08 decision.
- LearnDash exposes no single record-version field; B09 is a proposed derived retained contract.
- Time-dependent lifespan/expiry calculation remains P3B3-blocked because no authoritative pre-review timestamp is retained for both review and capture; P3B2b v1 is explicitly time-independent.
- A portable mechanism to interrupt an already executing statement within two seconds has not been proven across MySQL 8.0, MySQL 8.4, and MariaDB 10.6.
- Production principal provisioning, table-descriptor resolution, and review/capture dependency composition remain P3B3 activation blockers.

## 9. Named regression matrix

After approval, the minimum named regressions are:

### Versions, descriptors, and schema

- `P3B2B-SUPPORTED-VERSION-TUPLE-ACCEPTED`
- `P3B2B-UNKNOWN-VERSION-REJECTED-BEFORE-EVIDENCE-QUERY`
- `P3B2B-SOURCE-VERSION-DESCRIPTOR-EXACT-THREE-FIELD-ACCEPTED`
- `P3B2B-SOURCE-VERSION-DESCRIPTOR-MISSING-OR-EXTRA-FIELD-REJECTED`
- `P3B2B-SOURCE-VERSION-DESCRIPTOR-MALFORMED-OR-SPOOFED-VALUE-REJECTED-BEFORE-QUERY`
- `P3B2B-SOURCE-VERSION-DESCRIPTOR-DETACHED-FROM-CALLER-MUTATION`
- `P3B2B-P3B3-NONCONFIGURABLE-CODE-VERSION-ATTESTATION-GATE`
- `P3B2B-PHP83-PLUS-DISTRIBUTION-FLOOR-PRESERVED`
- `P3B2B-VERIFICATION-BINARIES-DO-NOT-NARROW-PHP-FLOOR`
- `P3B2B-DEDICATED-READONLY-PRINCIPAL-REQUIRED`
- `P3B2B-ARCHIVE-CONNECTION-REUSE-REJECTED`
- `P3B2B-MULTISITE-BLOG-PREFIX-DESCRIPTOR-VALIDATED`
- `P3B2B-ARCHIVE-SITE-ID-MUST-EQUAL-WORDPRESS-BLOG-ID`
- `P3B2B-IDENTIFIER-ASCII-GRAMMAR-AND-LENGTH-ENFORCED`
- `P3B2B-DOT-BACKTICK-NUL-QUALIFICATION-INJECTION-REJECTED`
- `P3B2B-CROSS-SCHEMA-OR-MIXED-PREFIX-REJECTED`
- `P3B2B-BASE-TABLE-INNODB-PREFLIGHT`
- `P3B2B-VIEW-MISSING-COLUMN-INDEX-OR-ENGINE-REJECTED`
- `P3B2B-EXACT-REQUIRED-INDEX-MANIFEST`

### Physical mapping and minimization

- `P3B2B-CLOSED-OPTION-AND-META-ALLOWLIST`
- `P3B2B-UNKNOWN-SERIALIZED-KEY-REJECTED`
- `P3B2B-PHP-OBJECT-SERIALIZATION-REJECTED`
- `P3B2B-USER-IDENTITY-FALLBACK-EXCLUDES-LOGIN`
- `P3B2B-ROLE-KEYS-INTERSECT-REGISTERED-ROLES`
- `P3B2B-GROUP-DIRECT-AND-DESCENDANT-MAPPING`
- `P3B2B-GROUP-CYCLE-DEPTH-AND-COUNT-REJECTED`
- `P3B2B-ANNUAL-AND-NEW-HIRE-TRACKED-COURSE-SETS`
- `P3B2B-DIRECT-GROUP-OPEN-ENROLLMENT-PRECEDENCE`
- `P3B2B-NO-ARBITRARY-META-PROQUIZ-ANSWER-OR-PHI-QUERY`

### Course, quiz, calculation, and certificates

- `P3B2B-COURSE-ACTIVITY-AND-COMPLETION-META-AGREE`
- `P3B2B-DUPLICATE-OR-CONTRADICTORY-COURSE-ACTIVITY-REJECTED`
- `P3B2B-CYCLE-HALF-OPEN-TIMESTAMP-FILTER`
- `P3B2B-PRE-CYCLE-CARRY-FORWARD-REJECTED`
- `P3B2B-QUIZ-ORDER-PASS-AND-DECIMAL-SCORE`
- `P3B2B-QUIZ-NOT-REQUIRED-PASS-STATE`
- `P3B2B-TIME-SPENT-DECIMAL-ZERO-AND-NO-TIMER-QUERY`
- `P3B2B-CALCULATION-V1-READS-NO-CLOCK`
- `P3B2B-TIME-DEPENDENT-CALCULATION-REMAINS-P3B3-GATED`
- `P3B2B-CATEGORY-AND-OVERALL-COMPLIANCE-CALCULATION`
- `P3B2B-COMPLETE-E07-CONSTRUCTION-PASSES-ACCEPTED-VALIDATOR`
- `P3B2B-ORGANIZATION-FALLBACK-AND-SOURCE-VERSION-INJECTION`
- `P3B2B-COMPLETENESS-CALCULATED-MATRIX-AND-EXCEPTIONS-EXACT`
- `P3B2B-CERTIFICATE-REFERENCE-ONLY`
- `P3B2B-CERTIFICATE-REFERENCE-EXACTLY-TWO-KEYS`
- `P3B2B-CERTIFICATE-CONTENT-AND-ACQUISITION-ABSENT`

### IDs, versions, and fingerprint parity

- `P3B2B-SOURCE-RECORD-ID-SETS-DETERMINISTIC`
- `P3B2B-COURSE-RECORD-VERSION-INDEPENDENT-GOLDEN`
- `P3B2B-CERTIFICATE-RECORD-VERSION-INDEPENDENT-GOLDEN`
- `P3B2B-POLICY-CONSTITUENT-AND-DIGEST-INDEPENDENT-GOLDEN`
- `P3B2B-POLICY-DIGEST-MUST-EQUAL-AUTHORITATIVE-IDENTITY`
- `P3B2B-FULL-E07-AND-E08-INDEPENDENT-GOLDENS`
- `P3B2B-UNUSED-SOURCE-VALUE-DOES-NOT-ALTER-RECORD-VERSION`
- `P3B2B-USED-SOURCE-VALUE-ALTERS-RECORD-VERSION`
- `P3B2B-REVIEW-CAPTURE-E07-BYTES-IDENTICAL`
- `P3B2B-REVIEW-CAPTURE-E08-FINGERPRINT-IDENTICAL`
- `P3B2B-ADAPTER-VERSION-CHANGE-ALTERS-FINGERPRINT`

### Transaction, concurrency, and cleanup

- `P3B2B-QUERY-PLAN-ORDER-AND-COUNT-AT-MOST-32`
- `P3B2B-COUNT-FIRST-AND-LIMIT-CEILING-PLUS-ONE`
- `P3B2B-MUTATION-BEFORE-SNAPSHOT-IS-VISIBLE`
- `P3B2B-MUTATION-AFTER-SNAPSHOT-IS-NOT-VISIBLE`
- `P3B2B-MULTIQUERY-READS-ONE-CONSISTENT-SNAPSHOT`
- `P3B2B-SUCCESS-ROLLS-BACK-AND-CLOSES`
- `P3B2B-QUERY-FAILURE-ROLLS-BACK-AND-CLOSES`
- `P3B2B-NORMALIZATION-FAILURE-HAPPENS-AFTER-CLEANUP`
- `P3B2B-ELAPSED-BUDGET-STOPS-BETWEEN-STATEMENTS`
- `P3B2B-FENCED-HEARTBEAT-IS-SOURCE-CHECKPOINT`
- `P3B2B-CHECKPOINT-CANCELLATION-ROLLS-BACK-CLOSES-AND-DISCARDS`
- `P3B2B-ROLLBACK-FAILURE-DISCARDS-RESULT`
- `P3B2B-CLOSE-FAILURE-DISCARDS-RESULT`
- `P3B2B-NO-SOURCE-OR-ARCHIVE-MUTATION`
- `P3B2B-DISPOSABLE-SOURCE-SCHEMA-SAFETY-PREFIX-AND-OPT-IN`
- `P3B2B-RESTRICTED-SOURCE-GRANT-IS-SELECT-ONLY`
- `P3B2B-RESTRICTED-SOURCE-PRINCIPAL-CANNOT-READ-ARCHIVE`
- `P3B2B-MISSING-TEST-ENV-NEVER-FALLS-BACK-TO-CURRENT-SITE`

### Boundaries and full verification

- `P3B2B-NO-GLOBAL-WPDB-WP-BOOTSTRAP-OR-CURRENT-SITE-ACCESS`
- `P3B2B-NO-RUNTIME-SECRET-OR-TABLE-DISCOVERY`
- `P3B2B-NO-HOOK-CRON-SCHEDULER-CONTROLLER-ACTIVATION-OR-ENTRYPOINT`
- `P3B2B-NO-NETWORK-CERTIFICATE-PACKET-VERIFY-FINALIZE-OR-P3B3`
- `P3B2B-NO-SCHEMA-CHANGE`
- `P3B2B-EXACT-FAILURE-TUPLES-AND-SANITIZED-TEXT`
- `P3B2B-PERMANENT-INVALID-FAILURES-USE-ACCEPTED-LIFECYCLE-RECOVERY`
- `P3B2B-OPERATIONAL-INTEGRITY-UNKNOWN-AND-FENCE-FAILURES-EMIT-NO-EVENT`
- `P3B2B-IMMUTABLE-CONFLICT-CONTEXTS-EXACT`
- `P3B2B-PHP83-PHP85-THREE-DATABASE-MATRIX`
- `P3B2B-ALL-PRIOR-ARCHIVE-BASELINES-UNCHANGED`

## 10. Proposal-only completion statement

This checkpoint created only this proposal. It performed no current-site access and no production implementation. Branch and HEAD remained:

```text
feature/dual-layer-archive-slice-1b-p3b2b-evidence-source-adapter
54ff365e95cacd380161f86ae63e1a5399c2ef7e
```

The final proposal-review verification used these exact non-database suites:

| Runtime | Boundary command/result | P3 digest command/result |
|---|---|---|
| PHP 8.3.30 | `php.exe tests/archive/test-p3-boundaries.php` — 13/13 | `php.exe tests/archive/test-p3-digests.php` — 9/9 |
| PHP 8.5.7 | `php.exe tests/archive/test-p3-boundaries.php` — 13/13 | `php.exe tests/archive/test-p3-digests.php` — 9/9 |

The earlier 24/24 P3-digest handoff is withdrawn; it did not report `tests/archive/test-p3-digests.php`. The authoritative proposal result is 9/9 per runtime above.

Nothing was staged, committed, pushed, deployed, activated, or added to runtime wiring. `.claude/` remained untouched.

## 11. 2026-07-28 formal re-review remediation amendments

The owner authorized the following narrow clarifications without reopening B01-B18:

1. `policy.tracked_course_ids` is numeric-ID ascending, while retained `courses` remains ordered by `(category_order, course_order, course_id)`. Validators compare exact numeric-sorted membership sets and do not rewrite display order.
2. Serialized source values reject PHP references, recursive/cyclic graphs, objects, resources, depth greater than 32, more than 10,000 values, and strings greater than 262,144 bytes. The tracked-course ceiling is enforced before course-dependent SQL placeholders.
3. Cleanup always attempts rollback and close. A rollback failure or exception has priority as `transaction_rollback`; otherwise a close failure or exception is `connection_close`; otherwise the original pending/fence result is retained.
4. Principal preflight accepts only one exact `USAGE` grant and one exact source-schema `SELECT` grant for the same account. Combined privileges, other schemas, table grants, duplicate grants, and `WITH GRANT OPTION` are rejected.
5. Missing, malformed, spoofed, or inconsistent certificate assignment/reference records use only `archive_certificate_invalid` / `certificate_gate`.
6. `maximum_transaction_milliseconds` accepts positive integers through 2,000 inclusive and rejects 2,001, zero, negative, string, null, or otherwise malformed values before source queries.
7. Structural/version/schema/engine/principal mismatches remain `archive_source_schema_unsupported` / `source_preflight`; connection/query execution failures during preflight use the existing retryable `archive_source_read_failed` / `source_query` tuple.
8. The permanent matrix runner must execute all three P3B2b suites in every cell and create process-local disposable source-schema credentials.

The owner separately approved modifying `class-wpdb-archive-snapshot-store.php` only so tracked and retained course IDs are compared as exact numeric-sorted membership sets while retained course display order and every other snapshot validation remain unchanged. The approved P3B2a regressions cover accepted order independence plus missing, duplicate, and additional membership rejection and immutable database reload.

**Status: owner-approved; P3B2b implementation authorized within B17.**

## 12. 2026-07-28 remaining formal-review remediation amendments

The owner directed these mechanical corrections without reopening B01-B18:

1. Exceptions thrown by either transaction-start statement are translated to `retryable` / `archive_source_read_failed` / `transaction_start` with only the canonical sanitized message. A failed `SET TRANSACTION` closes without rollback; an attempted `START TRANSACTION` is treated as possibly started and is rolled back before close.
2. Validly formed but contradictory site/blog bindings and mixed base/blog/table prefixes are `invalid` / `archive_build_binding_invalid` / `authoritative_load`. Invalid identifier grammar, cross-schema names, and unsupported physical schema remain `operational_blocked` / `archive_source_schema_unsupported` / `source_preflight`.
3. The 2,000 millisecond monotonic elapsed budget ends only after mandatory rollback and successful connection close. Cleanup always finishes first. Rollback failure retains first priority, close failure second, an existing pending/fence throwable third, and only an otherwise successful over-budget result becomes `retryable` / `archive_source_query_failed` / `source_query`.

The named regressions are:

- `P3B2B-SET-TRANSACTION-THROWING-IS-SANITIZED-RETRYABLE`
- `P3B2B-START-TRANSACTION-THROWING-IS-SANITIZED-RETRYABLE`
- `P3B2B-TRANSACTION-START-EXCEPTION-MESSAGES-ARE-CANONICAL-AND-SANITIZED`
- `P3B2B-SITE-BLOG-MISMATCH-IS-AUTHORITATIVE-BINDING-INVALID`
- `P3B2B-MIXED-BASE-BLOG-AND-TABLE-PREFIXES-ARE-AUTHORITATIVE-BINDING-INVALID`
- `P3B2B-INVALID-IDENTIFIER-AND-CROSS-SCHEMA-RETAIN-SOURCE-PREFLIGHT`
- `P3B2B-UNSUPPORTED-PHYSICAL-SCHEMA-RETAINS-SOURCE-PREFLIGHT`
- `P3B2B-DEADLINE-CROSSED-DURING-ROLLBACK-FAILS-AFTER-CLOSE`
- `P3B2B-DEADLINE-CROSSED-DURING-CLOSE-FAILS-AFTER-CLOSE`
- `P3B2B-ROLLBACK-THEN-CLOSE-FAILURES-RETAIN-PRIORITY-OVER-DEADLINE`
- `P3B2B-EXACT-FENCE-THROWABLE-REMAINS-UNCHANGED-AFTER-DEADLINE-CLEANUP`

**Status: formally accepted by the owner on 2026-07-28 after remediation re-review.**
