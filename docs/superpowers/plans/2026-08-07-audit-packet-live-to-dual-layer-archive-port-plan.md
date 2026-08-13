# Dual-Layer Archive Certificate-Free Audit Packet Materialization Decisions Proposal

**Date:** 2026-08-12

**Status:** Formally approved for documentation-only publication; implementation not authorized

**Owner approval date:** 2026-08-13

**Branch:** `feature/dual-layer-archive-slice-1b-audit-packet-port-proposal`

**Parent:** `ee1ef9142c814049755b54c57da2b5da40e774e7`

**Scope:** proposal-only architecture for a constructed-dark, certificate-free `materialize_packet` candidate; no implementation authority

## 1. Purpose, authority, and hard boundary

This record replaces the former live-feature port checklist with owner decisions for the smallest archive packet candidate that can be derived only from retained immutable evidence. The live packet implementation is discovery evidence, not archive authority. Nothing in this record copies or approves its WordPress option reads, LearnDash reads, TCPDF dependency, FPDI composition, temporary job broker, certificate routes, uploads paths, URLs, hooks, filters, user context, or ambient clock.

The formally approved certificate strategy record at the parent commit selects neither Strategy A nor Strategy B. Certificate acquisition, certificate-bearing packets, packet verification/finalization, D16 lifecycle retry, controlled-site testing, scheduling, activation, downloads, and publication remain blocked.

### 1.1 Mandatory preflight evidence

| Check | Result |
|---|---|
| Branch | exact expected audit-packet proposal branch |
| HEAD and ancestry | exact `ee1ef9142c814049755b54c57da2b5da40e774e7`; branch is stacked directly on it |
| Certificate strategy | parent contains formal CS01-CS20 approval, selects neither strategy, and keeps implementation blocked |
| Staged/tracked state | zero staged files; zero tracked modifications before this rewrite |
| Authorized untracked paths | `.claude/` and this proposal only; `.claude/` was not read or modified |
| Runtime boundary | packet and verify handlers remain absent and uninstalled |
| Current site, database, Docker, network, PDF tools | not accessed or executed |

### 1.2 Accepted operating envelope

The retained operating assumptions remain approximately 100 reads per lifecycle write, validation at 100 reads/second and 5 lifecycle writes/second per site, at most five artifact workers per site, one operational tenant per WordPress site, employment/training PII only, 99.9% monthly lifecycle API availability, lifecycle-command p99 at most 1,000 ms excluding asynchronous artifact work, zero primary RPO for acknowledged event commits, disaster-recovery RPO at most 15 minutes, and RTO at most four hours. No packet-render duration SLO is inferred; PM15 keeps it owner-gated.

## 2. Confirmed repository facts and contradictions resolved

1. Snapshot v1 is the only packet evidence authority. It retains exact case, cycle, review, subject, organization, policy, source, ordered course, calculated, and completeness sections. It does not retain course descriptions, logos, uploads paths, URLs, mutable packet options, current roles, dashboard visibility, or packet-generation time.
2. Accepted E13 permits capture success only when every course is certificate-free. A required certificate fails before snapshot submission. Therefore a successful current-slice snapshot has `source.evidence_assets = []`, every `course.certificate_required = false`, and every `course.certificate_artifact_id = null`.
3. `EvidenceSnapshotCaptured` enqueues a recognized `materialize_packet` row, but the current task-specific packet payload is not frozen. `GHCA_ACD_Archive_Task_Catalog` installs and validates only `capture_evidence` and `materialize_ledger`.
4. The aggregate accepts `PacketMaterialized` only during `MATERIALIZING`, bound to the exact archive, build attempt, snapshot ID/digest, and certificate digest manifest. It moves to `VERIFYING` only when both candidate layers exist.
5. The Unit of Work already binds a packet descriptor to the retained snapshot and requires `certificate_content_digests` to equal the snapshot evidence-asset digest list. For this proposal that list is exactly empty.
6. The artifact repository already fixes packet kind `packet`, role `packet`, media type `application/pdf`, artifact schema version `1`, storage adapter `private_local`, SHA-256, and artifact dedupe over archive ID, build-attempt ID, kind, and role.
7. The private store already fixes the packet PDF ceiling at 67,108,864 bytes, a 1-byte pre-validation minimum, chunks no larger than 1,048,576 bytes, immutable no-overwrite commit, and committed key shape `committed/{tenant_id}/{stream_id}/{archive_id}/{artifact_id}.pdf`.
8. The private store's bounded header/tail/xref checks are necessary but not a semantic PDF security parser.
9. The historical packet path dynamically loads LearnDash TCPDF, consumes mutable request/site state, and can append externally acquired certificates. It is rejected as an archive renderer.
10. No archive-owned immutable renderer package, approved semantic parser, measured page/memory/duration/temp ceilings, licensing approval, or cross-runtime packet-byte vector exists. Implementation therefore remains blocked.

## 3. Decision summary

| ID | Outcome |
|---|---|
| PM01 | Approve proposal-only constructed-dark scope; no implementation |
| PM02 | Permit only retained immutable archive inputs |
| PM03 | Replace AP port assumptions with the exact disposition matrix |
| PM04 | Freeze certificate-free eligibility against authoritative history and snapshot v1 |
| PM05 | Propose one exact nine-field `materialize_packet` task payload v1 |
| PM06 | Freeze `packet-input-document-v1` grammar, with producer literals blocked |
| PM07 | Freeze the minimal snapshot-v1 display mapping |
| PM08 | Freeze four packet sections and deterministic ordering, with no certificate pages |
| PM09 | Omit unavailable presentation data; do not reread it |
| PM10 | Reuse accepted task/artifact dedupe and private-key contracts; preallocate packet ID |
| PM11 | Preserve exact descriptor, snapshot, task, build, revision, and event bindings |
| PM12 | Keep renderer/producer/package selection blocked |
| PM13 | Require a pinned bounded semantic parser; selection remains blocked |
| PM14 | Require byte identity and independent vectors; packet-byte constants remain blocked |
| PM15 | Preserve byte/chunk limits; keep other resource integers blocked pending measurements |
| PM16 | Preserve blob-first then fenced atomic UoW ordering and candidate-only semantics |
| PM17 | Freeze one exact event-safe failure tuple per condition |
| PM18 | Freeze checkpoints, one-owner behavior, replay, recovery, and no overwrite |
| PM19 | Keep D16 and certificate-bearing compatibility unresolved; define attempt-five limits |
| PM20 | Keep the implementation allowlist blocked and retain the full verification floor |

## 4. AP-01 through AP-22 disposition matrix

The disposition is per concern. A split row prevents a permitted immutable fact from silently authorizing a mutable or certificate-dependent concern.

