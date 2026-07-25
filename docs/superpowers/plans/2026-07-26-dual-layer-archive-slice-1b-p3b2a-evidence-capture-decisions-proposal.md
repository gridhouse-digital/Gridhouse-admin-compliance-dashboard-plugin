# Dual-Layer Archive Slice 1B-P3B2a Evidence-Capture Decisions Proposal

**Status:** Formally approved by the owner; P3B2a implementation is authorized within Decisions E01-E18.

**Owner authorization:** “Approve P3B2a Decisions E01-E18 as written, including the explicit current-site-access and certificate-acquisition deferrals.”

**Proposal branch:** `feature/dual-layer-archive-slice-1b-p3b2a-evidence-capture-proposal`

**Proposal base / HEAD:** `6c1a07eda1922e166be03636613429d5988c2258` (`feat(archive): add dark ledger materialization worker`)

**Date:** 2026-07-26

## 1. Purpose and authority boundary

This record proposes Decisions E01-E18 for the smallest safe evidence-capture vertical slice. It is a decision record, not an implementation authorization or implementation plan.

The following inherited contracts remain authoritative and unchanged:

- the append-only event stream and aggregate are the lifecycle authority;
- snapshot schema version 1, canonical format `ghca-cjson-1`, the existing event catalog, and existing digest domains are frozen;
- the existing immutable snapshot, certificate descriptor, command receipt, task lease, task fence, and Unit of Work rules remain in force;
- task schema version is 1, maximum attempts is 5, and the exact completed outcome document remains `{"result_code":"committed"}`;
- PHP 8.3 is the minimum, with exact verification on PHP 8.3.30 and PHP 8.5.7;
- P3B2a remains dark and constructor-injected;
- current-site reads, runtime composition, certificate acquisition, packet rendering, verification/finalization, P3B3 wiring, and D16 lifecycle retry remain unapproved.

Installed dashboard and LearnDash source code is discovery evidence only. It does not authorize current-site access and is not silently promoted into the evidence contract.

## 2. Preflight evidence

| Check | Result |
|---|---|
| Exact branch | `feature/dual-layer-archive-slice-1b-p3b2a-evidence-capture-proposal` |
| Exact HEAD | `6c1a07eda1922e166be03636613429d5988c2258` |
| Accepted P3B1 in branch history | Yes; HEAD is the formally accepted P3B1 ledger-worker commit and its traceability record is marked formally accepted |
| Staged files before proposal | 0 |
| Tracked modifications before proposal | 0 |
| Pre-existing untracked path | `.claude/` only; it was not read or modified |
| Plugin-entrypoint archive references | 0 |

## 3. Decision summary

| Decision | Recommended result |
|---|---|
| E01 | Dark capture coordinator, validation, preparation, and fake source only; physical source access deferred |
| E02 | Install `capture_evidence` v1 only for initial/replacement request tasks with the existing closed six-field payload |
| E03 | Freeze one interface under `includes/archive/contracts/`; inject fakes in P3B2a |
| E04 | Require later authorization for a real adapter/current-site access and gate reviewed-fingerprint provenance |
| E05 | Freeze only source mappings proven by local source; keep physical tables and unresolved facts owner-gated |
| E06 | Freeze the later adapter's repeatable-read contract; P3B2a implements no physical query or transaction |
| E07 | Freeze `normalized-evidence-document-v1` with exact types, ordering, normalization, and unknown-field rejection |
| E08 | Hash the complete normalized document with existing `ghca-source-fingerprint-v1` and a literal golden vector |
| E09 | Compare reviewed and captured fingerprints exactly; mismatch is source drift, never silent recapture |
| E10 | Deterministically map the normalized document into existing snapshot v1; no schema or event mutation |
| E11 | Preserve row/asset/byte limits; apply the 10,000-value limit independently to E07 and snapshot documents |
| E12 | Enforce exact source-column/result-field allowlists and mechanically detectable structural prohibitions |
| E13 | Retain only bounded certificate eligibility/reference facts; no certificate bytes or acquisition |
| E14 | Keep operational/unclassified failures operational at every attempt; use receipt-first attempt-five recovery |
| E15 | Add a separate capture-ID path while preserving every P3B1 ID byte-identically |
| E16 | Freeze exact outcomes for before/during/after-snapshot changes, response loss, and competing workers |
| E17 | Permit only named dark files; the source contract is an interface under `contracts/` |
| E18 | Require the 2x3 dark-slice matrix; defer physical transaction/source tests to the adapter slice |

## 4. Proposed decisions

### E01 - P3B2a scope

**Question.** What is the exact implementation scope that a later P3B2a authorization may cover?

**Recommended decision.** P3B2a may later implement only:

1. the closed `capture_evidence` task installation and validation;
2. an injected local evidence-source interface;
3. validation of the exact E07 document returned by a fake source;
4. deterministic preparation from the validated E07 document;
5. source fingerprint calculation and reviewed/captured comparison;
6. deterministic construction of the existing immutable snapshot v1 document;
7. fenced, receipt-replayable `StartBuild` and `RecordEvidenceSnapshot` submissions through the existing Unit of Work;
8. task completion only after the authoritative snapshot command commits or its stored receipt is replayed;
9. closed failure classification and attempt-five recovery.

P3B2a does not implement a physical source adapter, SQL query, database connection, E06 transaction, query cancellation, connection timeout, or source-specific database test. Those belong to a later, separately authorized source-adapter slice. P3B2a fake-source tests prove only the dark coordinator, result validation, preparation, command fencing/replay, and operational disposition contracts; they are not evidence of production transaction behavior.

It excludes certificate bytes/acquisition, packet or PDF rendering, ledger changes, final verification, lifecycle retry execution, reset work, runtime wake-up, configuration loading, hooks, cron, controllers, WP-CLI registration, activation, deployment, and P3B3.

**Rejected alternatives.**

- A combined capture/certificate/packet slice: rejected because it crosses independently unapproved trust and rendering boundaries.
- Runtime composition in the same slice: rejected because current-site access and production activation require separate authority.
- A new event, snapshot schema, queue, canonicalizer, or digest: rejected because the accepted contracts already represent the outcome.

**Reasoning and risks.** A narrow slice can prove deterministic capture preparation and crash recovery without claiming that fake transactions prove production database behavior, granting production data access, or conflating external certificate work with a database snapshot.

**Frozen implementation effect.** Later P3B2a implementation must remain directly callable by tests and constructor-injected. The only source implementation in this slice is a test fake. No P3B2a class may self-load WordPress, credentials, globals, configuration, or physical source queries.

**Retained-data or compatibility effect.** No schema or event compatibility change. Successful work adds only already-approved `ArchiveBuildStarted`, `EvidenceSnapshotCaptured`, immutable snapshot v1, and command-receipt records.

**Exact owner-approval wording.** `Approve E01 as recommended: P3B2a is the fake-backed dark capture coordinator/validation/preparation slice; the physical source adapter and E06 transaction are separately authorized later work.`

### E02 - Trigger and task contract

**Question.** Which events and exact retained task payload may invoke P3B2a?

**Recommended decision.**

- Installed task type: `capture_evidence`.
- Task schema: integer `1`.
- Allowed trigger event types: exactly `ArchiveRequested` and `ReplacementArchiveRequested`.
- Trigger kind: exactly `event`.
- The canonical payload has exactly these keys in this lexicographic order:

| Key | Exact type/value | Authoritative source |
|---|---|---|
| `archive_id` | 32 lowercase hexadecimal characters | triggering event payload |
| `canonical_format_version` | literal `ghca-cjson-1` | Unit of Work |
| `stream_id` | 32 lowercase hexadecimal characters | triggering event envelope |
| `task_schema_version` | integer `1` | task store |
| `task_type` | literal `capture_evidence` | Unit of Work |
| `trigger_event_id` | 32 lowercase hexadecimal characters | triggering event envelope |

Every field is a server-derived fact. The task contains no new caller intent. The handler must reload the trigger event and authoritative stream and prove the payload, task row, event envelope, request payload, Archive Case, revision, policy, cycle, and scope agree.

Existing task dedupe is unchanged:

`SHA-256("ghca-archive-task-dedupe-v1\n" + canonical_json({"payload": payload, "task_type": "capture_evidence", "trigger_event_id": trigger_event_id}))`.

The completed task outcome identity is unchanged:

`SHA-256("ghca-task-outcome-v1\n" + canonical_json({"logical_outcome":"completed","task_id":task_id,"task_schema_version":1}))`.

That same outcome key may be used in different command-specific idempotency scopes for `StartBuild`, `RecordEvidenceSnapshot`, or the authoritative closed failure decision. Scope separation prevents receipt collision.

`ArchiveRetryRequested` capture tasks are not an installed lifecycle path in P3B2a. If a retained task of that shape is claimed, validation rejects it before source access, handler invocation, or command submission with permanent task code `task_payload_invalid`. No lifecycle event is permitted. D16 must separately decide whether such tasks are later filtered, migrated, or executed.

**Rejected alternatives.**

- Adding `build_attempt_id`, `snapshot_id`, employee data, policy data, or credentials to the retained task: rejected because the current task contract and authoritative stream can derive them.
- Treating `ArchiveRetryRequested` as an allowed trigger: rejected because D16 is explicitly deferred.
- A second task schema: rejected because no retained task mutation is necessary.

**Reasoning and risks.** The existing six-field task is sufficient. A closed trigger check prevents a same-type retained retry task from silently expanding P3B2a.

**Frozen implementation effect.** The task catalog adds one exact validator and one installed type. The coordinator must validate trigger type before source access.

**Retained-data or compatibility effect.** No existing task bytes change. Old valid initial/replacement tasks remain processable; retry-triggered tasks remain unexecuted by design.

**Exact owner-approval wording.** `Approve E02 as recommended, including the exact six-field capture_evidence v1 payload and rejection of ArchiveRetryRequested without side effects.`

### E03 - Evidence-source interface

**Question.** What injected source boundary may later expose local WordPress/LearnDash evidence?

**Recommended decision.** Freeze `GHCA_ACD_Archive_Evidence_Source` as an interface at `includes/archive/contracts/class-archive-evidence-source.php` with one operation:

`read_consistent_evidence(capture_identity, limits) -> normalized_evidence_document_v1`

`capture_identity` has exactly:

- `case_key`: the canonical existing Archive Case key document;
- `archive_id`, `stream_id`, `trigger_event_id`: 32-character lowercase hexadecimal identifiers;
- `revision_number`: positive integer;
- `policy_digest`, `reviewed_source_fingerprint`, `subject_scope_digest`: 64-character lowercase hexadecimal digests;
- `resolved_cycle`: the exact event-bound cycle document.

`limits` has exactly:

- `maximum_rows`: integer `10000`;
- `maximum_values`: integer `10000`;
- `maximum_assets`: integer `10000`;
- `maximum_snapshot_bytes`: integer `1048576`;
- `maximum_queries`: integer `32`;
- `maximum_transaction_milliseconds`: integer `2000`.

The result is exactly the E07 document. It contains no WordPress objects, database handles, query builders, passwords, salts, cookies, nonces, access tokens, URLs, HTML, filesystem paths, streams, resources, closures, callbacks, or lazy/mutable objects.

The interface may fail only with `GHCA_ACD_Archive_Evidence_Source_Exception`, carrying:

- category: one of `invalid`, `retryable`, `operational_blocked`, `integrity`;
- stable reason: one of the E14 source reasons;
- safe fixed message selected by reason;
- operation context: one of the E14 closed contexts.

Exception messages never classify behavior.

**Rejected alternatives.**

- Returning `WP_User`, `WP_Post`, LearnDash model objects, or raw rows: rejected because they are mutable and encoding-dependent.
- Letting the source calculate lifecycle events or call the Unit of Work: rejected because the source is a read boundary only.
- Passing a mutable heartbeat callback into the source result: rejected; the coordinator owns heartbeat calls between bounded phases, never inside result data.

**Reasoning and risks.** A single document boundary makes normalization, limits, fingerprints, fixtures, and cross-runtime results testable without WordPress bootstrap.

**Frozen implementation effect.** The interface is constructor-injected. P3B2a supplies no production implementation; test fakes drive every P3B2a source call without current-site access. “Abstract class or interface” is not left to implementation judgment.

**Retained-data or compatibility effect.** The returned document is transient until mapped to snapshot v1. Changing its frozen grammar later changes source fingerprints and requires an explicit version/compatibility decision.

**Exact owner-approval wording.** `Approve E03 as recommended, including the exact read_consistent_evidence identity, limits, result, and closed failure surface.`

### E04 - Current-site read authorization

**Question.** Does approval of this proposal authorize implementation or production reads against the current WordPress site?

**Recommended decision.** No. Before any real adapter or runtime composition may read the current site, the owner must separately authorize all of:

1. a separately approved source-adapter implementation slice;
2. the exact resolved, site-prefixed tables;
3. the exact meta/option keys and columns;
4. the read-only database principal and privileges;
5. the E06 transaction/isolation statements on the supported database matrix;
6. the WordPress/LearnDash version range and source-schema checks;
7. multisite site/tenant resolution;
8. constructor composition and secret/configuration source;
9. production activation and rollback procedure;
10. the E09 reviewed-fingerprint provenance gate.

Source inspection performed for this proposal grants none of that authority. P3B2a implementation tests must use fakes and disposable prefixed databases only.

**Rejected alternatives.**

- Reusing current-site credentials or global `$wpdb`: rejected because neither proves a read-only principal nor an isolated connection.
- Loading `wp-load.php` or `wp-config.php` in tests: rejected.
- Treating installed code as implicit authorization: rejected.

**Reasoning and risks.** Consistent reads require a dedicated connection and exact source contract; normal runtime WordPress access does not provide either automatically.

**Frozen implementation effect.** No production source adapter, physical source query, E06 transaction, runtime key/config loader, composition root, or activation wiring is permitted by P3B2a approval alone.

**Retained-data or compatibility effect.** None. This is an operational authority gate.

**Exact owner-approval wording.** `Approve E04 as recommended: P3B2a approval does not authorize current-site reads, runtime composition, or activation.`

### E05 - Exact WordPress/LearnDash source mapping

**Question.** Which source facts are proven locally, derived, excluded, or still unresolved?

**Recommended decision.** Freeze the following classification. “Proven” means local source proves the semantic field/key or LearnDash logical table, not that P3B2a is authorized to query the current site.

| Evidence field | Classification | Local source evidence and proposed treatment |
|---|---|---|
| employee user ID | Proven authoritative source | WordPress user ID used throughout dashboard/LearnDash; normalize as positive unsigned decimal string |
| display name | Proven authoritative source | dashboard reads `first_name`, `last_name`, and user display data; required by snapshot v1, normalized text only |
| email | Proven authoritative source | WordPress user email; required by snapshot v1, normalized/lowercased valid email only |
| registration time | Proven authoritative source | WordPress `user_registered`; normalize to UTC |
| WordPress role keys | Proven semantic source; physical mapping unresolved | dashboard uses `WP_User::roles`; exact capabilities meta/table read remains owner-gated |
| external employee key | Unresolved owner decision | no accepted local authoritative key was proven; bounded options are `null` or a separately approved exact meta key |
| group membership | Proven semantic candidates; physical read unresolved | `learndash_is_user_in_group`, `learndash_get_users_group_ids`, and meta key pattern `learndash_group_users_{group_id}` are present; hierarchical behavior and exact query remain gated |
| tracked course set | Proven plugin-policy source | option `ghca_acd_audit_mapping`, plus configured new-hire group option `ghca_new_hire_group_ids`; the archive policy must choose one exact program-specific set |
| group-to-course membership | Proven semantic candidate; physical read unresolved | LearnDash `learndash_group_enrolled_courses`; local source shows post-meta pattern `learndash_group_enrolled_{group_id}` |
| direct course enrollment/access | Proven semantic candidates; physical read unresolved | `learndash_user_get_enrolled_courses`; local LearnDash composes open, direct meta, and group courses and proves meta pattern `course_{course_id}_access_from`; exact inclusion policy remains gated |
| course title and post version | Title proven; version unresolved | dashboard uses published `sfwd-courses` titles; exact `post_modified_gmt`/record-version rule requires owner authorization |
| progress/completion | Proven semantic candidates | dashboard uses `learndash_course_status`, `learndash_course_progress`, `learndash_course_completed`; LearnDash source proves `_sfwd-course_progress` and course activity semantics |
| lesson/topic completion | Proven logical source; exact requirement unresolved | LearnDash `user_activity` logical table supports `lesson` and `topic`; snapshot v1 has no lesson/topic rows, so exclude unless needed to derive exact course progress |
| quiz attempts | Proven logical source; field mapping partly unresolved | `user_activity`/`user_activity_meta` support `quiz` and meta keys including `score`, `percentage`, and `passed`; exact attempt-to-course/version selection remains owner-gated |
| completion timestamp | Proven authoritative candidates | `course_completed_{course_id}` and logical course activity `activity_completed`; disagreement rule is unresolved and must fail closed rather than choose silently |
| start timestamp | Proven candidate | logical course activity `activity_started`; absence maps to `null` only under an approved completeness rule |
| time spent | Derived deterministic value, source choice unresolved | candidate is nonnegative `activity_completed - activity_started`; dashboard timer meta is discovery-only and must not override without owner approval |
| audit category/order/credits | Proven plugin-policy source | option `ghca_acd_audit_mapping` fields `odp_category`, `oltl_category`, `credit_hours`, `sort_order`, `is_orientation`; normalize credits to integer minutes and reject non-quarter-hour values |
| course lifespan policy | Proven plugin-policy source | options `ghca_acd_course_lifespans` and `ghca_acd_warning_days` |
| annual-cycle policy | Proven plugin-policy source | option `ghca_acd_annual_cycle`; the event-bound resolved cycle remains authoritative |
| new-hire deadline policy | Proven plugin-policy source | option `ghca_new_hire_deadline_days` |
| course certificate assignment | Proven semantic source; physical setting unresolved | LearnDash uses course setting `certificate`; dashboard `_ld_certificate` check is inconsistent discovery evidence and is not authoritative |
| certificate eligibility reference | Proven semantic candidate | LearnDash link logic requires a valid certificate setting and completed course; P3B2a retains no URL/nonce and only the E07 bounded reference |
| certificate bytes/digest/artifact | Excluded/deferred | no acquisition or rendering contract is approved |
| WordPress/LearnDash/plugin versions | Proven runtime facts; physical source unresolved | normalized as bounded version strings after the current-site/runtime gate |
| source record IDs/versions | Unresolved owner decision | exact logical IDs are proposed in E07; exact physical version fields must be authorized before a real adapter |
| legacy UI cache/transient values | Excluded | caches, formatted labels, URLs, and dashboard aggregate rows are not evidence authority |
| legacy fallback “completion time = now” | Excluded | nondeterministic and contradicts immutable evidence |
| free-form filters or plugin hooks | Excluded | mutable runtime filters are outside the consistent source contract |