| AP | Concern | Disposition | Exact retained source or gate |
|---|---|---|---|
| AP-01 | Hire/registration anchor | `CANDIDATE_FROM_SNAPSHOT_V1` | `subject.registered_at_gmt`; nullable means display `Not provided`, never a live fallback |
| AP-01 | Group-enrollment onboarding anchor | `REQUIRES_EXACT_MAPPING` | `subject.group_ids` and course `enrollment_status` do not retain an enrollment timestamp; no onboarding deadline may be reconstructed |
| AP-02 | Active-cycle identity | `CANDIDATE_FROM_SNAPSHOT_V1` | exact `cycle` section plus `policy.relevant_settings.annual_cycle`; no current-clock selection |
| AP-03 | Half-open period and display end | `CANDIDATE_FROM_SNAPSHOT_V1` | `cycle.boundary = "[)"`, `start_gmt`, `end_gmt`, and `timezone`; formatting is frozen in PM07 |
| AP-04 | Compliance status | `REQUIRES_EXACT_MAPPING` | `calculated.compliance_status` is only `compliant`, `non_compliant`, or retained legacy `incomplete`; the renderer cannot decide open/closed from current time |
| AP-05 | Include-course-details option | `DEFERRED_VERSIONED_CAPTURE` | no snapshot-v1 field; default or option reread is forbidden |
| AP-06 | Course short description | `DEFERRED_VERSIONED_CAPTURE` | no snapshot-v1 field; postmeta, mirrored setting, and excerpt rereads are forbidden |
| AP-07 | Required/achieved credit matrix | `REQUIRES_EXACT_MAPPING` | candidates are `policy.audit_mapping`, ordered `courses`, `calculated.categories`, and `calculated.matrix`; exact namespace/category aggregation is not selected here |
| AP-08 | Orientation-only calculation | `REQUIRES_EXACT_MAPPING` | `policy.audit_mapping[course_id].is_orientation`, course completion, and `case.program_key`; no new calculation is approved in this packet slice |
| AP-09 | Dedicated deterministic course page | `CANDIDATE_FROM_SNAPSHOT_V1` | PM08 permits a course-evidence section using retained fields only |
| AP-09 | Course-details column | `DEFERRED_VERSIONED_CAPTURE` | description text is absent and the column is omitted |
| AP-10 | Organization heading | `CANDIDATE_FROM_SNAPSHOT_V1` | `organization.agency_name`, then `organization.site_name` only when the former is the empty normalized value; both are already normalized at capture |
| AP-10 | WordPress site-title fallback or render-time entity decoding | `REJECTED_FOR_ARCHIVE_MATERIALIZER` | no site read; no second normalization pass |
| AP-11 | PDF logo | `DEFERRED_VERSIONED_CAPTURE` | no immutable logo asset or descriptor exists in snapshot v1 |
| AP-11 | Dashboard-header change | `SEPARATE_LIVE_UI_AUTHORIZATION` | not packet materialization |
| AP-12 | Category and current calculation facts | `REQUIRES_EXACT_MAPPING` | only exact snapshot policy/calculated values may be used |
| AP-12 | Certificate acquisition and missing-certificate behavior | `DEFERRED_CERTIFICATE_STRATEGY` | E13 fails before snapshot/task creation; no packet fallback |
| AP-12 | Ignored roles and user exclusions | `REJECTED_FOR_ARCHIVE_MATERIALIZER` | authorization/capture policy, not a renderer decision |
| AP-13 | Media Library or external logo resolution | `REJECTED_FOR_ARCHIVE_MATERIALIZER` | paths, uploads, URLs, HTTP, and external images are forbidden |
| AP-14 | Configuration-based credit accounting | `REQUIRES_EXACT_MAPPING` | candidate inputs are `policy.audit_mapping[*].credit_minutes`, course completion, and calculated category facts; named-category literals need a separate exact mapping |
| AP-15 | BuddyBoss active/inactive partition | `SEPARATE_LIVE_UI_AUTHORIZATION` | no current account-state read or dashboard projection in a packet worker |
| AP-16 | Inactive Employees tab and actions | `SEPARATE_LIVE_UI_AUTHORIZATION` | UI/controller/capability scope only |
| AP-17 | Dashboard cache invalidation and inactive Audit Data | `SEPARATE_LIVE_UI_AUTHORIZATION` | cache/hooks/UI scope only |
| AP-18 | Primary-group visibility optimization | `SEPARATE_LIVE_UI_AUTHORIZATION` | live authorization/query behavior only |
| AP-19 | Compliance Lead dashboard authority | `SEPARATE_LIVE_UI_AUTHORIZATION` | live capability policy only; current roles are not packet input |
| AP-20 | Certificate job broker and cross-user acquisition | `DEFERRED_CERTIFICATE_STRATEGY` | no broker, route, source, or coordinator is approved |
| AP-20 | Actor authorization and transport | `SEPARATE_LIVE_UI_AUTHORIZATION` | controller/authentication/transport work remains outside archive materialization |
| AP-21 | Orientation assignment/completion rule | `REQUIRES_EXACT_MAPPING` | candidates are `policy.audit_mapping[*].is_orientation`, `courses[*].enrollment_status`, and completion facts; packet rendering cannot recalculate policy silently |
| AP-22 | Certificate Builder and TCPDF fallback | `DEFERRED_CERTIFICATE_STRATEGY` | both are unapproved archive producers; no fallback chain |

## 5. Proposed retained packet contracts

### 5.1 Exact proposed `materialize_packet` task payload v1

The proposed payload has exactly these lexicographically ordered keys:

```json
{
  "archive_id": "32 lowercase hexadecimal characters",
  "build_attempt_id": "32 lowercase hexadecimal characters",
  "canonical_format_version": "ghca-cjson-1",
  "packet_artifact_id": "32 lowercase hexadecimal characters",
  "snapshot_id": "32 lowercase hexadecimal characters",
  "stream_id": "32 lowercase hexadecimal characters",
  "task_schema_version": 1,
  "task_type": "materialize_packet",
  "trigger_event_id": "32 lowercase hexadecimal characters"
}
```

The canonical payload is at most 512 bytes. Missing, extra, reordered in-memory keys at the validator boundary, wrong scalar types, uppercase IDs, unsupported versions, a non-`EvidenceSnapshotCaptured` trigger, or disagreement with indexed task-row bindings is `task_payload_invalid`. The trusted Unit of Work preallocates `packet_artifact_id` once when it creates the task; retries never replace it.

Existing retained packet rows have the earlier exact eight-field envelope, which is this grammar without `packet_artifact_id`. Once `materialize_packet` is installed, the exact packet validator must reject each such row as `task_payload_invalid` and the coordinator must fenced-dead-letter it with fixed text `The retained task payload is invalid.` before handler invocation, rendering, producer/parser access, private-object access, Build Coordinator/UoW submission, receipt creation, or lifecycle event creation. The retained payload bytes and dedupe digest are never rewritten. No migration or inferred artifact ID is approved.

Task dedupe remains the accepted `ghca-archive-task-dedupe-v1` digest over exactly `payload`, `task_type`, and `trigger_event_id`. Successful logical outcome dedupe remains the accepted `ghca-task-outcome-v1` document containing `logical_outcome = completed`, task ID, and task schema version. No packet-specific alternative queue or result grammar is introduced.

### 5.2 Exact proposed canonical packet input

`packet-input-document-v1` is canonical `ghca-cjson-1`, at most 1,048,576 bytes and 10,000 canonical values. It has exactly these lexicographically ordered top-level keys:

`archive`, `canonical_format`, `case`, `content`, `evidence_assets`, `format_version`, `packet_policy`, `producer`, `snapshot`.

Its exact grammar is:

- `format_version`: integer `1`;
- `canonical_format`: literal `ghca-cjson-1`;
- `archive`: exact `archive_id`, `build_attempt_id`, `revision_number`, `stream_id`, and `task_id`;
- `case`: exact retained `program_key` from snapshot `case.program_key`;
- `snapshot`: exact `snapshot_id`, `snapshot_digest`, and `snapshot_schema_version = 1`;
- `evidence_assets`: exact empty list `[]`;
- `packet_policy`: exact `calculation_version = 1`, `certificate_mode = "none"`, `layout_version = 1`, `packet_input_version = 1`, and the retained `policy_digest`;
- `producer`: exact `producer_key`, `producer_version`, and `producer_package_digest`; all three must match a future non-configurable attestation, and the version/digest literals remain blocked by PM12;
- `content`: exact `calculated`, `completeness`, `courses`, `cycle`, `organization`, and `subject` views defined below.

The input digest is proposed as:

`lowerhex(SHA-256(UTF-8("ghca-packet-input-v1\n" + canonical_json(packet_input_document))))`.

The producer-package digest is proposed as:

`lowerhex(SHA-256(UTF-8("ghca-packet-producer-package-v1\n" + canonical_json(approved_package_manifest))))`.

No current time, filesystem path, URL, option, user object, locale lookup, environment value, or mutable source fact occurs in either document. Exact literal producer and input digest vectors remain an implementation-blocking owner gate because no producer package exists.

### 5.3 Exact minimal content view

| Input path | Snapshot-v1 source | Rule |
|---|---|---|
| `case.program_key` | `case.program_key` | byte-identical validated machine key; displayed and packet-input-digest bound |
| `content.subject.display_name` | `subject.display_name` | byte-identical normalized UTF-8; displayed |
| `content.subject.employee_user_id` | `subject.employee_user_id` | shortest unsigned decimal; binding only, not displayed |
| `content.subject.registered_at_gmt` | `subject.registered_at_gmt` | nullable; display `Not provided` when null |
| `content.organization.agency_name` | `organization.agency_name` | byte-identical; preferred heading when nonempty |
| `content.organization.site_name` | `organization.site_name` | byte-identical; fallback only to this retained value |
| `content.cycle` | exact complete `cycle` section | byte-identical canonical object |
| `content.calculated` | exact complete `calculated` section | byte-identical; no recalculation or current-clock classification |
| `content.completeness` | exact complete `completeness` section | must remain `result = complete`; warnings use retained machine codes |
| `content.courses[*]` | ordered snapshot courses | exact subset: `category_order`, `completed_at_gmt`, `completion_status`, `course_id`, `course_order`, `course_title`, `enrollment_status`, `pass_state`, `quiz_score_basis_points`, `started_at_gmt`, `time_spent_seconds` |

Course rows retain snapshot order `(category_order, course_order, unsigned course_id)`. UTC timestamps display as fixed ASCII `YYYY-MM-DD HH:MM:SS UTC`; null displays `Not provided`. The cycle range displays `start_gmt` as `YYYY-MM-DD` and the instant one second before exclusive `end_gmt` as `YYYY-MM-DD`, both converted through the retained IANA timezone under the future pinned producer package. Labels are fixed English ASCII literals in the immutable template; locale APIs are forbidden. Unsigned seconds display as `H:MM:SS` using arbitrary-precision decimal division. Basis points display as exact decimal percent with two digits after the point; null displays `Not provided`. No float or locale formatting is permitted.

### 5.4 Exact packet section order

The proposed document contains exactly these sections:

1. **Evidence binding:** fixed title `Compliance Audit Packet`, packet-input digest, archive ID, revision number, snapshot ID, snapshot digest, producer key/version, and package digest.
2. **Subject and cycle:** retained organization heading, display name, registration date, digest-bound `case.program_key`, cycle display label, exact date range, and retained compliance status.
3. **Compliance summary:** retained total course count, total training duration, completeness result, retained exception/warning machine codes, and matrix/category values only as canonical machine-key/value tables without invented display labels or new calculations.
4. **Course evidence:** one row per ordered course with title, enrollment status, completion status, start/completion timestamps, duration, pass state, and quiz score.