The later current-site authorization must turn every “physical read unresolved” or “owner decision” entry into an exact table/column/meta query and precedence rule. Until then, only fake-source tests may exercise the interface.

**Rejected alternatives.**

- Copying `GHCA_ACD_Data_Provider` output into a snapshot: rejected because it includes cache/UI/fallback behavior.
- Hashing serialized WordPress option values or LearnDash objects: rejected.
- Guessing table names from conventional prefixes: rejected.

**Reasoning and risks.** Local code proves several keys and semantic APIs but also exposes incompatible fallbacks. Explicitly retaining unresolved mappings prevents accidental promotion of UI behavior into compliance evidence.

**Frozen implementation effect.** A later real adapter cannot be added until every unresolved physical mapping is approved. Fake adapters must conform to E07.

**Retained-data or compatibility effect.** Approved mappings affect retained snapshot bytes and source fingerprints. Changing precedence, record version, course-set, or timer rules later is a retained-data compatibility change.

**Exact owner-approval wording.** `Approve E05 as recommended, with every mapping marked unresolved remaining an implementation-blocking owner gate.`

### E06 - Later source-adapter consistent-read model

**Question.** What transaction contract is frozen for a later authorized physical source adapter?

**Recommended decision.** Freeze the following contract for the later source-adapter slice only. P3B2a neither implements it nor claims that fake-source tests prove it.

1. Use one injected, dedicated database connection for the complete read; never global `$wpdb`.
2. Verify the connection is not the archive persistence connection and is configured read-only.
3. Execute `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ`.
4. Execute `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY`.
5. Read only the E05-authorized source in a fixed query order: engine/version preflight; authoritative request-bound policy facts; user identity/scope; program/course membership; course records; enrollment/access; course activity; lesson/topic facts if required; quiz activity/meta; certificate assignment/reference; post-read counts.
6. Use deterministic `ORDER BY` clauses on every multi-row query and normalize independently of returned order.
7. Use no locking reads, `FOR UPDATE`, `LOCK IN SHARE MODE`, advisory locks, writes, temp-table writes, or cache mutations.
8. If any required table is missing, view-backed, or not transactional InnoDB, roll back and raise operational-blocked `archive_source_schema_unsupported`.
9. Permit at most 32 SQL statements and a 2,000 monotonic-millisecond elapsed budget from successful transaction start through close.
10. On a complete successful read, issue `COMMIT` for the read-only transaction and close/release the injected connection.
11. On every exception, limit breach, timeout, cancellation, or invalid result after transaction start, issue `ROLLBACK`; always close/release in a finally-equivalent path. A failed `COMMIT` is a transaction failure, not success.
12. Perform no canonical JSON encoding, snapshot hashing, filesystem operation, network call, certificate work, rendering, heartbeat callback, lifecycle command, or event commit inside the read transaction.

Concurrent-change rules:

- a change committed before the consistent snapshot is established is visible and can cause reviewed/captured drift;
- a change committed after the consistent snapshot is established is invisible to every source query in that transaction, so no torn document is permitted;
- a change after the source snapshot may be detected only by later verification/finalization; P3B2a must not perform a second unfenced source read and silently mix it into the snapshot.

The 2,000-millisecond rule is an elapsed-budget check, not a guarantee that an in-flight vendor query is forcibly cancelled at the deadline. Query cancellation, statement timeouts, or connection timeouts require a separately approved mechanism proven on every supported MySQL/MariaDB vendor/version before they may be claimed as a hard timeout.

**Rejected alternatives.**

- Default connection isolation: rejected because it is environment-dependent.
- Locking source rows: rejected because capture is observational and must not block LMS writes.
- Multiple WordPress API calls across independent connections: rejected because they cannot prove a common snapshot.

**Reasoning and risks.** Repeatable read plus a consistent snapshot is the intended portable contract, but only a real adapter exercised against each supported vendor can prove transaction, cleanup, and elapsed-budget behavior. Fake transaction tests cannot.

**Frozen implementation effect.** The later source adapter must own transaction control and cleanup and cannot invoke WordPress functions that open independent queries/connections. Physical queries, transaction implementation, elapsed-budget enforcement, any separately approved hard timeout, and source-specific database tests are excluded from P3B2a.

**Retained-data or compatibility effect.** Operational policy only unless query ordering or included sources changes the normalized document; such a source-contract change requires a new decision.

**Exact owner-approval wording.** `Approve E06 as the frozen later-source-adapter contract only, including one read-only REPEATABLE READ consistent snapshot, 32-query/2-second elapsed limits, and unconditional rollback/closure on failure; P3B2a implements none of it.`

### E07 - Normalized source document

**Question.** What exact transient document is fingerprinted and mapped to snapshot v1?

**Recommended decision.** Freeze `normalized-evidence-document-v1`. Top-level keys are exact and lexicographically ordered:

`calculated`, `canonical_format`, `case`, `completeness`, `courses`, `cycle`, `organization`, `policy`, `schema_version`, `source`, `subject`.

No unknown key is accepted at any level.

#### E07.1 Common representation rules

- `schema_version` is integer `1`; `canonical_format` is literal `ghca-cjson-1`.
- IDs that originate as SQL unsigned integers are shortest-form base-10 strings: `0` or `[1-9][0-9]*`; no sign, decimal point, exponent, whitespace, or leading zero.
- Archive identifiers are 32 lowercase hexadecimal characters. Digests are 64 lowercase hexadecimal characters.
- Counts, ordinals, policy versions, and basis points are JSON integers within their named ranges. Durations and credit minutes are unsigned decimal strings.
- Timestamps are UTC `YYYY-MM-DDTHH:MM:SSZ`, second precision, with no fractional seconds. Nullable timestamps use JSON `null`.
- Text is valid UTF-8, NFC-normalized, has CRLF/CR normalized to LF, leading/trailing Unicode whitespace removed, HTML entities decoded once as UTF-8, then tags/control characters rejected. Internal whitespace is preserved; display text is never case-folded.
- Machine keys are lowercase ASCII `[a-z0-9][a-z0-9_-]*` and bounded by the existing snapshot limits.
- An empty collection whose contract is a list is `[]`; an empty keyed document is `{}`. PHP array ambiguity must be resolved before canonical encoding.
- Sets are deduplicated before validation and sorted by unsigned numeric order for decimal IDs or bytewise ASCII order for machine keys. A duplicate carrying contradictory values is an error, never last-write-wins.
- The existing canonical JSON encoder supplies object-key ordering and encoding. No PHP serialization, floats, locale formatting, or second canonicalizer is permitted.

#### E07.2 Exact sections

`case` has exactly:

- `cycle_key`, `program_key`: nonempty bounded machine/text keys copied from the event-bound Archive Case;
- `employee_user_id`, `site_id`: positive unsigned decimal strings;
- `tenant_id`: 32-character lowercase identifier.

`cycle` is byte-identical to the request event’s existing resolved-cycle document and has exactly:

- `boundary`: literal `[)`;
- `display_label`: bounded normalized text;
- `end_gmt`, `start_gmt`: UTC timestamps with start before end;
- `key`, `policy_key`: bounded keys;
- `policy_version`: positive integer;
- `timezone`: canonical IANA timezone identifier.

`organization` has exactly `agency_name`, `site_name`, and `tenant_id`. Names are required normalized text; `tenant_id` must equal `case.tenant_id`.

`subject` has exactly:

- `display_name`: required normalized text, maximum 255 bytes;
- `email`: required valid normalized email, maximum 254 bytes;
- `employee_user_id`: must equal `case.employee_user_id`;
- `external_employee_key`: `null` until an exact source is separately approved, otherwise bounded normalized text;
- `group_ids`: sorted unique list of positive unsigned decimal strings;
- `registered_at_gmt`: nullable UTC timestamp;
- `role_keys`: sorted unique list of machine keys.

`policy` has exactly the existing snapshot policy keys:

- `audit_mapping`: object keyed by every `tracked_course_ids` value. Each value has exactly `category_order` and `course_order` nonnegative integers, `credit_minutes` unsigned decimal string, `is_orientation` boolean, and nullable machine keys `odp_category_key`, `oltl_category_key`;
- `completeness_policy`: literal `snapshot_v1_complete`;
- `course_lifespan_rules`: object keyed by tracked course ID; each value has exactly unsigned decimal strings `lifespan_days` and `warning_days`;
- `policy_digest`: the authoritative request digest, which must also equal the digest of the separately approved canonical policy constituent used by the request path;
- `quiz_policy`: exactly `attempt_selection` = `latest_completed`, `required` boolean, and `score_scale` = `basis_points`;
- `relevant_settings`: exactly `annual_cycle` (`calendar_year` or `employee_start_date`), `new_hire_deadline_days`, and `warning_days` as unsigned decimal strings;
- `tracked_course_ids`: nonempty, sorted, unique positive unsigned decimal list.

Any policy option not represented above is excluded, not placed in an open metadata bag.

`courses` is a list with exactly one row for every tracked course ID, including not-enrolled/not-started rows. Rows are strictly ordered by `(category_order, course_order, unsigned course_id)` and have exactly:

- `category_order`, `course_order`: nonnegative integers copied from normalized policy;
- `certificate_reference`: `null`, or an object with exactly `certificate_post_id` as a positive unsigned decimal string and `source_record_version` as a bounded version string; no URL, nonce, path, HTML, or bytes;
- `certificate_required`: boolean derived from the approved certificate-assignment rule;
- `completed_at_gmt`, `started_at_gmt`: nullable UTC timestamps within the resolved cycle;
- `completion_status`: `not_started`, `in_progress`, or `completed`;
- `course_id`: positive unsigned decimal string;
- `course_stable_key`: nullable bounded normalized text;
- `course_title`: required normalized text, maximum 255 bytes;
- `enrollment_status`: `enrolled` or `not_enrolled`;
- `pass_state`: `passed`, `failed`, `not_applicable`, or `unknown`;
- `quiz_attempts`: ordered list of exact objects `attempt_ordinal`, `attempted_at_gmt`, `passed`, `score_basis_points`; ordinal is zero-based and equals list position, timestamp is within cycle, score is integer 0-10000;
- `quiz_score_basis_points`: nullable integer 0-10000 selected by `quiz_policy`;
- `source_provenance`: exact `adapter_key`, `record_id`, `record_version`;
- `time_spent_seconds`: unsigned decimal string.

The course timeline invariants are the existing snapshot invariants: completed iff completion timestamp is non-null; not-started requires null start and zero time; start cannot follow completion. `certificate_reference` must be non-null iff `certificate_required` is true. P3B2a still cannot commit such a course until certificate acquisition supplies the existing artifact binding, so E13 blocks it before snapshot submission.

`source` has exactly:

- `learndash_version`, `plugin_version`, `wordpress_version`: bounded normalized version strings;
- `source_adapter_key`: literal `learndash-local`;
- `source_adapter_version`: literal `1.0.0`;
- `source_record_ids`: exact object containing sorted unique decimal lists `course_activity_ids`, `course_post_ids`, `group_ids`, `quiz_activity_ids`, plus `user_id` equal to `case.employee_user_id`.

No raw row, meta value, query, table name, or unbounded metadata is retained.

`completeness` has exactly the existing fields:

- `missing_fields`: exact empty list;
- `observed_count`: integer equal to `count(courses)`;
- `policy_code`: literal `snapshot_v1_complete`;
- `policy_version`: integer `1`;
- `required_count`: integer equal to `count(policy.tracked_course_ids)`;
- `result`: literal `complete`;
- `warnings`: sorted unique list of approved machine reason codes, never free text.

Missing/contradictory facts cannot produce this section and therefore fail with `archive_evidence_incomplete`; there is no partial snapshot.

`calculated` has exactly the existing fields:

- `calculation_version`: integer `1`;
- `categories`: object keyed by nonempty normalized ODP category key; each value has exactly sorted `completed_course_ids` and unsigned-decimal `credit_minutes`;
- `compliance_status`: `compliant` only when all policy-required course/pass/lifespan conditions are met, otherwise `non_compliant`; P3B2a does not emit `incomplete` because incomplete source cannot be committed;
- `exceptions`: sorted unique list of approved machine reason codes, never free text;
- `matrix`: exact object with keys `odp` and `oltl`; each value is an object keyed by normalized category key with boolean compliance values;
- `total_course_count`: integer equal to course count;
- `total_training_seconds`: exact arbitrary-precision unsigned-decimal sum of course durations.

Credit hours from the discovered option are accepted only when their normalized decimal value is a nonnegative quarter-hour increment; multiply exactly by 60 to `credit_minutes`. Floats never enter the document. The detailed category/compliance policy is retained-data-sensitive and must be covered by literal fixtures before a real source is authorized.

**Rejected alternatives.**

- Fingerprinting only IDs and a policy digest: rejected because reviewed/captured comparison would miss mutable retained evidence.
- Reusing formatted dashboard rows: rejected because they contain labels, caches, URLs, wall-clock fallbacks, and locale output.
- Allowing open metadata objects: rejected because they defeat boundedness and minimization.

**Reasoning and risks.** The document contains every fact that will be retained or used to calculate the retained snapshot, while excluding transport/runtime material.

**Frozen implementation effect.** A later source validator and snapshot preparer must enforce this exact grammar before any Unit of Work call.

**Retained-data or compatibility effect.** Material. Any field, normalization, ordering, calculation, or source-precedence change alters fingerprints and snapshot bytes and requires a versioned owner decision.

**Exact owner-approval wording.** `Approve E07 as recommended, including the complete normalized-evidence-document-v1 grammar and retained-data compatibility boundary.`

### E08 - Source fingerprint

**Question.** What exact bytes define the reviewed/captured source fingerprint?

**Recommended decision.**

- Domain/version literal: `ghca-source-fingerprint-v1`.
- Constituent document: the complete, validated E07 document, including direct identifiers required by snapshot v1, calculated/completeness sections, policy facts, source versions, and source record identities. Nothing else is added or omitted.
- Formula:

`lowerhex(SHA-256(UTF-8("ghca-source-fingerprint-v1\n" + canonical_json(E07_document))))`.

- Algorithm: SHA-256, producing exactly 64 lowercase hexadecimal characters.
- Object keys use the accepted canonical JSON ordering; ordered course/attempt lists retain E07 order.

Independent literal vector:

```text
{"calculated":{"calculation_version":1,"categories":{"individual_rights":{"completed_course_ids":["101"],"credit_minutes":"60"}},"compliance_status":"compliant","exceptions":[],"matrix":{"odp":{"individual_rights":true},"oltl":{"general":true}},"total_course_count":1,"total_training_seconds":"3600"},"canonical_format":"ghca-cjson-1","case":{"cycle_key":"2026","employee_user_id":"42","program_key":"annual_training","site_id":"1","tenant_id":"11111111111111111111111111111111"},"completeness":{"missing_fields":[],"observed_count":1,"policy_code":"snapshot_v1_complete","policy_version":1,"required_count":1,"result":"complete","warnings":[]},"courses":[{"category_order":0,"certificate_reference":null,"certificate_required":false,"completed_at_gmt":"2026-06-30T15:00:00Z","completion_status":"completed","course_id":"101","course_order":0,"course_stable_key":null,"course_title":"Safety & Rights","enrollment_status":"enrolled","pass_state":"not_applicable","quiz_attempts":[],"quiz_score_basis_points":null,"source_provenance":{"adapter_key":"learndash-local","record_id":"7001","record_version":"9001"},"started_at_gmt":"2026-06-30T14:00:00Z","time_spent_seconds":"3600"}],"cycle":{"boundary":"[)","display_label":"2026","end_gmt":"2027-01-01T00:00:00Z","key":"2026","policy_key":"calendar_year","policy_version":1,"start_gmt":"2026-01-01T00:00:00Z","timezone":"UTC"},"organization":{"agency_name":"Gridhouse Example","site_name":"Academy Example","tenant_id":"11111111111111111111111111111111"},"policy":{"audit_mapping":{"101":{"category_order":0,"course_order":0,"credit_minutes":"60","is_orientation":false,"odp_category_key":"individual_rights","oltl_category_key":"general"}},"completeness_policy":"snapshot_v1_complete","course_lifespan_rules":{"101":{"lifespan_days":"365","warning_days":"90"}},"policy_digest":"2222222222222222222222222222222222222222222222222222222222222222","quiz_policy":{"attempt_selection":"latest_completed","required":false,"score_scale":"basis_points"},"relevant_settings":{"annual_cycle":"calendar_year","new_hire_deadline_days":"30","warning_days":"90"},"tracked_course_ids":["101"]},"schema_version":1,"source":{"learndash_version":"5.0.0","plugin_version":"1.0.0","source_adapter_key":"learndash-local","source_adapter_version":"1.0.0","source_record_ids":{"course_activity_ids":["7001"],"course_post_ids":["101"],"group_ids":["9"],"quiz_activity_ids":[],"user_id":"42"},"wordpress_version":"6.8.0"},"subject":{"display_name":"Ada Example","email":"ada@example.test","employee_user_id":"42","external_employee_key":null,"group_ids":["9"],"registered_at_gmt":"2025-01-02T03:04:05Z","role_keys":["subscriber"]}}
```

The canonical document is exactly 2,653 UTF-8 bytes. The domain line plus document is exactly 2,680 bytes. Frozen SHA-256:

`a281faf9f44869ba7f48ca2ccad4cc4b1bf17f7cbe5f2a977f3771541ee1fc06`

Future tests must construct the semantic document independently, assert its canonical bytes equal this literal, hash the literal without the production helper, then assert the existing production digester matches on PHP 8.3.30 and 8.5.7.

**Rejected alternatives.**

- Raw SQL rows, query order, PHP serialization, objects, or database encodings: rejected as nonportable.
- A new domain literal or digest helper: rejected because the existing accepted domain already exists.
- Excluding required names/email from the fingerprint: rejected because snapshot v1 retains them; an unreviewed change would otherwise be silently sealed.

**Reasoning and risks.** Fingerprinting the exact prepared evidence detects any change that would alter retained evidence while remaining independent of database representation.

**Frozen implementation effect.** No digest or canonicalizer production change is permitted. Tests add the independent vector.

**Retained-data or compatibility effect.** Material. Changing the E07 constituent changes all new source fingerprints and requires a versioned migration/compatibility decision.

**Exact owner-approval wording.** `Approve E08 as recommended, including the exact constituent, formula, 2,653-byte literal, and SHA-256 a281faf9f44869ba7f48ca2ccad4cc4b1bf17f7cbe5f2a977f3771541ee1fc06.`

### E09 - Reviewed-versus-captured fingerprint binding

**Question.** How does capture bind to the reviewed source without silently recapturing drift?

**Recommended decision.**