There is no logo, course-description column, certificate page, attachment, external reference, mutable footer, generated-at time, page URL, current user, role, signature, or publication statement. Page numbering may use only deterministic final page count after layout. Requirement/credit presentation described by AP-07/AP-08/AP-14/AP-21 remains excluded until its exact mapping receives a later owner amendment.

## 6. Decisions PM01-PM20

### PM01 - Proposal scope and constructed-dark boundary

**Status.** Proposed for documentation approval only.

**Exact proposed decision.** Approve only the certificate-free contract evaluation in this record. Do not install a packet handler, add it to the runtime registry, claim packet tasks, render PDFs, alter task production, or activate any surface.

**Evidence and rationale.** The packet task is recognized but uninstalled, and activation traceability requires it to remain absent.

**Security and correctness consequences.** Proposal approval cannot expose PII, mutate lifecycle state, or create a false production-readiness signal.

**Explicit exclusions.** Production/test code, schema, metadata, hooks, routes, scheduling, downloads, current-site testing, certificate work, verification/finalization, and deployment.

**Remaining owner/implementation gates.** PM12-PM15 evidence, literal vectors, exact allowlist, and separate implementation authorization.

**Named regression requirements.** `[RETAINED_EXISTING] PACKET-PM01-CONSTRUCTED-DARK`; `[FUTURE_EXECUTABLE] PACKET-PM01-HANDLER-UNINSTALLED`; `[FUTURE_EXECUTABLE] PACKET-PM01-NO-RUNTIME-WIRING`; `[FUTURE_EXECUTABLE] PACKET-PM01-NO-CURRENT-SITE`; `[FUTURE_EXECUTABLE] PACKET-PM01-NO-SCHEMA-CHANGE`.

**Exact owner-approval wording.** `Approve PM01 as written for proposal-only constructed-dark scope; authorize no packet implementation or activation.`

### PM02 - Authority hierarchy and immutable input boundary

**Status.** Proposed.

**Exact proposed decision.** Authority order is accepted event/state contracts, exact retained snapshot row/document/digest, accepted immutable artifact records, approved task/fence records, then approved code attestation. Historical live code is non-authoritative discovery evidence. A packet materializer reads no WordPress, LearnDash, options, uploads, URLs, globals, users, roles, hooks, filters, network, or current clock.

**Evidence and rationale.** Technical design requires materializers to accept a sealed snapshot rather than an employee ID for live rereads.

**Security and correctness consequences.** Prevents time-of-check/time-of-use drift and mutable presentation facts entering immutable evidence.

**Explicit exclusions.** Fallbacks, ambient locale, request context, task-supplied evidence, and reconstruction from live UI rows.

**Remaining owner/implementation gates.** Future code must mechanically block every forbidden source.

**Named regression requirements.** `[FUTURE_EXECUTABLE] PACKET-PM02-SNAPSHOT-ONLY`; `[FUTURE_EXECUTABLE] PACKET-PM02-NO-WPDB-GLOBAL`; `[FUTURE_EXECUTABLE] PACKET-PM02-NO-OPTIONS`; `[FUTURE_EXECUTABLE] PACKET-PM02-NO-LEARNDASH-READ`; `[FUTURE_EXECUTABLE] PACKET-PM02-NO-CLOCK`; `[FUTURE_EXECUTABLE] PACKET-PM02-NO-NETWORK`.

**Exact owner-approval wording.** `Approve PM02's authority order and immutable-input-only boundary.`

### PM03 - AP-01 through AP-22 disposition

**Status.** Proposed.

**Exact proposed decision.** Adopt Section 4 as the complete disposition of every former port item. A candidate label is not implementation approval; `REQUIRES_EXACT_MAPPING`, every deferred label, and every separate-live label remain excluded.

**Evidence and rationale.** Mixed live/archive rows previously hid mutable and certificate-dependent behavior inside apparently portable features.

**Security and correctness consequences.** Prevents a live UI or transport change from entering the immutable worker under a packet label.

**Explicit exclusions.** Recombining split AP concerns or treating a candidate as proof of an exact mapping.

**Remaining owner/implementation gates.** Separate amendments for exact calculation mappings, capture v2 fields, certificate strategy, and live UI work.

**Named regression requirements.** `[FUTURE_EXECUTABLE] PACKET-PM03-AP01-AP22-COMPLETE`; `[FUTURE_EXECUTABLE] PACKET-PM03-MIXED-ROWS-SPLIT`; `[FUTURE_EXECUTABLE] PACKET-PM03-NO-PORT-BY-ASSUMPTION`; `[FUTURE_EXECUTABLE] PACKET-PM03-DEFERRED-NOT-COUNTED-PASS`.

**Exact owner-approval wording.** `Approve PM03 and the complete split AP-01 through AP-22 disposition matrix.`

### PM04 - Exact packet eligibility grammar

**Status.** Proposed around existing schemas; implementation remains blocked.

**Exact proposed decision.** Before rendering or object access, require one live lease and an authoritative history proving: exact task/row/payload identity; `EvidenceSnapshotCaptured` trigger; matching stream/archive/build/snapshot/revision; retained snapshot canonical/digest integrity; aggregate revision in `MATERIALIZING`; no packet artifact/result; no open source-drift, integrity, unprotected-reset, destructive-reset, active-reset, cancellation, correction, or other aggregate block; expected head sequence/digest loaded for the eventual command; `source.evidence_assets === []`; capture-event certificate ID/digest lists are both empty; every course has `certificate_required === false` and `certificate_artifact_id === null`; and the task's preallocated packet ID has no contradictory descriptor/event.

No new schema field is invented. Eligibility is reconstructed from accepted event history, aggregate state, snapshot row/document, task row, and artifact repository. If an accepted aggregate API cannot expose one predicate without duplicating private state-machine logic, implementation stops for a narrow authoritative-query decision rather than weakening the predicate.

**Evidence and rationale.** These facts already exist across accepted authoritative records, but no packet coordinator currently performs the combined revalidation.

**Security and correctness consequences.** Prevents certificate-bearing, blocked, stale, conflicting, or cross-case work.

**Explicit exclusions.** Handler claims, current-site state, inferred success from lease expiry, and permissive empty/missing equivalence.

**Remaining owner/implementation gates.** Exact authoritative coordinator API and regression proof without state-machine duplication.

**Named regression requirements.** `[FUTURE_EXECUTABLE] PACKET-PM04-ELIGIBLE-EMPTY-MANIFEST`; `[FUTURE_EXECUTABLE] PACKET-PM04-NONEMPTY-MANIFEST-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM04-CERTIFICATE-REQUIRED-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM04-SNAPSHOT-TAMPER-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM04-BINDING-MISMATCH-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM04-OPEN-INCIDENT-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM04-PRIOR-PACKET-CONFLICT`; `[FUTURE_EXECUTABLE] PACKET-PM04-HEAD-BINDING-RELOADED`.

**Exact owner-approval wording.** `Approve PM04's exact certificate-free eligibility predicate, subject to the authoritative-query implementation gate.`

### PM05 - Exact materialize_packet task payload v1

**Status.** Proposed retained contract and exact legacy-row terminal behavior; no writer/reader change authorized.

**Exact proposed decision.** Adopt Section 5.1's nine-field, 512-byte, strict payload and preallocated packet artifact ID. Once the handler is installed, every retained eight-field packet row is deterministically rejected and fenced-dead-lettered as `task_payload_invalid` before any handler, renderer, object-store, Build Coordinator, UoW, receipt, or lifecycle side effect. Do not rewrite or migrate the retained row.

**Evidence and rationale.** The current packet envelope lacks artifact identity while D02 expressly deferred its task-specific contract to this slice.

**Security and correctness consequences.** Makes new retries converge on one identity, prevents task data from becoming an open instruction object, and prevents a legacy row from deriving a new artifact identity or touching immutable storage.

**Explicit exclusions.** Capture/ledger payload changes, schema version 2, optional fields, names, paths, producer data, certificate facts, legacy payload migration, inferred artifact IDs, and treating a legacy payload as retryable.

**Remaining owner/implementation gates.** Literal payload bytes/hash vector and retained-eight-field disposition tests.

**Named regression requirements.** `[FUTURE_EXECUTABLE] PACKET-PM05-PAYLOAD-EXACT-FIELDS`; `[FUTURE_EXECUTABLE] PACKET-PM05-PAYLOAD-512-EQUALITY`; `[FUTURE_EXECUTABLE] PACKET-PM05-PAYLOAD-513-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM05-PAYLOAD-TYPE-STRICT`; `[FUTURE_EXECUTABLE] PACKET-PM05-PREALLOCATED-ID-STABLE`; `[FUTURE_EXECUTABLE] PACKET-PM05-DEDUPE-LITERAL-VECTOR`; `[FUTURE_EXECUTABLE] PACKET-PM05-RETAINED-EIGHT-FIELD-DEAD-EVENT-FREE`; `[FUTURE_EXECUTABLE] PACKET-PM05-RETAINED-EIGHT-FIELD-BYTES-UNTOUCHED`; `[FUTURE_EXECUTABLE] PACKET-PM05-RETAINED-EIGHT-FIELD-ZERO-HANDLER-OBJECT-UOW`; `[FUTURE_EXECUTABLE] PACKET-PM05-UNKNOWN-SCHEMA-NO-SIDE-EFFECT`.

**Exact owner-approval wording.** `Approve PM05's proposed exact packet task payload v1 and event-free task_payload_invalid dead-letter of retained eight-field rows before all handler, object, UoW, receipt, or lifecycle effects, without authorizing implementation.`

### PM06 - Canonical packet input document

**Status.** Grammar and domains proposed; producer literals and golden constants blocked.

**Exact proposed decision.** Adopt Sections 5.2-5.3 as the only input grammar, canonical rules, 1,048,576-byte ceiling, 10,000-value ceiling, and `ghca-packet-input-v1` domain. Reject missing/extra fields and any noncanonical or unavailable value before rendering.

**Evidence and rationale.** Separating immutable input from PDF bytes makes provenance, replay, and deterministic testing explicit.

**Security and correctness consequences.** Prevents renderer-specific hidden reads and makes every byte-affecting fact reviewable.

**Explicit exclusions.** Open metadata, PHP serialization, floats, paths, URLs, clock, locale lookup, mutable options, and certificate data.

**Remaining owner/implementation gates.** Exact approved producer literals and independent literal input bytes/digest.

**Named regression requirements.** `[FUTURE_EXECUTABLE] PACKET-PM06-INPUT-EXACT-GRAMMAR`; `[FUTURE_EXECUTABLE] PACKET-PM06-INPUT-CANONICAL-ROUNDTRIP`; `[FUTURE_EXECUTABLE] PACKET-PM06-INPUT-DIGEST-DOMAIN`; `[FUTURE_EXECUTABLE] PACKET-PM06-PROGRAM-KEY-DIGEST-BOUND`; `[FUTURE_EXECUTABLE] PACKET-PM06-INPUT-10000-EQUALITY`; `[FUTURE_EXECUTABLE] PACKET-PM06-INPUT-10001-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM06-INPUT-BYTE-CEILING`; `[FUTURE_EXECUTABLE] PACKET-PM06-INPUT-FORBIDDEN-FIELD`; `[FUTURE_EXECUTABLE] PACKET-PM06-INPUT-LITERAL-VECTOR`.

**Exact owner-approval wording.** `Approve PM06's packet-input grammar and digest domain while keeping producer-bound vectors blocked.`

### PM07 - Snapshot-v1 field-to-packet mapping

**Status.** Minimal mapping proposed; AP calculation extensions deferred.

**Exact proposed decision.** Adopt Section 5.3's exact mapping and formatting. Render retained compliance/calculation facts without recalculation. Omit email, roles, group IDs, source record IDs, quiz attempts, and provenance rows from visible output, while keeping necessary identity/calculation facts in the input digest.

**Evidence and rationale.** The selected fields are present and validated in snapshot v1; presentation-only data is not.

**Security and correctness consequences.** Minimizes PII and prevents display-time policy drift.

**Explicit exclusions.** Descriptions, logos, site fallback, current-period determination, friendly category invention, and current-role authorization.

**Remaining owner/implementation gates.** Cross-runtime timezone/date fixtures and later exact AP-07/AP-08/AP-14/AP-21 mapping amendment.

**Named regression requirements.** `[FUTURE_EXECUTABLE] PACKET-PM07-FIELD-MAPPING-EXACT`; `[FUTURE_EXECUTABLE] PACKET-PM07-PROGRAM-KEY-SNAPSHOT-ONLY`; `[FUTURE_EXECUTABLE] PACKET-PM07-NULL-DISPLAY-STABLE`; `[FUTURE_EXECUTABLE] PACKET-PM07-DECIMAL-NO-FLOAT`; `[FUTURE_EXECUTABLE] PACKET-PM07-CYCLE-END-EXCLUSIVE`; `[FUTURE_EXECUTABLE] PACKET-PM07-NO-RECALCULATION`; `[FUTURE_EXECUTABLE] PACKET-PM07-PII-MINIMIZED`.

**Exact owner-approval wording.** `Approve PM07's minimal snapshot-v1 mapping and defer every unmapped calculation or presentation value.`

### PM08 - Packet sections, manifest, and ordering

**Status.** Proposed.

**Exact proposed decision.** Adopt Section 5.4's four sections in exact order. The evidence-asset manifest is the exact empty list; the event's `certificate_content_digests` is also exact `[]`. Course order is snapshot order. Object keys use canonical JSON order. Matrix/category machine keys use bytewise ASCII order only where retained, with ODP namespace before OLTL. No certificate or attachment section exists.

**Evidence and rationale.** The snapshot already proves course order; the event catalog already binds the certificate digest list.

**Security and correctness consequences.** Prevents reordering, substitution, hidden attachments, and certificate fallback.

**Explicit exclusions.** Course descriptions, logos, certificate pages, mutable headers/footers, and unapproved requirement rows.

**Remaining owner/implementation gates.** Renderer layout fixture and final page-ceiling decision.

**Named regression requirements.** `[FUTURE_EXECUTABLE] PACKET-PM08-SECTION-ORDER`; `[FUTURE_EXECUTABLE] PACKET-PM08-COURSE-ORDER`; `[FUTURE_EXECUTABLE] PACKET-PM08-EMPTY-ASSET-MANIFEST`; `[FUTURE_EXECUTABLE] PACKET-PM08-ADDITIONAL-ASSET-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM08-REORDERED-COURSE-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM08-NO-CERTIFICATE-PAGES`; `[FUTURE_EXECUTABLE] PACKET-PM08-NO-ATTACHMENTS`.

**Exact owner-approval wording.** `Approve PM08's exact minimal section, manifest, and ordering contract.`

### PM09 - Data minimization and unavailable presentation fields

**Status.** Proposed.

**Exact proposed decision.** Omit every absent presentation field rather than display a live fallback. Fixed literal `Not provided` is allowed only for a nullable retained field named in PM07. Packet bytes, input documents, descriptors, events, task rows, receipts, and logs contain no email unless separately approved, no URL, path, SQL, credential, cookie, nonce, raw exception, stack trace, or hidden source record.

**Evidence and rationale.** Snapshot v1 is closed and does not carry the former live presentation inputs.

**Security and correctness consequences.** Prevents accidental expansion of retained PII and operational-secret leakage.

**Explicit exclusions.** Semantic PHI heuristics, hidden metadata bags, debug output, and copying the full snapshot into PDF metadata.

**Remaining owner/implementation gates.** Sanitized fixture review and PDF metadata inspection.

**Named regression requirements.** `[FUTURE_EXECUTABLE] PACKET-PM09-ABSENT-FIELD-OMITTED`; `[FUTURE_EXECUTABLE] PACKET-PM09-NOT-PROVIDED-ONLY-NULL`; `[FUTURE_EXECUTABLE] PACKET-PM09-NO-EMAIL-OUTPUT`; `[FUTURE_EXECUTABLE] PACKET-PM09-NO-SENSITIVE-METADATA`; `[FUTURE_EXECUTABLE] PACKET-PM09-ERROR-REDACTION`.

**Exact owner-approval wording.** `Approve PM09's omission, minimization, and sensitive-output rules.`

### PM10 - Artifact, dedupe, filename, and storage-key identities

**Status.** Existing identities retained; packet ID preallocation proposed.

**Exact proposed decision.** Use the exact preallocated `packet_artifact_id` from PM05; introduce no artifact-ID hash domain. Task dedupe remains `ghca-archive-task-dedupe-v1`. Artifact dedupe remains `ghca-artifact-dedupe-v1` over exact archive ID, build-attempt ID, `artifact_kind = packet`, and `role_key = packet`. Outcome dedupe remains `ghca-task-outcome-v1`. Content digest is raw streamed SHA-256. Filename is exact `archive-audit-packet.pdf`. Storage adapter is `private_local`; committed key is exact `committed/{tenant_id}/{stream_id}/{archive_id}/{packet_artifact_id}.pdf`.

Distinct packet command/correlation IDs must use a new closed `ghca-packet-command-id-v1` domain over purpose and task ID so existing P3B1 and P3B2a IDs remain byte-identical. Allowed purposes are `command:RecordMaterializedArtifact`, `correlation:RecordMaterializedArtifact`, `command:FailArchive`, and `correlation:FailArchive` only.

**Evidence and rationale.** These contracts fit accepted task/artifact schemas and avoid changing ledger/capture identity domains.

**Security and correctness consequences.** Prevents cross-kind receipt collision, PII filenames, path construction, and overwrite.

**Explicit exclusions.** Names/cycles in keys, caller paths, random retry IDs, cross-tenant reuse, and changing retained digester domains.

**Remaining owner/implementation gates.** Independent literal task, artifact-dedupe, outcome, command-ID, filename, and key vectors.