1. Reload the allowed request event from the authoritative stream.
2. Take `reviewed_source_fingerprint` only from that event payload; the task/source cannot override it.
3. With a later authorized real adapter, complete E06; in P3B2a, accept only a fake-produced document. In both cases validate E07 and compute E08.
4. Compare with constant-time `hash_equals`.
5. If unequal, do not construct/insert a snapshot. Submit or replay the existing `DetectSourceDrift` command after a live-fence check. Its exact caller facts are `archive_id` from the request, `changed_component_codes = ["source_fingerprint"]`, `detection_point = "pre_capture"`, and `observed_source_fingerprint` from E08. Its exact server facts are a deterministic incident ID derived from task ID, `expected_source_fingerprint` from the request, `snapshot_id = null`, `invalidations = []`, and an `ArchiveFailed` payload with this task's build attempt, empty candidate artifact IDs, `failure_code = "archive_source_drift"`, `phase = "capturing"`, `retryable = false`, and `sealed_snapshot_id = null`. The command atomically emits the existing `SourceDriftDetected` followed by `ArchiveFailed`.
6. Never replace the reviewed fingerprint, create a new request, or silently accept the captured value.
7. Before any drift/failure command, first recover a matching successful `EvidenceSnapshotCaptured` event/receipt/snapshot; success always wins over a later failure attempt.

The request event proves where the reviewed digest is stored, not how it was produced. Production activation is therefore blocked until the review-time fingerprint producer and capture-time source adapter are proven to use the same exact approved:

- E05 physical column/key/query mapping and precedence rules implemented by the same adapter key/version;
- E06 source-read contract implementation;
- E07 normalization contract literal `normalized-evidence-document-v1`;
- E08 fingerprint domain literal `ghca-source-fingerprint-v1`;
- E07 source descriptor literals `source_adapter_key = "learndash-local"` and `source_adapter_version = "1.0.0"`.

This proof is an activation/composition prerequisite, not a caller assertion and not a field inferred from the request digest. A fake or task payload cannot satisfy it. P3B2a adds no activation mechanism; `P3B2A-REVIEWED-FINGERPRINT-PROVENANCE-GATED` must prove that the dark slice remains non-activatable and that no capture proceeds through a production composition lacking this exact provenance equivalence. The later source-adapter/activation authorization must define and test the concrete producer descriptor and equality check.

Response loss is resolved by receipt-first lookup in the command’s exact idempotency scope, followed by matching retained history/snapshot recheck. A replayed committed response is authoritative.

**Rejected alternatives.**

- Updating the request fingerprint during capture: rejected because it removes human review binding.
- Retry-looping drift: rejected because stable drift requires a new reviewed request/rebase decision, not transient retry.

**Reasoning and risks.** Reviewed and captured values represent different times but must describe identical normalized evidence produced under one mapping/normalization/adapter contract. Digest equality alone cannot prove that provenance. Mismatch is a business fact, not an adapter convenience.

**Frozen implementation effect.** Drift comparison occurs before `RecordEvidenceSnapshot`; handler side effects remain zero on mismatch. Dark P3B2a remains unregistered, and later production composition must fail closed until the reviewed-fingerprint provenance tuple is proven identical.

**Retained-data or compatibility effect.** Uses existing events only. It may append the already-approved drift/failure outcome but never a snapshot for mismatched evidence.

**Exact owner-approval wording.** `Approve E09 as recommended: exact mismatch is source drift, successful retained capture is recovered before failure, and activation remains blocked until review-time and capture-time mapping, normalization, fingerprint, and adapter provenance are proven identical.`

### E10 - Snapshot construction

**Question.** How is E07 mapped into the existing immutable snapshot without changing its schema?

**Recommended decision.**

The prepared snapshot has exactly the existing 13 top-level sections. Mapping is:

| Snapshot field/section | Exact source |
|---|---|
| `schema_version`, `canonical_format` | E07 values |
| `captured_at_gmt` | coordinator clock captured once after the source operation returns and E07 validates, then reused on every retry; with a later real adapter this is necessarily after successful source COMMIT |
| `case.tenant_id/site_id/employee_user_id/program_key/cycle_key` | E07 case, revalidated against Archive Case |
| `case.archive_id/stream_id/revision_number` | authoritative request event/stream |
| `case.snapshot_id` | `first32(SHA-256("ghca-p3b2a-capture-id-v1|Snapshot|" + task_id))` |
| `case.case_key_digest` | existing Archive Case key digest |
| `cycle`, `organization`, `policy`, `subject`, `calculated`, `completeness` | E07 byte-equivalent sections |
| `review.request_event_id/requested_at_gmt/reviewed_source_fingerprint/subject_scope_digest` | authoritative request event/envelope |
| `review.actor_user_id/initiating_user_id/authority_code` | authoritative request event actor/envelope; nullable fields remain null where event says null |
| `source.learndash_version/plugin_version/source_adapter_key/source_adapter_version/source_record_ids/wordpress_version` | E07 source |
| `source.source_fingerprint_version` | integer `1` |
| `source.reviewed_source_fingerprint` | request event |
| `source.captured_source_fingerprint` | E08 result |
| `source.evidence_assets` | exact empty list in P3B2a |
| each course except certificate fields | E07 course values |
| `course.certificate_artifact_id` | `null`; this is valid only when `certificate_required` is false |

The coordinator derives a stable initial build attempt ID:

`first32(SHA-256("ghca-p3b2a-capture-id-v1|BuildAttempt|" + task_id))`.

It submits/replays `StartBuild` with phase `capturing`, retry ordinal `0`, and `snapshot_id = null`. After source validation it builds snapshot bytes once, computes the existing `ghca-snapshot-v1` digest and byte count, and submits `RecordEvidenceSnapshot` with the existing event payload. Certificate ID/digest lists are exact empty lists.

The `RecordEvidenceSnapshot` Unit of Work transaction atomically inserts the snapshot and event under the live task fence. Its causation is the request trigger event. The `ArchiveBuildStarted` causation is also that trigger event. Both commands use deterministic command/correlation IDs derived from purpose plus task ID.

On replay:

- a retained snapshot with the same snapshot ID, archive, stream/revision identity, canonical bytes, digest, byte count, source event, and event payload is success and returns the stored response;
- any same identity with different bytes, digest, binding, or event is `archive_immutable_conflict`, blocks completion, and permits no replacement/delete/update;
- a snapshot with certificate-required rows cannot be built in P3B2a and is handled by E13.

If existing snapshot v1 cannot represent a required approved fact, implementation stops for a new owner decision; it must not add an open field.

**Rejected alternatives.**

- Random snapshot/build IDs generated on every attempt: rejected because crash recovery would diverge.
- Inserting snapshot and event in separate transactions: rejected.
- Replacing a contradictory retained snapshot: rejected because it is immutable evidence.

**Reasoning and risks.** Deterministic IDs plus the existing atomic side-record contract allow crash-safe replay without schema changes.

**Frozen implementation effect.** Snapshot construction and validation finish before any Unit of Work call. The coordinator, not the source, supplies archive/event/actor/time identities.

**Retained-data or compatibility effect.** Successful snapshots are permanent. The deterministic ID formula and mapping are retained-data contracts.

**Exact owner-approval wording.** `Approve E10 as recommended, including deterministic build/snapshot IDs, exact snapshot-v1 mapping, atomic command binding, and immutable conflict handling.`

### E11 - Ceilings and failure behavior

**Question.** What limits prevent unbounded capture and what happens when any limit is exceeded?

**Recommended decision.**

- maximum source rows read/considered: 10,000;
- maximum canonical values in the complete E07 document: 10,000;
- maximum canonical values in the complete prepared snapshot document: the existing canonical limit of 10,000, applied independently from the E07 count;
- maximum evidence assets: 10,000, although P3B2a requires exactly zero;
- maximum canonical snapshot bytes: 1,048,576;
- existing canonical depth and per-string byte limits remain unchanged;
- E06 additionally limits queries and transaction time.

The E07 and snapshot value ceilings are two independent validations. Values that appear in both documents are counted in each document's own traversal; there is no combined counter or shared 10,000-value allowance. In the later source-adapter slice, bounded count queries must reject a known over-limit result before detail queries and every detail query uses `LIMIT ceiling + 1` where portable. P3B2a validates the fake-produced E07 document and then the separately prepared snapshot document, including list members, canonical values, nesting depth, string bytes, evidence assets, and final canonical snapshot bytes.

There is no truncation, pagination, partial snapshot, warning-only overflow, or “first 10,000” success. Every overflow rolls back the source read if open, leaves no snapshot/event/receipt residue, and yields:

- build failure code: `archive_evidence_incomplete`;
- sanitized task code: `task_handler_failed` before attempt five, then authoritative closed failure if recovery finds no success;
- safe text: `The required archive evidence is incomplete or exceeds its approved limit.`;
- class: permanent when the deterministic normalized source exceeds a ceiling; retryable only for a proven transient query/transaction failure; operational-blocked for unsupported source schema/engine.

**Rejected alternatives.**

- Truncation: rejected because it produces false completeness.
- Increasing limits in code without owner approval: rejected because it changes resource and retained-data policy.

**Reasoning and risks.** Independent E07 and snapshot checks preserve the existing canonical limit without an ambiguous combined count. The later adapter's pre-query checks cover database fan-out; P3B2a's two document validations cover canonical expansion.

**Frozen implementation effect.** Each negative regression asserts the exact exception/reason and zero unintended database/filesystem residue.

**Retained-data or compatibility effect.** Limits are operational policy, but increasing them can enable previously rejected retained snapshots and therefore requires an explicit decision.

**Exact owner-approval wording.** `Approve E11 as recommended, preserving all ceilings with no truncation, partial success, or silent limit increase.`

### E12 - Data minimization and prohibited data