**Named regression requirements.** `[FUTURE_EXECUTABLE] PACKET-PM10-ARTIFACT-ID-PREALLOCATED`; `[FUTURE_EXECUTABLE] PACKET-PM10-TASK-DEDUPE-VECTOR`; `[FUTURE_EXECUTABLE] PACKET-PM10-ARTIFACT-DEDUPE-VECTOR`; `[FUTURE_EXECUTABLE] PACKET-PM10-OUTCOME-DEDUPE-VECTOR`; `[FUTURE_EXECUTABLE] PACKET-PM10-COMMAND-ID-DOMAIN`; `[FUTURE_EXECUTABLE] PACKET-PM10-P3B1-IDS-UNCHANGED`; `[FUTURE_EXECUTABLE] PACKET-PM10-FILENAME-EXACT`; `[FUTURE_EXECUTABLE] PACKET-PM10-STORAGE-KEY-EXACT`.

**Exact owner-approval wording.** `Approve PM10's accepted dedupe reuse, packet-specific command domain, preallocated artifact ID, filename, and private key contract.`

### PM11 - Descriptor and authoritative bindings

**Status.** Proposed using accepted schema/event fields.

**Exact proposed decision.** The descriptor has exactly accepted fields and values: task packet artifact ID; kind `packet`; schema version `1`; future approved producer key/version; role `packet`; storage adapter `private_local`; PM10 key/filename; media `application/pdf`; streamed byte count; algorithm `sha256`; streamed content digest. UoW binding is exact stream/archive/snapshot/build attempt/snapshot digest. `PacketMaterialized` payload is exact archive ID, build attempt, snapshot ID/digest, packet artifact ID, content digest, and `certificate_content_digests = []`. Causation is the `EvidenceSnapshotCaptured` trigger; the live task fence, expected sequence, and expected head digest are mandatory.

**Evidence and rationale.** Every field already exists in artifact/event/command contracts; no schema extension is needed.

**Security and correctness consequences.** Prevents cross-snapshot, cross-attempt, cross-stream, and substituted-object commits.

**Explicit exclusions.** Packet-input digest or package digest stuffed into open descriptor fields. `producer_version` remains the future approved implementation/template version under the retained 1-64 character version grammar. The distinct 64-character lowercase hexadecimal `producer_package_digest` appears only in the exact packet input and rendered evidence-binding section and must match non-configurable package attestation; it is not substituted for `producer_version`.

**Remaining owner/implementation gates.** Producer literal and exact command coordinator method.

**Named regression requirements.** `[FUTURE_EXECUTABLE] PACKET-PM11-DESCRIPTOR-EXACT`; `[FUTURE_EXECUTABLE] PACKET-PM11-PRODUCER-VERSION-NOT-PACKAGE-DIGEST`; `[FUTURE_EXECUTABLE] PACKET-PM11-SNAPSHOT-BINDING`; `[FUTURE_EXECUTABLE] PACKET-PM11-TASK-BINDING`; `[FUTURE_EXECUTABLE] PACKET-PM11-BUILD-REVISION-BINDING`; `[FUTURE_EXECUTABLE] PACKET-PM11-EVENT-PAYLOAD-EXACT`; `[FUTURE_EXECUTABLE] PACKET-PM11-EMPTY-DIGEST-LIST-EXACT`; `[FUTURE_EXECUTABLE] PACKET-PM11-CAUSATION-AND-FENCE`.

**Exact owner-approval wording.** `Approve PM11's exact existing-schema descriptor, UoW, and PacketMaterialized bindings.`

### PM12 - Producer, renderer, and package provenance

**Status.** Implementation-blocking owner decision remains open.

**Exact proposed decision.** Select no current renderer. A future selectable producer must be archive-owned, immutable, redistributable, and identified by three distinct attested authorities: exact `producer_key`; exact 1-64 character `producer_version` denoting the implementation/template contract version and stored in the retained descriptor; and exact 64-character lowercase hexadecimal `producer_package_digest` denoting the closed package-manifest SHA-256 and bound into the canonical packet input and rendered evidence-binding section. Equality between version and package digest is forbidden. The package manifest must close every source file, template, font, asset, dependency, build setting, metadata setting, license, notice, and code attestation. The producer may perform no WordPress/LearnDash read, hook/filter call, URL/upload access, ambient locale/time/randomness, or mutable metadata generation.

**Evidence and rationale.** Historical TCPDF/FPDI depends on LearnDash and mutable runtime state. No qualifying package is present.

**Security and correctness consequences.** Prevents dependency substitution, nondeterministic bytes, and unlicensed redistribution.

**Explicit exclusions.** Existing LearnDash TCPDF, Certificate Builder/mPDF, FPDI as a renderer, system fonts, external assets, and version-header-only attestation.

**Remaining owner/implementation gates.** Exact package, manifest digest, producer key, implementation/template version, license/legal approval, SBOM, build provenance, and non-configurable code attestation.

**Named regression requirements.** `[DEFERRED_OPERATOR_EVIDENCE] PACKET-PM12-PACKAGE-MANIFEST-APPROVED`; `[DEFERRED_OPERATOR_EVIDENCE] PACKET-PM12-LICENSING-APPROVED`; `[FUTURE_EXECUTABLE] PACKET-PM12-PRODUCER-ATTESTATION`; `[FUTURE_EXECUTABLE] PACKET-PM12-PRODUCER-VERSION-PACKAGE-DIGEST-DISTINCT`; `[FUTURE_EXECUTABLE] PACKET-PM12-PACKAGE-SUBSTITUTION-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM12-LIVE-TCPDF-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM12-AMBIENT-INPUT-REJECTED`.

**Exact owner-approval wording.** `Approve PM12's producer requirements and continued renderer deferral; select no renderer or package.`

### PM13 - Semantic PDF validation and active-content policy

**Status.** Policy proposed; parser selection blocked.

**Exact proposed decision.** Require the existing bounded structural store validation plus a separately pinned bounded semantic PDF parser with exact version, license, code-manifest digest, adversarial corpus, and fail-closed API. It must reject encryption; JavaScript; actions, open actions, and additional actions; launch behavior; embedded files, attachments, and file specifications; rich media; XFA; `AcroForm`; external references; malformed objects, xref tables, and xref streams; unresolved references; and trailing payloads. It must prove `page_count >= 1` and enforce PM15's future approved maximum.

**Evidence and rationale.** Header/xref/EOF validation does not prove semantic safety. No qualifying parser is approved.

**Security and correctness consequences.** Blocks active content, parser bombs, hidden payloads, and zero-page documents.

**Explicit exclusions.** Lexical token scans, generator-success claims, browser rendering, unbounded parse trees, and FPDI import success as validation.

**Remaining owner/implementation gates.** Exact parser package, limits, corpus, licensing, and cross-runtime results.

**Named regression requirements.** `[FUTURE_EXECUTABLE] PACKET-PM13-ENCRYPTION-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM13-JAVASCRIPT-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM13-ACTIONS-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM13-LAUNCH-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM13-ATTACHMENTS-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM13-RICHMEDIA-XFA-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM13-ACROFORM-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM13-EXTERNAL-REFERENCE-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM13-MALFORMED-XREF-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM13-TRAILING-PAYLOAD-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM13-ZERO-PAGE-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM13-PAGE-CEILING-ENFORCED`.

**Exact owner-approval wording.** `Approve PM13's exact semantic PDF policy while keeping parser selection and implementation blocked.`

### PM14 - Cross-runtime byte determinism and golden vectors

**Status.** Requirement proposed; exact packet bytes blocked.

**Exact proposed decision.** Identical packet input and approved producer package must yield byte-identical PDF bytes, byte count, SHA-256, page count, and parser result on PHP 8.3.30 and 8.5.7. PHP 8.4.12 is supplemental evidence and never substitutes for either required runtime. Independent literals must cover task payload bytes/dedupe, packet-input bytes/digest, packet/artifact identity and dedupe, producer manifest bytes/digest, descriptor, complete PDF bytes/content digest, and exact `PacketMaterialized` payload. Tests must construct literals without invoking the production helper under test.

**Evidence and rationale.** No current renderer has proven deterministic metadata, fonts, layout, compression, or object numbering.

**Security and correctness consequences.** Makes retry/orphan recovery compare expected bytes rather than accepting whichever output appeared first.

**Explicit exclusions.** Structural-only equality, per-runtime expected hashes, normalized-after-render comparison, and learning expected digests from an orphan.

**Remaining owner/implementation gates.** Every literal constant and representative fixed fixture after PM12-PM15 approval.

**Named regression requirements.** `[FUTURE_EXECUTABLE] PACKET-PM14-PAYLOAD-LITERAL-VECTOR`; `[FUTURE_EXECUTABLE] PACKET-PM14-INPUT-LITERAL-VECTOR`; `[FUTURE_EXECUTABLE] PACKET-PM14-IDENTITY-LITERAL-VECTOR`; `[FUTURE_EXECUTABLE] PACKET-PM14-PRODUCER-LITERAL-VECTOR`; `[FUTURE_EXECUTABLE] PACKET-PM14-PDF-LITERAL-VECTOR`; `[FUTURE_EXECUTABLE] PACKET-PM14-EVENT-LITERAL-VECTOR`; `[FUTURE_EXECUTABLE] PACKET-PM14-PHP83-PHP85-BYTE-IDENTITY`; `[FUTURE_EXECUTABLE] PACKET-PM14-PHP84-SUPPLEMENTAL`.