**Question.** What data may and may not cross the evidence boundary?

**Recommended decision.**

Allow only the exact E07 fields. Direct identity is limited to employee user ID, snapshot-required display name/email, optional separately approved external key, group/role keys, registration time, and organization names. Task payloads, task errors, failure contexts, logs, and command reason text contain none of those direct identifiers.

The contract is enforced mechanically through exact allowlists:

- the later physical source adapter may select only the exact owner-approved tables, columns, meta keys, and option keys needed for E07; no wildcard projection or arbitrary meta/options read is permitted;
- the P3B2a result validator accepts only the exact E07 object keys, shapes, scalar types, literals, and field-specific formats;
- every unknown key is rejected;
- HTML markup, URLs/URL schemes, query strings, absolute or relative filesystem-path forms, control characters, resources, objects, callbacks, and structurally forbidden values are rejected in every field where E07 does not explicitly permit them.

The adapter/validator must not claim to semantically recognize arbitrary PHI, PCI, clinical, payment, or other sensitive prose. Such heuristic classification is not reliable. Prevention comes from never reading an unapproved source column/key and from accepting no free-form field in E07.

The approved source-column/field allowlists exclude:

- WordPress password/password hash, application passwords, salts, reset keys;
- session tokens, cookies, nonces, OAuth/API keys, authorization headers, credentials;
- payment, billing, bank, card, PCI, order, subscription, or transaction data;
- diagnosis, treatment, medication, clinical notes, disability/health details, PHI, or other health data not represented by the archive schema;
- free-form administrator, learner, HR, quiz-answer, essay, comment, support, or audit notes;
- raw HTML, shortcodes, rendered blocks, URLs, query strings, filesystem paths, IP addresses, user-agent strings;
- arbitrary user meta, post meta, options, activity meta, serialized blobs, cache/transient values, debug traces, SQL, or stack traces;
- certificate bytes or externally retrievable links;
- any unknown field.

Course/quiz facts retain only statuses, timestamps, scores, IDs/versions, titles, and policy fields required by E07. Quiz questions and answers are prohibited.

**Rejected alternatives.**

- Capturing all meta and filtering afterward: rejected because prohibited data has already crossed the boundary.
- Removing name/email from snapshot v1 inside P3B2a: rejected because that would be a schema change; a future schema decision may minimize further.

**Reasoning and risks.** Employment/training PII is permitted, but the immutable archive amplifies unnecessary data. Exact projections and a closed field grammar are enforceable; a semantic “PHI/PCI detector” is not.

**Frozen implementation effect.** The closed E07 validator runs before fingerprinting and snapshot construction. An unknown key or mechanically detectable forbidden structure causes `archive_evidence_prohibited`. P3B2a adds no recursive semantic-content classifier. The later adapter must implement exact source projections before data crosses the interface.

**Retained-data or compatibility effect.** The allowlist defines retained evidence. Expanding it is a retained-data/privacy change requiring owner approval.

**Exact owner-approval wording.** `Approve E12 as recommended, including exact source-column and E07 field allowlists, mechanical structural rejection before fingerprinting, and no heuristic semantic PHI/PCI classifier.`

### E13 - Certificate boundary

**Question.** What certificate information may P3B2a retain?

**Recommended decision.** P3B2a may transiently retain only:

- `certificate_required`;
- nullable `certificate_reference.certificate_post_id`;
- nullable `certificate_reference.source_record_version`.

It may use these facts only to prove whether the existing snapshot requires a certificate artifact. It may not retain or use certificate URL, nonce, cookie, HTML, template, generator selection, credentials, filesystem path, bytes, PDF, content digest, artifact descriptor, or public/private download address.

Because existing snapshot v1 requires a real immutable artifact ID for every `certificate_required = true` course, P3B2a succeeds only when every course has `certificate_required = false`. If any is true, it fails closed with permanent `archive_certificate_invalid` and safe message `Required certificate evidence is not available in this slice.` It creates no snapshot and performs no network/filesystem work.

Certificate HTTP retrieval, rendering, credential forwarding, media validation, storage, and generator policy remain a separate owner-approved slice.

**Rejected alternatives.**

- Fabricating a descriptor or zero-byte placeholder: rejected because it violates snapshot/artifact binding.
- Storing a LearnDash certificate URL for later: rejected because it can contain nonces, identities, and runtime routing.
- Treating certificate assignment as optional evidence: rejected where the approved policy says it is required.

**Reasoning and risks.** The current immutable snapshot cannot honestly represent a required but unacquired certificate.

**Frozen implementation effect.** The handler blocks before Unit of Work submission when a required certificate is observed.

**Retained-data or compatibility effect.** No certificate data is retained by P3B2a. Later certificate support must produce the existing descriptor/evidence-asset contract or obtain a separate schema decision.

**Exact owner-approval wording.** `Approve E13 as recommended: P3B2a retains only bounded eligibility/reference facts and fails closed when a certificate artifact is required.`

### E14 - Failure classification

**Question.** What exact category/reason/context mapping controls retry, lifecycle decisions, and attempt five?

**Recommended decision.** Classification uses exception class/category plus operation context only. Message text and reason prefixes never classify.

| Source/category | Exact reason | Context | Disposition | Sanitized task code | Lifecycle event permitted |
|---|---|---|---|---|---|
| task validation / invalid | `task_payload_invalid` | `task_validation` | permanent | `task_payload_invalid` | none |
| build binding / invalid | `archive_build_binding_invalid` | `authoritative_load` or `command_prepare` | permanent | `task_handler_failed` | `ArchiveFailed` only after success recovery; phase `requested` before build or `capturing` after build |
| source validation / invalid | `archive_snapshot_invalid` | `source_validate` or `snapshot_prepare` | permanent | `task_handler_failed` | closed `ArchiveFailed` after recovery |
| prohibited source / invalid | `archive_evidence_prohibited` | `source_validate` | permanent | `task_handler_failed` | `ArchiveFailed` using existing `archive_evidence_incomplete` event failure code unless separately added to the accepted build-code list |
| source drift / invalid | `archive_source_drift` | `fingerprint_compare` | permanent | `task_outcome_commit_failed` if command fails | exact E09 `DetectSourceDrift` atomic decision only |
| read / retryable | `archive_source_read_failed` | `transaction_start`, `source_query`, or `transaction_commit` | retryable | `task_handler_failed` | none before attempt five; existing `archive_build_attempts_exhausted` only after final deterministic recovery fails and no matching outcome exists |
| transaction cleanup / operational-blocked | `archive_source_transaction_failed` | `transaction_rollback` or `connection_close` | operational retry/dead-letter only | `task_handler_failed` | none at every attempt |
| query / retryable | `archive_source_query_failed` | `source_query` | retryable | `task_handler_failed` | same exact final-recovery rule as read failure |
| limit / invalid | `archive_evidence_incomplete` | `pre_query_limit`, `normalize_limit`, or `snapshot_byte_limit` | permanent | `task_handler_failed` | closed `ArchiveFailed` after recovery |
| source schema / operational-blocked | `archive_source_schema_unsupported` | `source_preflight` | operational retry/dead-letter only | `task_handler_failed` | none at every attempt |
| certificate gate / invalid | `archive_certificate_invalid` | `certificate_gate` | permanent | `task_handler_failed` | closed `ArchiveFailed` after recovery |
| retained evidence / integrity | `archive_immutable_conflict` | `authoritative_recovery` or `snapshot_commit` | operational-blocked integrity | `task_outcome_commit_failed` | no additional lifecycle event; manual integrity review |
| unknown Throwable / internal | `task_handler_failed` | exact current phase | operational retry/dead-letter only | `task_handler_failed` | none at every attempt |
| fence / concurrency | existing exact fence reason | any fenced phase | lease lost | no retry/dead mutation by stale worker | none |
| response loss | receipt/history match | `start_build_commit` or `snapshot_commit` | replay success | none | no duplicate event |

Event failure codes remain restricted to the existing `GHCA_ACD_Archive_Build_Coordinator::FAILURE_CODES`. Source exception reasons are not new event payload values. A closed permanent evidence failure may map to the exact existing code shown by this decision; a retryable source read/query may map only to `archive_build_attempts_exhausted` after attempt-five deterministic recovery fails. `archive_source_transaction_failed`, `archive_source_schema_unsupported`, unknown/unclassified throwables, rollback/close failures, lease loss, and other operational-blocked causes never map to an event code and never invoke `FailArchive`.

Attempt-five algorithm:

1. validate task and authoritative binding without source side effects;
2. recover/replay a matching `EvidenceSnapshotCaptured` receipt/event/snapshot first;
3. recover/replay a matching existing failure decision second;
4. if success/failure is already authoritative, complete/dead-letter according to that decision;
5. otherwise make one final deterministic recovery attempt appropriate to the category;
6. if the remaining cause is a closed permanent lifecycle mapping, submit `FailArchive` with that exact existing code only when no matching outcome exists and final recovery fails;
7. if the remaining cause is retryable operational, submit `FailArchive` with existing `archive_build_attempts_exhausted` only when no matching outcome exists and final deterministic recovery fails;
8. if the remaining cause is operational-blocked, unknown, unclassified, rollback/close failure, unsupported source schema, lease loss, response loss without an authoritative decision, task death, or worker death, append no lifecycle event and retry/dead-letter only under the sanitized P3A task rule;
9. never append failure after successful snapshot commit or response loss;
10. if an allowed failure command loses its response, replay its receipt/history before task disposition.

**Rejected alternatives.**

- Parsing messages/prefixes: rejected as unstable and spoofable.
- Dead-lettering before authoritative recovery on attempt five: rejected because response loss can hide success.
- Converting lease expiry to `ArchiveFailed`: rejected by P3A.
- Converting operational-blocked or unclassified task exhaustion into `ArchiveFailed`: rejected by D05 because it invents a lifecycle fact.

**Reasoning and risks.** Closed classification makes retry and lifecycle behavior deterministic and preserves “success wins” under crashes.

**Frozen implementation effect.** Every catch site supplies an explicit operation context and delegates to the closed table.

**Retained-data or compatibility effect.** Task error codes are operational records; lifecycle failure codes/events are retained and must remain within the accepted catalog.

**Exact owner-approval wording.** `Approve E14 as recommended, including receipt-first attempt-five recovery and the prohibition on lifecycle events for operational-blocked, rollback/close, unsupported-schema, unknown, or unclassified failures.`

### E15 - Idempotency, fencing, and recovery

**Question.** How does capture avoid duplicate commands/snapshots and stale-worker outcomes?

**Recommended decision.**

For every claimed task:

1. receipt/history/snapshot recovery precedes source work;
2. validate the live lease before `StartBuild`, before source work, after source work, and immediately before every authoritative Unit of Work call;
3. pass the exact task fence (`task_id`, `lease_owner`, `lease_token`) into every authoritative Unit of Work transaction;
4. compute the E02 task outcome key once;
5. use command-specific idempotency scopes for `StartBuild`, `RecordEvidenceSnapshot`, `DetectSourceDrift`, and `FailArchive`, all carrying the same task outcome key;
6. leave the existing P3B1 `derived_id()` helper and its literal `ghca-p3b1-command-id-v1` byte-for-byte unchanged;
7. add a separate capture-only `capture_derived_id(purpose, task_id)` path whose exact formula is `first32(SHA-256("ghca-p3b2a-capture-id-v1|" + purpose + "|" + task_id))`; it must not call, parameterize, rename, or replace the existing P3B1 `derived_id()` helper, and it has this closed purpose set:
   - `command:StartBuild`, `command:RecordEvidenceSnapshot`, `command:DetectSourceDrift`, and `command:FailArchive`;
   - `correlation:StartBuild`, `correlation:RecordEvidenceSnapshot`, `correlation:DetectSourceDrift`, and `correlation:FailArchive`;
   - `BuildAttempt`, `Snapshot`, and `DriftIncident`;
8. derive the E10 build attempt and snapshot through that capture-only path;
9. replay `StartBuild` receipt/history before starting a new source read;
10. after stream conflict, reload receipt, stream, snapshot, and task fence once; accept only an exact matching success/failure, otherwise preserve the conflict;
11. after `RecordEvidenceSnapshot` commit, synthesize the existing exact completed outcome and only then complete the task;
12. if task completion loses its lease after successful commit, the stale worker reports lease loss; a later owner reclaims and replays success without duplicate event/snapshot;
13. never retry source work or submit failure once a matching capture outcome exists;
14. a contradictory retained row/event is integrity-blocked and is never overwritten.

`P3B2A-P3B1-ID-DOMAINS-UNCHANGED` freezes representative existing P3B1 command, correlation, build-attempt, and artifact identities from independent literal inputs and proves they remain byte-identical after P3B2a is loaded. The test must fail if the existing helper body/domain changes, if P3B1 is routed through the capture-only helper, or if a P3B2a purpose is routed through the P3B1 helper.

**Rejected alternatives.**

- Handler-controlled command submission: rejected; the coordinator owns validation and fencing.
- Completing the task before snapshot command commit: rejected.
- Treating a timeout as proof of command failure: rejected.

**Reasoning and risks.** Receipts and retained history are the authority after ambiguous transport failure. A separate capture-only derivation path avoids silently changing retained P3B1 identities while the task fence prevents a stale lease holder from mutating the stream.

**Frozen implementation effect.** The capture handler returns a bounded prepared result only; coordinator validation finishes before any command commit.

**Retained-data or compatibility effect.** Existing P3B1 identities remain byte-identical. New P3B2a deterministic identity formulas and command scopes become retained idempotency contracts.

**Exact owner-approval wording.** `Approve E15 as recommended, including an isolated capture-ID path, byte-identical preservation of all P3B1 IDs, receipt-first recovery, live fencing, and success-wins replay.`

### E16 - Concurrent-change acceptance cases

**Question.** What exact result is authoritative for each concurrent-change/crash window?

**Recommended decision.**

| Case | Required result |
|---|---|
| source change commits before transaction snapshot starts | new state is read; if fingerprint differs, source drift; no snapshot |
| change commits after transaction starts but before first detail query | consistent snapshot excludes it; every query sees the pre-change version; no tear |
| change commits between two source queries | both queries remain on one snapshot; no mixed old/new document |
| change commits immediately after source COMMIT | captured document may validly represent the earlier point; P3B2a does not reread/mix; later verification is responsible for detecting post-capture drift |
| change commits before `RecordEvidenceSnapshot` command commit | same as prior row; command commits the already-reviewed, consistently read document only if captured fingerprint matched; later verification catches subsequent change |
| worker crashes after `StartBuild` commit | next worker replays the start receipt/event and continues with same build attempt |
| worker crashes after snapshot command commit before task completion | next worker replays receipt/event/snapshot byte-identically and completes; no duplicate |
| response is lost after snapshot commit | receipt/history replay is success; never `ArchiveFailed` |
| two workers contend for one task | task-store fencing gives one live owner; stale worker cannot read-authoritatively-submit success after lease loss |
| lease expires during source transaction | current worker rolls back/closes if detected and submits no command; reclaiming worker uses a new token |
| retained snapshot conflicts with prepared bytes | integrity blocked; no overwrite, completion, failure event, or second snapshot |

`P3B2A-SOURCE-DRIFT-DURING-CAPTURE` must use two barriers: a change committed before consistent snapshot establishment yields drift; a change committed after establishment is excluded and is paired with `P3B2A-CHANGE-AFTER-SNAPSHOT-NO-TEAR`.

**Rejected alternatives.**

- Re-reading after source COMMIT and merging changes: rejected because it tears the evidence point.
- Locking the LMS for the whole workflow: rejected.

**Reasoning and risks.** A consistent snapshot proves internal coherence, not that the live source cannot change afterward. Final verification owns the latter concern.

**Frozen implementation effect.** P3B2a may use fake-source barriers only to prove coordinator ordering and “no command before validated prepared result”; it cannot claim physical consistent-read or no-tear evidence. The later source-adapter slice must use explicit transaction barriers and two real source connections to prove the first five rows and lease-expiry rollback behavior. Existing archive Unit of Work/fencing persistence tests continue to use real disposable connections in P3B2a.

**Retained-data or compatibility effect.** Defines which point-in-time facts may be retained; changing it is a semantic compatibility decision.

**Exact owner-approval wording.** `Approve E16 as recommended, including the exact before/during/after-snapshot and competing-worker outcomes.`

### E17 - Future implementation allowlist

**Question.** Which exact files may a later owner-authorized P3B2a implementation add or modify?

**Recommended decision.**

New production files:

- `includes/archive/contracts/class-archive-evidence-source.php`
- `includes/archive/application/class-archive-evidence-source-exception.php`
- `includes/archive/application/class-archive-evidence-result-validator.php`
- `includes/archive/application/class-archive-evidence-snapshot-preparer.php`
- `includes/archive/application/class-archive-evidence-task-handler.php`

Existing production files permitted to change:

- `includes/archive/application/class-archive-task-catalog.php`
- `includes/archive/application/class-archive-worker-coordinator.php`
- `includes/archive/application/class-archive-build-coordinator.php`

`includes/archive/contracts/class-archive-evidence-source.php` must declare the exact `GHCA_ACD_Archive_Evidence_Source` interface from E03. It is not an abstract class and has no production implementation in P3B2a. No production current-site adapter is allowed under E04; tests inject fakes.

New test files:

- `tests/archive/test-p3b2a-evidence.php`
- `tests/archive/test-p3b2a-evidence-persistence.php`
- `tests/archive/test-p3b2a-evidence-concurrency.php`

Existing test/runner files permitted to change only to register exact P3B2a suites and the one allowed handler:

- `tests/archive/bootstrap.php`
- `tests/archive/persistence-bootstrap.php`
- `tests/archive/test-p3-boundaries.php`
- `tests/archive/test-all.ps1`

New traceability file:

- `docs/superpowers/plans/2026-07-27-dual-layer-archive-slice-1b-p3b2a-traceability.md`

The proposal itself may be revised only by owner direction. No other production, test, schema, migration, entrypoint, hook, controller, cron, activation, packet, verification/finalization, reset, D16, or configuration file is allowed. If implementation needs any file outside this list, it stops for owner approval.

**Rejected alternatives.**

- A production LearnDash adapter now: rejected by E04.
- Modifying event catalog, snapshot store/schema, digester, canonical JSON, Unit of Work task generation, plugin entrypoint, or task-store SQL: rejected because the current contracts suffice for the proposed dark slice.
- A service container/event bus: rejected as unnecessary.

**Reasoning and risks.** The allowlist isolates preparation/validation/coordination and prevents dark tests from becoming production wiring.

**Frozen implementation effect.** Later implementation tooling must enforce the allowlist and report exact deviations.

**Retained-data or compatibility effect.** None by itself.

**Exact owner-approval wording.** `Approve E17 as recommended, including the exact later-phase file allowlist and stop-on-expansion rule.`

### E18 - Verification and acceptance matrix