**Exact owner-approval wording.** `Approve PM14's deterministic and independent-vector requirements while keeping all producer-bound constants blocked.`

### PM15 - Resource ceilings and measurement plan

**Status.** Existing byte/chunk ceilings retained; remaining limits blocked.

**Exact proposed decision.** Preserve inclusive packet size `1..67,108,864` bytes and stream/read/hash chunks no larger than `1,048,576` bytes. Do not guess maximum pages, peak PHP memory, renderer duration, parser duration, total task duration, or temporary-storage bytes/files. Measure sanitized smallest, typical, maximum-course, longest-text, complex-font, multi-page, and adversarial fixtures using the final producer/parser on PHP 8.3.30 and 8.5.7, with PHP 8.4.12 supplemental evidence, then obtain owner approval of exact inclusive integers.

**Evidence and rationale.** Output bytes do not bound in-memory layout or parser expansion.

**Security and correctness consequences.** Prevents denial of service and dependence on host defaults.

**Explicit exclusions.** `memory_limit`, web timeout, nominal page counts, and measurements from a different renderer/parser.

**Remaining owner/implementation gates.** Representative measurements and exact page/memory/duration/temp limits.

**Named regression requirements.** `[RETAINED_EXISTING] PACKET-PM15-BYTE-CEILING-RETAINED`; `[RETAINED_EXISTING] PACKET-PM15-CHUNK-CEILING-RETAINED`; `[FUTURE_EXECUTABLE] PACKET-PM15-BYTE-EQUALITY`; `[FUTURE_EXECUTABLE] PACKET-PM15-BYTE-OVERFLOW`; `[DEFERRED_OPERATOR_EVIDENCE] PACKET-PM15-REPRESENTATIVE-MEASUREMENTS`; `[FUTURE_EXECUTABLE] PACKET-PM15-HOST-DEFAULTS-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM15-TEMP-CONTAINMENT`.

**Exact owner-approval wording.** `Approve PM15's retained byte/chunk limits and measurement gate; approve no other resource ceiling yet.`

### PM16 - Unit-of-Work ordering and candidate-only semantics

**Status.** Proposed.

**Exact proposed decision.** The only permitted order is: recover matching authoritative success first; load/revalidate task, history, aggregate, snapshot, and bindings; prove PM04 eligibility; attest producer/parser; build and hash canonical input; deterministically derive expected PDF bytes/count/digest; only then inspect/reuse the exact committed object; stage/commit missing bytes outside a database transaction; validate the complete prepared result; checkpoint the lease; invoke the fenced Build Coordinator/Unit of Work; atomically insert one packet descriptor and append one `PacketMaterialized`; return success only after commit; replay the stored response byte-for-byte after response loss.

`PacketMaterialized` means only that one candidate human-readable packet artifact was durably bound to the named snapshot/build. It does not verify, finalize, activate, publish, expose, download, or make the archive available. A generated verify task remains uninstalled and untouched.

**Evidence and rationale.** This is the accepted blob-first materialization boundary and event meaning.

**Security and correctness consequences.** Prevents events before bytes, partial authority, and accidental finalization.

**Explicit exclusions.** External work inside a transaction, task completion before command commit, direct repository writes, and verify-handler dispatch.

**Remaining owner/implementation gates.** Packet-specific coordinator method and exact atomic integration regressions.

**Named regression requirements.** `[FUTURE_EXECUTABLE] PACKET-PM16-RECOVERY-FIRST`; `[FUTURE_EXECUTABLE] PACKET-PM16-BYTES-BEFORE-EVENT`; `[FUTURE_EXECUTABLE] PACKET-PM16-OUTSIDE-TRANSACTION`; `[FUTURE_EXECUTABLE] PACKET-PM16-ATOMIC-DESCRIPTOR-EVENT`; `[FUTURE_EXECUTABLE] PACKET-PM16-CANDIDATE-ONLY`; `[FUTURE_EXECUTABLE] PACKET-PM16-VERIFY-TASK-UNCLAIMED`; `[FUTURE_EXECUTABLE] PACKET-PM16-RESPONSE-REPLAY-BYTE-IDENTICAL`.

**Exact owner-approval wording.** `Approve PM16's blob-first fenced UoW order and candidate-only PacketMaterialized semantics.`

### PM17 - Exact closed failure grammar

**Status.** Proposed; failure categories and worker dispositions are separate closed fields and neither is inferred from exception text.

**Exact proposed decision.** Use exactly the two tables below. The first table's `Packet category / reason / context` is the exact sanitized packet-failure tuple. `Packet category` has only `invalid`, `retryable`, `operational_blocked`, or `integrity`; it is not a worker status. `Worker disposition` separately controls the task. Classification uses concrete exception class/category plus operation context; message text and prefixes never classify.

| Condition | Packet category | Reason | Context | Worker disposition | Lifecycle effect |
|---|---|---|---|---|---|
| malformed/unknown packet task payload, including retained eight-field packet row | invalid | `task_payload_invalid` | `task_validation` | fenced `dead` immediately | none |
| exact snapshot absent | invalid | `archive_snapshot_invalid` | `authoritative_load` | success recovery first; otherwise commit/replay accepted failure, then fenced `dead` | existing closed failure only after success recovery |
| snapshot schema/canonical/digest failure | invalid | `archive_snapshot_invalid` | `authoritative_load` | success recovery first; otherwise commit/replay accepted failure, then fenced `dead` | existing closed failure only after success recovery |
| task/stream/archive/build/revision/snapshot/trigger mismatch | invalid | `archive_build_binding_invalid` | `authoritative_load` | success recovery first; otherwise commit/replay accepted failure, then fenced `dead` | existing closed failure only after success recovery |
| lifecycle or incident/reset/correction state ineligible | invalid | `archive_build_binding_invalid` | `packet_eligibility` | success recovery first; otherwise commit/replay accepted failure, then fenced `dead` | existing closed failure only after success recovery |
| nonempty/contradictory evidence manifest | operational_blocked | `archive_packet_invalid` | `packet_eligibility` | fenced `retry` on attempts 1-4; fenced `dead` on attempt 5 | none |
| any certificate-required course or artifact ID | operational_blocked | `archive_certificate_invalid` | `packet_eligibility` | fenced `retry` on attempts 1-4; fenced `dead` on attempt 5 | none; current E13 should have prevented the task |
| producer/package absent, incompatible, or unattested | operational_blocked | `task_handler_failed` | `packet_generate` | fenced `retry` on attempts 1-4; fenced `dead` on attempt 5 | none |
| deterministic renderer failure or resource exhaustion | operational_blocked | `task_handler_failed` | `packet_generate` | fenced `retry` on attempts 1-4; fenced `dead` on attempt 5 | none |
| parser absent, incompatible, or unattested | operational_blocked | `task_handler_failed` | `packet_validate` | fenced `retry` on attempts 1-4; fenced `dead` on attempt 5 | none |
| semantic PDF rejection | operational_blocked | `task_handler_failed` | `packet_validate` | fenced `retry` on attempts 1-4; fenced `dead` on attempt 5 | none |
| explicitly enumerated transient stream interruption | retryable | `task_handler_failed` | `packet_generate` | fenced `retry` on attempts 1-4; PM19 final recovery then fenced `dead` if still failing | none at every attempt |
| staging/write/open/commit access failure without positive mismatch | retryable | `task_handler_failed` | `artifact_write` | fenced `retry` on attempts 1-4; PM19 final recovery then fenced `dead` if still failing | none at every attempt |
| path escape, unsafe permissions, unsupported atomic commit, or non-committed symlink | operational_blocked | `task_handler_failed` | `artifact_write` | fenced `retry` on attempts 1-4; fenced `dead` on attempt 5 | none |
| proven occupied committed-key byte/digest mismatch or committed-key symlink | integrity | `archive_immutable_conflict` | `artifact_recovery` | success recovery first; otherwise commit/replay accepted conflict, then fenced `dead` | existing closed conflict only after success recovery |
| expected sequence/head conflict | retryable | `task_outcome_commit_failed` | `packet_commit` | reload outcomes; fenced `retry` on attempts 1-4; PM19 final recovery on attempt 5 | no new event unless authoritative replay already proves one |
| transient UoW/repository commit failure | retryable | `task_outcome_commit_failed` | `packet_commit` | fenced `retry` on attempts 1-4; PM19 final recovery on attempt 5 | PM19 exhaustion rule only |
| contradictory receipt/history pair | integrity | `task_outcome_commit_failed` | `authoritative_recovery` | fenced `dead` after success recovery proves no matching success | none; manual integrity review |
| unknown renderer throwable | operational_blocked | `task_handler_failed` | `packet_generate` | fenced `retry` on attempts 1-4; fenced `dead` on attempt 5 | none |
| unknown validator throwable | operational_blocked | `task_handler_failed` | `packet_validate` | fenced `retry` on attempts 1-4; fenced `dead` on attempt 5 | none |

Fencing exceptions retain the existing `GHCA_ACD_Archive_Persistence_Exception` category `integrity_blocked`. They are not packet-failure tuples and never enter the retry/dead logic above:

| Condition | Persistence category | Reason | Exact context | Worker response and disposition | Lifecycle effect |
|---|---|---|---|---|---|
| lease absent, expired, owner/token changed, or claimed task cannot be reloaded | `integrity_blocked` | `task_lease_lost` | `lease_revalidation` | return `status = lease_lost`, `reason_code = task_lease_lost`; zero task disposition | none |
| heartbeat compare-and-update rejects the lease | `integrity_blocked` | `task_heartbeat_fence_failed` | `packet_heartbeat` | return `status = lease_lost`, `reason_code = task_lease_lost`; zero task disposition | none |
| fenced UoW/outcome lease lock rejects the lease | `integrity_blocked` | `task_outcome_fence_failed` | `packet_outcome_commit` | return `status = lease_lost`, `reason_code = task_lease_lost`; zero task disposition | none |
| completion compare-and-update rejects the lease | `integrity_blocked` | `task_completion_fence_failed` | `task_completion` | return `status = lease_lost`, `reason_code = task_lease_lost`; zero task disposition | none |
| retry compare-and-update rejects the lease | `integrity_blocked` | `task_retry_fence_failed` | `task_retry` | return `status = lease_lost`, `reason_code = task_lease_lost`; zero task disposition | none |
| dead-letter compare-and-update rejects the lease | `integrity_blocked` | `task_dead_letter_fence_failed` | `task_dead_letter` | return `status = lease_lost`, `reason_code = task_lease_lost`; zero task disposition | none |

Operational renderer, parser, storage, path, and resource failures remain event-free, including attempt five. Raw exception text, SQL, paths, credentials, PII, and stack traces never enter task text, receipts, events, or ordinary logs.

**Evidence and rationale.** Local generation failure does not prove authoritative archive facts invalid. Positive retained mismatches are different from inability to access a path.

**Security and correctness consequences.** Prevents invented lifecycle facts and message-spoofed classification.

**Explicit exclusions.** Category/context alternatives, prefix matching, raw messages, and `ArchiveFailed` merely to terminalize a task.

**Remaining owner/implementation gates.** Mechanical enumeration against every reachable producer/parser/store/UoW reason after package selection.

**Named regression requirements.** `[FUTURE_EXECUTABLE] PACKET-PM17-TASK-PAYLOAD-TUPLE`; `[FUTURE_EXECUTABLE] PACKET-PM17-SNAPSHOT-MISSING-TUPLE`; `[FUTURE_EXECUTABLE] PACKET-PM17-SNAPSHOT-TAMPER-TUPLE`; `[FUTURE_EXECUTABLE] PACKET-PM17-BINDING-TUPLE`; `[FUTURE_EXECUTABLE] PACKET-PM17-ELIGIBILITY-TUPLE`; `[FUTURE_EXECUTABLE] PACKET-PM17-CERTIFICATE-TUPLE`; `[FUTURE_EXECUTABLE] PACKET-PM17-PRODUCER-TUPLE`; `[FUTURE_EXECUTABLE] PACKET-PM17-RENDERER-TUPLE`; `[FUTURE_EXECUTABLE] PACKET-PM17-PARSER-TUPLE`; `[FUTURE_EXECUTABLE] PACKET-PM17-SEMANTIC-PDF-TUPLE`; `[FUTURE_EXECUTABLE] PACKET-PM17-TRANSIENT-TUPLE`; `[FUTURE_EXECUTABLE] PACKET-PM17-STORAGE-TUPLES`; `[FUTURE_EXECUTABLE] PACKET-PM17-IMMUTABLE-CONFLICT-TUPLE`; `[FUTURE_EXECUTABLE] PACKET-PM17-UOW-TUPLES`; `[FUTURE_EXECUTABLE] PACKET-PM17-CATEGORY-DISPOSITION-SEPARATE`; `[FUTURE_EXECUTABLE] PACKET-PM17-LEASE-LOST-EXACT`; `[FUTURE_EXECUTABLE] PACKET-PM17-HEARTBEAT-FENCE-EXACT`; `[FUTURE_EXECUTABLE] PACKET-PM17-OUTCOME-FENCE-EXACT`; `[FUTURE_EXECUTABLE] PACKET-PM17-COMPLETION-FENCE-EXACT`; `[FUTURE_EXECUTABLE] PACKET-PM17-RETRY-FENCE-EXACT`; `[FUTURE_EXECUTABLE] PACKET-PM17-DEAD-LETTER-FENCE-EXACT`; `[FUTURE_EXECUTABLE] PACKET-PM17-UNKNOWN-TUPLES`; `[FUTURE_EXECUTABLE] PACKET-PM17-NO-SENSITIVE-TEXT`.

**Exact owner-approval wording.** `Approve PM17's exact closed packet failure table and event-free operational semantics.`

### PM18 - Fencing, retry, response replay, crash recovery, and collision handling

**Status.** Proposed.

**Exact proposed decision.** Checkpoint after authoritative recovery, before producer invocation, during bounded streaming at least once per 30 seconds, before staging commit, before opening/reusing an existing committed key, before prepared-result acceptance, and immediately before UoW submission. Only the current owner/token/unexpired lease may proceed. Fence loss closes owned handles, removes only invocation-owned uncommitted staging when safely contained, leaves committed bytes untouched, performs no task/lifecycle disposition, and returns lease loss.

Recovery order is matching committed packet outcome/receipt; matching failure decision; exact task/history/snapshot reconstruction; expected input and PDF bytes/count/digest derivation; exact committed-object verification/reuse; missing-object staging/commit; prepared-result validation; fence check; UoW commit. An exact committed object is idempotent success. A positive mismatch is immutable conflict. No overwrite, delete, quarantine, cross-case, cross-stream, cross-tenant, or cross-artifact reuse is allowed. Orphans remain report-only under the existing reconciler.

**Evidence and rationale.** Deterministic expected bytes and the accepted private-store link commit make crash recovery safe only in this order.

**Security and correctness consequences.** Prevents stale-worker success, digest learning from an orphan, and cross-identity substitution.

**Explicit exclusions.** Cleanup outside invocation staging, orphan deletion, handler-controlled authoritative outcome, and task completion before UoW.

**Remaining owner/implementation gates.** Deterministic producer and exact crash-injection implementation.

**Named regression requirements.** `[FUTURE_EXECUTABLE] PACKET-PM18-CHECKPOINT-BEFORE-PRODUCER`; `[FUTURE_EXECUTABLE] PACKET-PM18-CHECKPOINT-DURING-STREAM`; `[FUTURE_EXECUTABLE] PACKET-PM18-CHECKPOINT-BEFORE-COMMIT`; `[FUTURE_EXECUTABLE] PACKET-PM18-CHECKPOINT-BEFORE-UOW`; `[FUTURE_EXECUTABLE] PACKET-PM18-FENCE-LOSS-ZERO-DISPOSITION`; `[FUTURE_EXECUTABLE] PACKET-PM18-TWO-CONNECTION-ONE-OWNER`; `[FUTURE_EXECUTABLE] PACKET-PM18-CRASH-BEFORE-STAGING`; `[FUTURE_EXECUTABLE] PACKET-PM18-CRASH-AFTER-STAGING`; `[FUTURE_EXECUTABLE] PACKET-PM18-CRASH-BEFORE-OBJECT-COMMIT`; `[FUTURE_EXECUTABLE] PACKET-PM18-CRASH-AFTER-OBJECT-COMMIT`; `[FUTURE_EXECUTABLE] PACKET-PM18-CRASH-AFTER-UOW-COMMIT`; `[FUTURE_EXECUTABLE] PACKET-PM18-EXACT-OBJECT-REUSED`; `[FUTURE_EXECUTABLE] PACKET-PM18-IMMUTABLE-MISMATCH-NO-OVERWRITE`; `[FUTURE_EXECUTABLE] PACKET-PM18-CLEANUP-CONTAINED`; `[RETAINED_EXISTING] PACKET-PM18-ORPHAN-REPORT-ONLY`.

**Exact owner-approval wording.** `Approve PM18's exact checkpoint, one-owner, recovery, immutable-reuse, and zero-disposition fence-loss rules.`

### PM19 - Fifth attempt, D16, and future certificate compatibility

**Status.** Packet-delivery behavior proposed; lifecycle retry and certificate compatibility blocked.

**Exact proposed decision.** On attempt five: validate task/bindings without rendering; recover matching `PacketMaterialized` receipt/event/descriptor/object first; recover a matching accepted failure decision second; if success exists, validate and complete; if an accepted failure exists, preserve and dead-letter; otherwise make one final deterministic packet/object/UoW recovery. Operational renderer, parser, storage, path, resource, unknown, lease-loss, and certificate-gate conditions dead-letter/report only their sanitized operational task code and append no lifecycle event. Only a still-failing classified transient UoW/repository commit failure may use existing `archive_build_attempts_exhausted`, after both outcomes are rechecked and no success exists. Response loss after success never becomes failure.

D16 remains unresolved. `ArchiveRetryRequested` must not enqueue or install packet work under this proposal. Packet v1 accepts only `evidence_assets = []`. Future certificate-bearing packets require a separately approved packet-input version, manifest/order contract, identity/digest compatibility plan, renderer composition, golden vectors, and retained-v1 read policy. Certificate-free artifacts are never silently reinterpreted.

**Evidence and rationale.** Task death is not lifecycle evidence, and the accepted retry model conflicts with partial candidate reuse.

**Security and correctness consequences.** Preserves success-wins recovery and blocks certificate fallback through a v1 handler.

**Explicit exclusions.** Automatic lifecycle retry, cross-attempt rebinding, certificate mode auto-detection, and changing v1 bytes/readers.

**Remaining owner/implementation gates.** D16 owner decision and a future certificate-bearing packet version after strategy selection.

**Named regression requirements.** `[FUTURE_EXECUTABLE] PACKET-PM19-ATTEMPT5-SUCCESS-FIRST`; `[FUTURE_EXECUTABLE] PACKET-PM19-ATTEMPT5-FAILURE-REPLAY`; `[FUTURE_EXECUTABLE] PACKET-PM19-ATTEMPT5-OBJECT-RECOVERY`; `[FUTURE_EXECUTABLE] PACKET-PM19-ATTEMPT5-OPERATIONAL-NO-EVENT`; `[FUTURE_EXECUTABLE] PACKET-PM19-ATTEMPT5-UOW-EXHAUSTION-ONLY`; `[FUTURE_EXECUTABLE] PACKET-PM19-RESPONSE-LOSS-NEVER-FAILS`; `[FUTURE_EXECUTABLE] PACKET-PM19-D16-DEFERRED-NO-PACKET-ENQUEUE`; `[FUTURE_EXECUTABLE] PACKET-PM19-CERTIFICATE-V1-REJECTED`; `[FUTURE_EXECUTABLE] PACKET-PM19-RETAINED-V1-NOT-REINTERPRETED`.

**Exact owner-approval wording.** `Approve PM19's attempt-five limits and future-version gate while leaving D16 and certificate-bearing packets unresolved.`

### PM20 - Allowlist, verification matrix, deferrals, and owner request

**Status.** Verification floor proposed; implementation allowlist blocked.

**Exact proposed decision.** Approve no implementation path yet. A smallest exact production/test allowlist cannot be honest until PM12-PM15 select a producer/parser, measured resource policy, and complete literal vectors. A later allowlist may name only the packet task contract/validator, packet input builder/validator, packet handler/coordinator integration, existing UoW packet task production and packet recording path, exact producer/parser package manifest, and narrowly scoped packet tests/runner/boundaries. It must not authorize factories, service containers, generic rendering frameworks, hooks, routes, controllers, schedulers, downloads, UI work, certificate work, verification/finalization, D16, schema changes, or runtime activation.

A later implementation must run PHP 8.3.30 and 8.5.7 against disposable MySQL 8.0, MySQL 8.4, and MariaDB 10.6; PHP 8.4.12 supplemental suites; all retained archive suites; focused `php -n`; exact packet unit/persistence/failure/crash/concurrency/parser/golden tests; lint; boundaries; digests; runner uniqueness; forbidden-surface scans; and `git diff --check`. Deferred operator evidence is reported separately and never counted as an executable pass.

**Evidence and rationale.** No renderer/parser paths exist to allowlist, and speculative scaffolding would falsely imply selection.

**Security and correctness consequences.** Keeps the proposal reviewable and prevents scope expansion.

**Explicit exclusions.** Every path and runtime surface not explicitly approved by a later amendment.

**Remaining owner/implementation gates.** PM12-PM15, exact vectors, exact allowlist, separate implementation authorization, formal re-review, and downstream activation evidence.

**Named regression requirements.** `[FUTURE_EXECUTABLE] PACKET-PM20-ALLOWLIST-BLOCKED`; `[FUTURE_EXECUTABLE] PACKET-PM20-MATRIX-TWO-BY-THREE`; `[FUTURE_EXECUTABLE] PACKET-PM20-PHP84-SUPPLEMENTAL`; `[FUTURE_EXECUTABLE] PACKET-PM20-RETAINED-SUITES`; `[FUTURE_EXECUTABLE] PACKET-PM20-RUNNER-EXACTLY-ONCE`; `[FUTURE_EXECUTABLE] PACKET-PM20-FORBIDDEN-SURFACES`; `[FUTURE_EXECUTABLE] PACKET-PM20-DEFERRED-NOT-PASS`; `[FUTURE_EXECUTABLE] PACKET-PM20-ZERO-ACTIVATION`.

**Exact owner-approval wording.** `Approve PM20's verification floor and continued allowlist block; authorize no implementation.`

## 7. Regression and evidence classification

Every named identifier appears exactly once in its owning PM decision. The final inventory is mechanically counted during proposal verification.

| Classification | Meaning |
|---|---|
| `FUTURE_EXECUTABLE` | Must become a real automated assertion in a separately authorized implementation |
| `RETAINED_EXISTING` | Existing accepted behavior that the later slice must rerun and preserve |
| `DEFERRED_OPERATOR_EVIDENCE` | Human/legal/measurement evidence; never counted as an executable passing assertion |

Every future negative regression must assert the exact exception class and exact category/reason/context tuple where applicable, unchanged aggregate state unless an explicitly approved authoritative outcome commits, no unintended lifecycle event, zero unintended database residue, zero unauthorized filesystem residue, no sensitive output, and the exact task/fence disposition.

Requirement coverage is PM-owned: scope/darkness PM01; authority PM02; AP dispositions PM03; eligibility PM04; task PM05; input PM06; mapping PM07; content/order PM08; privacy PM09; identities PM10; bindings PM11; provenance PM12; parser PM13; determinism PM14; resources PM15; atomic semantics PM16; failures PM17; crash/fencing PM18; attempt five/compatibility PM19; allowlist/matrix PM20.

## 8. Implementation-blocking gates and explicit deferrals

Implementation remains blocked until all of these are separately approved:

1. one archive-owned immutable renderer package with distinct exact producer key, implementation/template version, and package digest;
2. complete dependency/font/template/asset manifest, licensing, redistribution, SBOM, build provenance, and code attestation;
3. one pinned bounded semantic parser and adversarial corpus;
4. exact page, memory, renderer-duration, parser-duration, total-duration, and temporary-storage ceilings supported by representative measurements;
5. independent literal task/input/identity/digest/descriptor/PDF/event vectors;
6. an authoritative aggregate/coordinator query surface for every PM04 predicate without private-state duplication;
7. an exact mechanical implementation allowlist; and
8. a separate explicit implementation authorization.

Still deferred: certificate acquisition and certificate-bearing packets; Strategy A/B work; E07/E08 or snapshot version changes; AP calculation extensions; verification/finalization; D16; downloads; REST/AJAX/admin/CLI controllers; hooks; scheduling; current-site or controlled testing; production activation; deployment; schema/event/canonical/digest mutation; historical read UI; live dashboard AP-15-AP-20 work; and any packet implementation branch.

## 9. Exact owner decision request

> **Approve Packet Materialization Decisions PM01-PM20 as written for documentation-only architecture: permit only a future constructed-dark certificate-free packet candidate derived exclusively from an authoritative sealed snapshot with an exactly empty evidence-asset manifest; approve event-free task_payload_invalid dead-lettering of retained eight-field packet tasks before side effects, digest-bound snapshot case.program_key, distinct producer implementation/template version and package digest authorities, the exact packet failure and six-reason fence-disposition tables, and the proposed task, input, mapping, ordering, identity, binding, candidate-event, recovery, and compatibility contracts; preserve all accepted event, snapshot, artifact, digest, task, private-storage, and state-machine invariants; keep renderer, producer package, parser, measured resource ceilings, literal producer-bound vectors, implementation allowlist, D16, certificate-bearing packets, verification/finalization, runtime wiring, current-site testing, scheduling, activation, publication, and deployment blocked; and authorize no implementation.**

Approval of that sentence records the architecture and continued implementation deferral only. It does not make any packet available or authorize a code branch.

**Approval record:** On 2026-08-13, the owner formally approved PM01-PM20 exactly as quoted above for documentation-only publication. Renderer/package selection, semantic-parser selection, measured resource ceilings, producer-bound vectors, the implementation allowlist, D16, certificate-bearing packets, verification/finalization, runtime wiring, controlled-site testing, activation, publication of packet artifacts, and deployment remain blocked. No packet implementation branch is authorized by this approval.

## 10. Proposal verification handoff

This proposal-only pass requires:

- PHP 8.3.30 boundaries and digests;
- PHP 8.5.7 boundaries and digests;
- PHP 8.4.12 boundaries and digests as supplemental evidence when the executable is real;
- exactly 20 unique PM headings and 20 exact owner-approval clauses;
- all AP-01 through AP-22 present with no missing identifier;
- unique named regression identifiers and honest three-way classification;
- UTF-8 without BOM and no trailing whitespace;
- searches for certificate approval, live/mutable packet input, selected renderer/parser, authorized allowlist, ambiguous failure tuple, stale port wording, verification/finalization authority, and runtime activation;
- `git diff --check` plus explicit untracked-document whitespace validation;
- zero production, test, schema, metadata, entrypoint, manifest, or runner changes;
- zero staged files and only this proposal plus untouched `.claude/` untracked; and
- no database, Docker, current-site, network, PDF, generator, parser, activation, deployment, commit, or push operation.