**Question.** What evidence is required before a later P3B2a implementation can be ready for formal review?

**Recommended decision.**

Run the full accepted archive matrix on:

- PHP 8.3.30 x MySQL 8.0, MySQL 8.4, MariaDB 10.6;
- PHP 8.5.7 x the same three databases.

Use only environment-supplied credentials and disposable databases matching `^ghca_acd_archive_test_[A-Za-z0-9_]+$`. PHP 8.4 is optional and reported unavailable if no CLI exists.

Each applicable cell must include schema, P1, P2, P3A, P3B1, and P3B2a dark coordinator/validation/preparation/UoW suites. Both PHP runtimes also run kernel, legacy, boundary, digest, lint, `git diff --check`, and static scans. Every negative test asserts exact exception class, category/reason, operation context, sanitized code, and zero unintended database/filesystem residue. No P3B2a result may be reported as proof of physical source transaction behavior.

Mandatory named future regressions and exact expectations:

| Test | Exact expected behavior |
|---|---|
| `P3B2A-TASK-PAYLOAD-CLOSED` | only E02 keys/types/order accepted; zero source/UoW calls on failure |
| `P3B2A-TASK-TRIGGER-EXACT` | initial/replacement request accepted; every other trigger rejected before source |
| `P3B2A-LIFECYCLE-RETRY-DEFERRED` | retry-triggered capture produces no source call, handler side effect, lifecycle event, or snapshot |
| `P3B2A-SOURCE-FINGERPRINT-GOLDEN` | independent 2,653-byte literal and frozen SHA match production helper |
| `P3B2A-SOURCE-FINGERPRINT-CROSS-RUNTIME` | identical canonical bytes and digest on PHP 8.3.30/8.5.7 |
| `P3B2A-SOURCE-DRIFT-BEFORE-CAPTURE` | fake source returns a document whose exact E08 digest differs; exact drift and zero snapshot |
| `P3B2A-REVIEWED-FINGERPRINT-PROVENANCE-GATED` | dark slice remains non-activatable; task/fake claims cannot satisfy provenance; later composition must prove exact E05/E06/E07/E08 and adapter-version equality |
| `P3B2A-SNAPSHOT-BINDING-EXACT` | every E10 event/case/attempt/source/digest/byte binding is exact |
| `P3B2A-SNAPSHOT-REPLAY-BYTE-IDENTICAL` | receipt/history replay returns identical snapshot bytes and one event/row |
| `P3B2A-SNAPSHOT-CONTRADICTION-BLOCKED` | contradictory retained evidence raises exact integrity failure and is untouched |
| `P3B2A-ROW-LIMIT-NO-TRUNCATION` | 10,001st row rejects; no partial output/residue |
| `P3B2A-VALUE-LIMIT-NO-TRUNCATION` | E07 value 10,001 and snapshot value 10,001 each reject under independent 10,000-value traversals; no combined counter, partial output, or residue |
| `P3B2A-BYTE-LIMIT-NO-TRUNCATION` | 1,048,577th snapshot byte rejects; no command/residue |
| `P3B2A-PROHIBITED-DATA-REJECTED` | unknown keys and exact HTML, URL, path, control/resource/object/callback structures reject before fingerprint/UoW without echo; test makes no semantic-PHI/PCI-classifier claim |
| `P3B2A-OPERATIONAL-FAILURES-NO-LIFECYCLE` | attempt-five rollback/close, unsupported-schema, unknown, and unclassified failures retry/dead-letter operationally with zero lifecycle command/event |
| `P3B2A-RESPONSE-LOSS-REPLAY` | committed snapshot response loss replays success; no failure/duplicate |
| `P3B2A-TWO-WORKER-ONE-OWNER` | two connections cannot both submit an authoritative outcome |
| `P3B2A-P3B1-ID-DOMAINS-UNCHANGED` | independent literal vectors prove all retained P3B1 IDs remain byte-identical and no capture purpose uses the P3B1 helper |
| `P3B2A-NO-CURRENT-SITE-ACCESS` | no bootstrap, current credentials, globals, current-site query, or WP-CLI access |
| `P3B2A-NO-RUNTIME-WIRING` | no entrypoint/hook/cron/controller/activation/composition additions |
| `P3B2A-CERTIFICATE-ACQUISITION-DEFERRED` | required reference blocks; zero HTTP/filesystem/render/descriptor calls |
| `P3B2A-D16-REMAINS-DEFERRED` | no lifecycle retry implementation or retry task execution |

The following named regressions are reserved for the separately authorized physical source-adapter slice. They are not implemented, executed, or claimed by P3B2a fake-source testing:

| Reserved test | Exact later-source-adapter expectation |
|---|---|
| `P3B2A-CONSISTENT-READ-COMMITS` | exact vendor-tested statements/order and one successful read-only COMMIT |
| `P3B2A-CONSISTENT-READ-ROLLBACK` | every injected physical transaction failure rolls back and closes once |
| `P3B2A-CONSISTENT-READ-NO-EXTERNAL-WORK` | no filesystem/network/render/certificate/UoW work while the physical source transaction is open |
| `P3B2A-SOURCE-DRIFT-DURING-CAPTURE` | a real-connection barrier before snapshot establishment yields drift; no torn read |
| `P3B2A-CHANGE-AFTER-SNAPSHOT-NO-TEAR` | a real change after snapshot establishment is excluded from every captured query |

Additional required evidence:

- invalid prepared result before any Unit of Work call;
- `StartBuild` response loss replay;
- attempt-five crash after snapshot command commit and before task completion;
- stream-conflict reload finds matching success;
- failure response loss replay;
- names/email allowed only in E07/snapshot, absent from tasks/errors/logs;
- network-call, credential, debug-output, schema-change, immutable-update/delete, and forbidden-wiring scans.

The later source-adapter slice must additionally prove non-InnoDB/missing-table operational blocking, physical query/count cleanup, the 2-second elapsed-budget behavior, and any separately approved vendor timeout/cancellation behavior on its supported database matrix.

Formal acceptance remains an owner/reviewer action after the complete matrix; implementation must report “ready for formal re-review, not self-accepted.”

**Rejected alternatives.**

- PHP 8.3-only evidence: rejected because PHP 8.5.7 is a required runtime.
- Presenting fake-source tests as transaction evidence: rejected. P3B2a uses real disposable connections only for existing archive UoW/fencing persistence; the later source-adapter slice must provide real vendor-specific transaction evidence.
- Reusing current-site data: rejected.

**Reasoning and risks.** The P3B2a matrix must prove deterministic dark contracts and real archive UoW/fencing behavior without overstating fake-source evidence. Physical source cleanup, isolation, elapsed budgets, and no-tear behavior require the later adapter matrix.

**Frozen implementation effect.** Traceability maps every decision to code, named tests, runtime/database results, and failure-injection evidence.

**Retained-data or compatibility effect.** None; verification policy only.

**Exact owner-approval wording.** `Approve E18 as recommended, including the 2x3 dark-slice matrix, P3B1 ID/provenance regressions, explicit deferral of physical-source transaction evidence, and reviewer-only formal acceptance.`

## 5. Explicit unresolved owner gates

Even if E01-E18 are approved, the following remain unresolved and block a production source adapter or activation:

1. separate owner authorization for the physical source-adapter slice;
2. exact site-prefixed WordPress/LearnDash source tables, columns, meta keys, and read queries;
3. the read-only database principal and runtime connection source;
4. multisite tenant/site resolution;
5. exact authoritative external employee key, or confirmation it is always `null`;
6. role and hierarchical group physical precedence;
7. program-specific tracked-course selection where audit mapping and new-hire groups differ;
8. direct/open/group course enrollment precedence;
9. completion disagreement handling between progress, completion meta, and activity rows;
10. exact quiz activity-to-course selection and versioning;
11. exact time-spent source/precedence;
12. exact course, policy, and source-record version fields;
13. exact certificate-assignment physical mapping;
14. current-site WordPress/LearnDash supported version range;
15. vendor-tested E06 physical transaction, elapsed-budget, cleanup, and any separately approved hard-timeout mechanism;
16. proof that the review-time producer and capture-time adapter use identical E05/E06/E07/E08 mapping, normalization, fingerprint domain, adapter key, and adapter version;
17. certificate acquisition/storage/rendering;
18. D16 lifecycle retry behavior;
19. production runtime composition, secrets/configuration, scheduling, activation, and rollback.

P3B2a dark implementation may proceed only against injected fakes and disposable databases after E01-E18 approval. It may not resolve these gates by inference.

## 6. Explicit deferrals

The proposal does not authorize:

- current-site access or credentials;
- production WordPress/LearnDash adapter, physical source queries, E06 transaction implementation, source-specific database tests, or configuration loading;
- query cancellation, statement/connection hard timeouts, or claims that the 2-second elapsed budget forcibly aborts an in-flight query;
- certificate acquisition, HTTP, rendering, descriptor creation, or storage;
- ledger changes;
- packet/PDF rendering;
- verification/finalization or live-source verification;
- D16 lifecycle retry;
- reset/reconciliation/projection rebuild;
- runtime wake-up, hooks, WP-Cron, Action Scheduler, WP-CLI registration, REST/admin controllers, entrypoint wiring, activation, or deployment;
- schema, migration, event catalog, snapshot schema/store, canonical JSON, or digest changes;
- staging, committing, pushing, merging, rebasing, or PR creation.

## 7. Owner response

Implementation remains prohibited until the owner gives the following single unambiguous approval:

> Approve P3B2a Decisions E01-E18 as written, including the explicit current-site-access and certificate-acquisition deferrals.
