# Slice 1B-P3B3 Activation Contracts Decision Proposal

**Date:** 2026-08-01
**Status:** formally approved for documentation only; implementation and activation remain unauthorized
**Branch:** `feature/dual-layer-archive-slice-1b-p3b3-activation-contracts`
**Proposal starting HEAD:** `3f1682678d8b4790990d4d74728de61ffc884512`
**Accepted parent:** P3B3 constructed-dark runtime at the same commit

## 1. Purpose and authority

This proposal freezes the remaining activation contracts before either current-site controlled testing or production activation. It does not authorize implementation, current-site access, runtime registration, scheduling, activation, deployment, or credential handling.

The authority order is:

1. the accepted event-sourcing technical design and development handoff;
2. accepted P3A worker/storage decisions and traceability, including H1 and the PHP 8.3 compatibility decision;
3. accepted P3B1 ledger decisions and traceability;
4. accepted P3B2a evidence-capture decisions and traceability;
5. accepted P3B2b evidence-source decisions and traceability;
6. accepted P3B3 C01-C27 runtime-composition decisions and traceability; and
7. the accepted production classes inspected for this proposal.

No decision below changes an event name, event payload, command identity, canonical document, digest domain, schema, immutable key, worker fence, retry delay, retained row, artifact byte, or source mapping.

## 2. Runtime states remain separate

```text
constructed_dark
    |
    | A01-A18 approved and separately implemented
    | owner authorizes one exact controlled-test window
    v
controlled_testing
    |
    | A19 evidence accepted
    | certificate + packet + verify/finalize + D16 gates resolved
    | owner separately authorizes production
    v
production

Any failed admission, stop condition, or operator kill:
controlled_testing/production -> emergency_disabled -> constructed_dark
```

### 2.1 Constructed-dark

Constructed-dark is already accepted and unchanged. The entrypoint loads the accepted bounded archive bootstrap once. Bootstrap constructs no current-site connection, reads no current-site option, registers no command or hook, and returns:

`blocked / archive_runtime_disabled / flags`

### 2.2 Controlled testing

Controlled testing is not authorized by approval of this proposal. It requires:

- implementation and independent review of the exact A18 allowlist;
- an owner authorization naming the one blog, operators, UTC window, and rollback owner;
- current-site access authorization limited to that window; and
- the A01-A17 preflight evidence.

Controlled testing permits only review/capture parity, `capture_evidence`, and `materialize_ledger` for non-certificate cases. It does not permit packet materialization, verification/finalization, lifecycle retry, reset, downloads, network access, or production fan-out.

### 2.3 Production activation

Production activation requires a later, separate owner authorization after A19 evidence is accepted. It remains blocked while certificate acquisition, packet materialization, verification/finalization, or D16 is unresolved.

## 3. Retained operating assumptions

- Distributed runtime floor: PHP 8.3.
- Required runtimes: PHP 8.3.30 and PHP 8.5.7.
- Supported databases: MySQL 8.0, MySQL 8.4, and MariaDB 10.6.
- Approximately 100 lifecycle reads per write.
- Validation envelope: 100 reads/second and 5 lifecycle writes/second per site.
- One operational tenant per WordPress site/blog.
- Employment/training PII only; PHI and PCI are prohibited.
- Monthly lifecycle API availability target: 99.9%.
- Lifecycle command p99: at most 1,000 ms, excluding asynchronous artifact work.
- Primary RPO: zero for acknowledged event commits.
- Disaster-recovery RPO: at most 15 minutes.
- RTO: at most four hours.
- Up to five production artifact workers per site; controlled testing uses one.
- The Product Owner owns lifecycle acceptance and the monthly error budget. The Site Operations Owner owns deployment, scheduler, storage, secret, alert, rollback, RPO, and RTO evidence.

## 4. Closed failure vocabulary

Activation code must use the accepted C01-C27 nine-field runtime envelope. This proposal adds no runtime result code.

| Condition | Exact runtime tuple |
|---|---|
| load/manifest failure | `blocked / archive_runtime_load_failed / load` |
| flags or mode unavailable | `blocked / archive_runtime_disabled / flags` |
| schema mismatch | `blocked / archive_runtime_schema_mismatch / schema` |
| code-version evidence fails | `blocked / archive_runtime_attestation_failed / attestation` |
| tenant/blog authority fails | `blocked / archive_runtime_tenant_invalid / tenant` |
| source identity or credential preflight fails | `blocked / archive_runtime_source_credentials_invalid / credentials` |
| storage root or capacity gate fails | `blocked / archive_runtime_storage_invalid / storage` |
| cursor key fails | `blocked / archive_runtime_cursor_key_invalid / storage` |
| review/capture parity unavailable | `blocked / archive_runtime_review_capture_parity_unavailable / parity` |
| calculation policy unavailable | `blocked / archive_runtime_calculation_policy_unapproved / calculation` |
| installed handler registry differs | `blocked / archive_runtime_handler_registry_invalid / registry` |
| scheduler/CLI boundary unavailable | `blocked / archive_runtime_wakeup_unavailable / wakeup` |
| D16 unresolved in production | `blocked / archive_runtime_partial_retry_unresolved / activation` |
| controlled-test or production authorization absent | `blocked / archive_runtime_activation_blocked / activation` |
| unknown failure before a claim | `blocked / archive_runtime_load_failed / load` |
| unknown failure after admission/claim | `blocked / archive_runtime_internal_failure / worker` |

Task-level failures retain the accepted P3A/P3B classification. Lease expiry, response loss, process death, task death, and unknown operational failures never create lifecycle facts.

## 5. Numbered activation decisions

### A01 - Controlled-test scope, window, operators, rollback, and stops

**Recommendation.** Use exactly one owner-named, non-network-activated blog. Authorize a 48-hour UTC calendar window containing:

1. up to two supervised hours of manual, one-task worker invocations with the host schedule disabled; then
2. exactly 24 continuous hours of the A11 schedule, only after the manual evidence is accepted.

Concurrency is one worker for that blog. Eligible tasks are only `capture_evidence` and `materialize_ledger`. Test cases must have `certificate_required = false`. At most 25 archive revisions may be requested during the window. No packet, verification/finalization, reset, retry-lifecycle, download, network, or other task is permitted.

The named roles are one Product Owner approver, one Site Operations Owner/rollback operator, and one least-privilege WP-CLI operator. One person may not fill both approver and rollback-operator roles.

Stop immediately on any blocked/dead/lease-lost result, immutable conflict, source drift, unexpected stderr/stdout, missed run, storage threshold breach, database denial outside the approved read/write surface, p99 command breach, or any PHI/PCI observation. Rollback follows A13 and A16.

**Rationale.** One blog and one worker bound blast radius while exercising the real admission, source, fence, event, snapshot, artifact, and scheduler paths.

**Alternatives considered.** Multi-blog canary, five-worker load test, certificate cases, and open-ended soak are rejected for the first controlled window.

**Retained-data effect.** Valid events, snapshots, ledger artifacts, tasks, receipts, and projections created in the window remain immutable. Rollback disables behavior; it does not delete them.

**Failure tuple.** Missing or expired authorization: `blocked / archive_runtime_activation_blocked / activation`.

**Named regressions/evidence.**

- `ACTIVATION-CONTROLLED-ONE-BLOG-ONLY`
- `ACTIVATION-CONTROLLED-WINDOW-BOUND`
- `ACTIVATION-CONTROLLED-ONE-WORKER`
- `ACTIVATION-CONTROLLED-TASK-REGISTRY-CLOSED`
- `ACTIVATION-CONTROLLED-STOP-CONDITIONS`
- `ACTIVATION-CONTROLLED-25-REVISION-CEILING`

**Unresolved environment facts.** Exact blog ID/site ID, UTC window, operator identities, and rollback owner.

**Approval wording.** `Approve A01 as written; this does not authorize a controlled current-site test window.`

### A02 - Representative installed-code attestation

**Recommendation.** Before each admission and again immediately before the first claim, use the accepted non-configurable `GHCA_ACD_Archive_Code_Version_Attestor` to prove:

- WordPress `7.0.2`;
- LearnDash `5.1.6.1`; and
- dashboard plugin `1.2.0`.

The controlled evidence packet records the three exact versions, normalized relative file labels, SHA-256 digests of the three bounded files, pass/fail, and UTC observation time. It must not include absolute paths or file contents. Ordinary options, request/task payloads, filters, globals, environment claims, source rows, or operator input are not version authority.

**Rationale.** This preserves C06 and B02's code-origin trust boundary.

**Alternatives considered.** Plugin options, `get_bloginfo()`, database version strings, and operator assertions are rejected as substitutable configuration.

**Retained-data effect.** None. Evidence packet digests are operational evidence and are not archive facts.

**Failure tuple.** `blocked / archive_runtime_attestation_failed / attestation`.

**Named regressions/evidence.**

- `ACTIVATION-ATTEST-EXACT-TUPLE`
- `ACTIVATION-ATTEST-FILE-DIGEST-EVIDENCE`
- `ACTIVATION-ATTEST-CONFIG-SPOOF-REJECTED`
- `ACTIVATION-ATTEST-RECHECK-BEFORE-CLAIM`

**Unresolved environment facts.** The deployed file digests and installed containment evidence.

**Approval wording.** `Approve A02 as written.`

### A03 - Archive/source endpoint identity and least privilege

**Recommendation.** Preserve C08 and B03:

- archive and source connections must identify the same database endpoint and database name;
- the connections must have different connection IDs and different exact unquoted `CURRENT_USER()` values;
- the source secret is used only to construct the isolated source connection and is never copied into an archive object, log, option, task, or evidence packet;
- source grants are exactly one `USAGE` row plus seven distinct table-level `SELECT` rows, one for each exact B05 source table;
- all eight `SHOW GRANTS` rows must decode to the identical expected source account;
- column-level, schema-wide, wildcard, role-derived, duplicate, and additional grants are rejected;
- the source principal must be denied source mutation/DDL and denied every archive-table read or mutation;
- source credential validation may execute identity/grant/schema preflight queries, but zero B05 evidence-data queries and zero mutations;
- the isolated connection always closes.

The archive connection continues to use the accepted current-blog WordPress connection. This proposal does not narrow ordinary WordPress grants or infer a deployed archive-account grant manifest.

**Rationale.** A separate read-only connection bounds evidence reads without changing WordPress's existing connection ownership.

**Alternatives considered.** Reusing `$wpdb`, cross-database names, broader schema `SELECT`, shared accounts, and configuration-reported identities are rejected.

**Retained-data effect.** None. Credentials and grant output are never retained in archive data.

**Failure tuple.** `blocked / archive_runtime_source_credentials_invalid / credentials`.

**Named regressions/evidence.**

- `ACTIVATION-SOURCE-ARCHIVE-CONNECTIONS-DISTINCT`
- `ACTIVATION-SOURCE-GRANTS-EXACT-EIGHT`
- `ACTIVATION-SOURCE-WRITES-DENIED`
- `ACTIVATION-SOURCE-ARCHIVE-READS-DENIED`
- `ACTIVATION-SOURCE-PREFLIGHT-NO-EVIDENCE-QUERY`
- `ACTIVATION-SOURCE-CONNECTION-CLOSED`
- `ACTIVATION-SOURCE-GRANT-SHAPES-REJECTED`

**Unresolved environment facts.** Exact endpoint identity, database name, archive/source account values, deployed grant rows, and secret delivery/rotation evidence.

**Approval wording.** `Approve A03 as written.`

### A04 - Storage roots, permissions, capacity, and cursor key

**Recommendation.** Require:

- `GHCA_ACD_ARCHIVE_PRIVATE_DIR` to be a pre-existing absolute, non-symlink private root outside and non-overlapping every public root;
- `GHCA_ACD_ARCHIVE_PUBLIC_DOCUMENT_ROOT` to resolve to the deployed public document root;
- Linux root/file modes no broader than `0700`/`0600`; on Windows, an owner-reviewed ACL granting only the service identity and designated backup operator;
- no uploads fallback and no public URL;
- the retained individual certificate, ledger, and packet object ceilings of 16 MiB, 8 MiB, and 64 MiB respectively;
- controlled testing, which authorizes one in-flight 8 MiB ledger and excludes certificates and packets, to require `free_bytes >= 1,090,519,040`: two ledger copies (`16,777,216` bytes) plus a 1 GiB (`1,073,741,824` byte) operational reserve;
- no production aggregate-capacity or certificate-count formula until A07 and A08 approve the certificate and packet contracts;
- a dedicated 64-lowercase-hex cursor HMAC key injected through the accepted deployment constant and absent from output;
- rotation only while both feature flags are disabled, with all old cursors discarded and scans restarted.

No quota cleanup or orphan deletion is added; P3A remains report-only.

**Rationale.** The controlled threshold is derived only from its one approved 8 MiB ledger. Production capacity remains blocked instead of assuming an unapproved certificate count.

**Alternatives considered.** Uploads storage, relative paths, shared HMAC keys, online dual-key rotation, automatic orphan deletion, and silent low-disk continuation are rejected.

**Retained-data effect.** Root/key rotation does not mutate artifacts. Existing committed keys and bytes remain authoritative; active orphan cursors become invalid and restart.

**Failure tuple.** Root/permission/capacity failure: `blocked / archive_runtime_storage_invalid / storage`. Key failure: `blocked / archive_runtime_cursor_key_invalid / storage`.

**Named regressions/evidence.**

- `ACTIVATION-STORAGE-PRIVATE-PUBLIC-DISJOINT`
- `ACTIVATION-STORAGE-PERMISSIONS-CLOSED`
- `ACTIVATION-STORAGE-CONTROLLED-THRESHOLD-EXACT`
- `ACTIVATION-STORAGE-THRESHOLD-EQUALITY-ACCEPTED`
- `ACTIVATION-STORAGE-THRESHOLD-ABOVE-ACCEPTED`
- `ACTIVATION-STORAGE-THRESHOLD-ONE-BYTE-BELOW-REJECTED`
- `ACTIVATION-STORAGE-PRODUCTION-CAPACITY-GATED`
- `ACTIVATION-STORAGE-LOW-DISK-BLOCKED`
- `ACTIVATION-CURSOR-KEY-NONEXPOSURE`
- `ACTIVATION-CURSOR-ROTATION-FLAGS-OFF`
- `ACTIVATION-ORPHAN-REMAINS-REPORT-ONLY`

**Unresolved environment facts.** Exact canonical roots, filesystem/ACL evidence, free space, backup identity, and injected key presence.

**Approval wording.** `Approve A04 as written.`

### A05 - Review-time intake producer

**Recommendation.** Add one future public module review operation and one review-intake application service. The public `GHCA_ACD_Archive_Module` operation performs admission, checkpoints immediately before directly calling its existing private `compose_evidence_source()` method, and injects that freshly composed `GHCA_ACD_Archive_Evidence_Source` plus the existing Unit of Work into the intake service. The private method remains private, is never exposed as a callback, and remains the only C11 composition recipe.

The intake service receives only the injected source, injected Unit of Work, and validated case/cycle/user/policy identity; obtains the exact E07 document; computes the exact E08 fingerprint; and supplies that fingerprint to the existing reviewed/request command flow. It may not construct a source or Unit of Work, or accept a caller-supplied source factory, E07 document, fingerprint, adapter key/version, version tuple, database descriptor, or calculation time.

The intake service checkpoints before and after the consistent read, before and after digesting, and immediately before and after the authoritative Unit-of-Work call. Source-construction checkpoint ownership remains exclusively in the module. A fence/cancellation throwable remains byte-identical after source rollback/close.

**Rationale.** Review and capture must share construction, mapping, normalization, attestation, calculation, and digest authority.

**Alternatives considered.** A second review adapter, controller-computed fingerprint, cached E07 document, and request-supplied fingerprint are rejected.

**Retained-data effect.** The reviewed fingerprint is retained event data. Changing the producer later requires a new approved adapter/normalization version and compatibility decision; retained values are not rewritten.

**Failure tuple.** Missing parity: `blocked / archive_runtime_review_capture_parity_unavailable / parity`. Source failures retain B13 tuples and create no lifecycle event.

**Named regressions/evidence.**

- `ACTIVATION-REVIEW-USES-C11-COMPOSITION`
- `ACTIVATION-REVIEW-CALLER-FINGERPRINT-REJECTED`
- `ACTIVATION-REVIEW-FENCE-PRESERVED`
- `ACTIVATION-REVIEW-NO-CACHE-SUBSTITUTION`
- `ACTIVATION-REVIEW-MODULE-CALLS-PRIVATE-COMPOSER`
- `ACTIVATION-REVIEW-PRIVATE-COMPOSER-NOT-EXPOSED`
- `ACTIVATION-REVIEW-MODULE-OWNS-PRECOMPOSE-CHECKPOINT`
- `ACTIVATION-REVIEW-INTAKE-OWNS-READ-DIGEST-UOW-CHECKPOINTS`

**Unresolved environment facts.** The existing human review entrypoint and capability owner to which the future service will be connected.

**Approval wording.** `Approve A05 as written.`

### A06 - Review/capture E07 and E08 parity

**Recommendation.** For the same immutable capture identity and unchanged source snapshot, review and capture must independently construct source connections and produce byte-identical E07 canonical bytes and the identical E08 digest. Evidence must show the same adapter key/version, code-version tuple, tenant/site/blog descriptor, mapping version, normalization version, calculation policy, policy digest, record IDs/versions, and canonical format.

Disposable-database tests run once without mutation and once with a controlled second-connection mutation after review. The unchanged case must match; the disposable mutation case must produce the accepted `archive_source_drift` lifecycle decision only through the fenced authoritative command, never from the test harness.

The controlled current-site window uses only the isolated read-only source principal, performs no induced source mutation, and proves unchanged parity. Naturally observed current-site drift follows the same accepted fenced `archive_source_drift` path and triggers the A01 stop condition.

**Rationale.** Digest equality without dependency equality could conceal a substituted producer.

**Alternatives considered.** Digest-only comparison and shared in-memory result reuse are rejected.

**Retained-data effect.** No rewrite. A captured snapshot retains its accepted source fingerprint and exact canonical bytes.

**Failure tuple.** Admission absence: `blocked / archive_runtime_review_capture_parity_unavailable / parity`. Authoritative drift retains `archive_source_drift`.

**Named regressions/evidence.**

- `ACTIVATION-PARITY-E07-BYTES-EXACT`
- `ACTIVATION-PARITY-E08-DIGEST-EXACT`
- `ACTIVATION-PARITY-DEPENDENCY-DESCRIPTORS-EXACT`
- `ACTIVATION-PARITY-MUTATION-DRIFT-FENCED`

**Unresolved environment facts.** Representative non-PII current-site record set. The mutation fixture remains disposable-database-only.

**Approval wording.** `Approve A06 as written.`

### A07 - Certificate assignment and acquisition

**Recommendation.** Freeze assignment at the accepted B10 reference contract: the source retains only the validated LearnDash certificate post ID and its deterministic source-record version. Certificate bytes are not source-database evidence.

Do not approve an acquisition implementation in this proposal. The preferred future choice is an owned, in-process, deterministic local generator with a separately approved producer key/version, input manifest, font/assets, PDF profile, memory/time ceilings, and cross-runtime golden vectors. HTTP acquisition, cookies, TLS weakening, and caller-provided URLs remain prohibited.

Controlled testing is limited to cases where the accepted evidence says `certificate_required = false`. Production activation remains blocked until a later certificate decision and implementation are accepted.

**Rationale.** No owned/pinned certificate producer exists in the accepted code; inventing one here would create retained producer semantics and a new security boundary.

**Alternatives considered.** LearnDash HTTP route, same-site cookies, browser capture, external service, and installed LearnDash TCPDF are rejected for activation.

**Retained-data effect.** None now. A later producer key/version, descriptor, and bytes will be retained and immutable.

**Failure tuple.** Required/missing/contradictory certificate mapping remains `invalid / archive_certificate_invalid / certificate_gate`; operational acquisition failure must not invent a lifecycle event.

**Named regressions/evidence.**

- `ACTIVATION-CERTIFICATE-REFERENCE-B10-UNCHANGED`
- `ACTIVATION-CONTROLLED-CERTIFICATE-CASES-EXCLUDED`
- `ACTIVATION-CERTIFICATE-NETWORK-PROHIBITED`
- `ACTIVATION-CERTIFICATE-PRODUCTION-GATE`

**Unresolved environment facts.** Generator ownership, license, producer key/version, rendering inputs, fonts/assets, and golden PDF bytes.

**Approval wording.** `Approve A07 deferral and keep certificate-required controlled tests and production activation blocked.`

### A08 - Packet materialization

**Recommendation.** Do not approve packet materialization yet. The future contract must use only the sealed snapshot, committed certificate artifacts, and committed ledger artifact; use an owned/pinned renderer and licensed immutable fonts/assets; freeze metadata, ordering, pagination, producer key/version, filename/media type, storage-key derivation, byte ceiling, and PHP 8.3/8.5 golden vectors; and perform external work outside database transactions under the live task fence.

FPDI alone is not a renderer, and LearnDash-owned TCPDF is not an approved dependency. Controlled testing must not install or claim `materialize_packet`.

**Rationale.** A renderer choice changes retained bytes and digests and cannot be inferred from installed incidental dependencies.

**Alternatives considered.** LearnDash TCPDF, unpinned system tools, browser printing, and nondeterministic PDF metadata are rejected.

**Retained-data effect.** None now. A later packet descriptor, bytes, and digest become immutable retained contracts.

**Failure tuple.** Deterministic packet invalidity will retain `archive_packet_invalid`; operational renderer failure must retry/dead-letter without lifecycle invention under the accepted taxonomy.

**Named regressions/evidence.**

- `ACTIVATION-PACKET-HANDLER-ABSENT`
- `ACTIVATION-PACKET-SEALED-INPUTS-ONLY`
- `ACTIVATION-PACKET-RENDERER-OWNER-GATE`
- `ACTIVATION-PACKET-GOLDEN-VECTOR-GATE`

**Unresolved environment facts.** Renderer/library, license, fonts/assets, producer key/version, deterministic PDF profile, and golden vectors.

**Approval wording.** `Approve A08 deferral and keep packet materialization and production activation blocked.`

### A09 - Verification and finalization

**Recommendation.** Preserve the technical design: after both materializers exist, one fenced verification handler must re-read authoritative history and immutable objects; verify source fingerprint, stream chain, snapshot digest, item digests/manifest, certificate count/digests, ledger/packet descriptors and bindings, packet PDF structure/readability, predecessor identity, and no conflicting terminal event; then commit the existing atomic verify/finalize command. Completion occurs only after commit or receipt replay.

Do not implement or install this handler until A07 and A08 are accepted. Controlled testing must not finalize.

**Rationale.** Partial verification could finalize an incomplete or drifted archive.

**Alternatives considered.** Separate non-atomic verify/finalize commands, trust in handler output, and verification before immutable commit are rejected.

**Retained-data effect.** Existing `ArchiveVerified` and `ArchiveFinalized` facts remain unchanged. No new event or schema is proposed.

**Failure tuple.** Proven deterministic failure retains `archive_verification_failed`; operational read/open/lease failures create no lifecycle fact.

**Named regressions/evidence.**

- `ACTIVATION-VERIFY-SEALED-AUTHORITY-RELOAD`
- `ACTIVATION-VERIFY-FINALIZE-ATOMIC`
- `ACTIVATION-VERIFY-RECEIPT-REPLAY`
- `ACTIVATION-CONTROLLED-NO-FINALIZATION`

**Unresolved environment facts.** Accepted certificate/packet producers and final verification handler implementation.

**Approval wording.** `Approve A09 as the future verification contract and keep its implementation and production activation blocked.`

### A10 - D16 partial-artifact lifecycle retry

**Recommendation.** Make no retained-data choice in this activation proposal. Preserve the accepted original-task delivery retry and attempt-five recovery, but continue to prohibit installed lifecycle materialization tasks from `ArchiveRetryRequested`.

A separate retained-semantics proposal must choose exactly one D16 model:

1. cross-attempt immutable candidate reuse with revised finalization;
2. an explicit reuse/rebinding event;
3. explicit candidate invalidation/replacement before rematerialization; or
4. cancellation and a replacement revision.

It must include aggregate transitions, events/payloads, task production, finalization binding, compatibility, idempotency, crash/race tests, and migration treatment. Controlled testing may not issue lifecycle retry. Production activation remains blocked.

**Rationale.** Choosing silently would conflict with same-attempt finalization and immutable candidate identity.

**Alternatives considered.** Treating task retry as lifecycle retry or rematerializing over an occupied key is rejected.

**Retained-data effect.** None now. Any later choice is retained-data affecting and requires explicit approval.

**Failure tuple.** Production admission: `blocked / archive_runtime_partial_retry_unresolved / activation`.

**Named regressions/evidence.**

- `ACTIVATION-D16-RETRY-TASK-NOT-INSTALLED`
- `ACTIVATION-D16-CONTROLLED-RETRY-REJECTED`
- `ACTIVATION-D16-PRODUCTION-BLOCKED`
- `ACTIVATION-D16-ORIGINAL-TASK-RECOVERY-PRESERVED`

**Unresolved environment facts.** Owner selection among the four D16 models.

**Approval wording.** `Approve A10 deferral; no D16 model or lifecycle retry is authorized.`

### A11 - Host scheduler ownership and reliability

**Recommendation.** Retain C13:

- one owner-managed host-cron entry per activated blog;
- exact command `wp --url=<canonical-site-url> ghca-acd archive-worker run`;
- one invocation per minute;
- `--url=<canonical-site-url>` is the required WP-CLI bootstrap selector; the archive subcommand accepts no positional or associative arguments;
- one task per invocation;
- controlled concurrency one; production ceiling five per blog;
- hard process timeout 110 seconds;
- alert when no validated invocation is observed for three minutes;
- backlog alert after five consecutive non-idle invocations;
- during controlled testing, the host enforces exactly one active invocation for the blog;
- during production, the host enforces no more than five active invocations for the blog;
- the host limits invocation concurrency only; database leases remain authoritative for task ownership;
- exactly 24 continuous hours of controlled soak with zero missed-run, malformed-output, unexpected-stderr, duplicate-owner, or stale-fence incident.

The Site Operations Owner owns cron, process identity, timeout, stdout validation, stderr isolation, alerts, and emergency disable. WP-Cron and Action Scheduler remain prohibited.

**Rationale.** Host scheduling is observable and independent of request traffic; database fencing remains the concurrency authority.

**Alternatives considered.** WP-Cron, Action Scheduler, web wake-up, one unbounded multisite loop, and host locks as lease authority are rejected.

**Retained-data effect.** None beyond valid task outcomes already governed by fencing.

**Failure tuple.** `blocked / archive_runtime_wakeup_unavailable / wakeup`.

**Named regressions/evidence.**

- `ACTIVATION-SCHEDULER-EXACT-COMMAND`
- `ACTIVATION-SCHEDULER-URL-IS-BOOTSTRAP-SELECTOR`
- `ACTIVATION-SCHEDULER-ONE-MINUTE`
- `ACTIVATION-SCHEDULER-110-SECOND-TIMEOUT`
- `ACTIVATION-SCHEDULER-MISSED-THREE-MINUTES`
- `ACTIVATION-SCHEDULER-BACKLOG-FIVE-RUNS`
- `ACTIVATION-SCHEDULER-24-HOUR-SOAK`
- `ACTIVATION-SCHEDULER-CONTROLLED-ONE-ACTIVE`
- `ACTIVATION-SCHEDULER-PRODUCTION-MAX-FIVE-ACTIVE`
- `ACTIVATION-SCHEDULER-DATABASE-LEASES-AUTHORITATIVE`

**Unresolved environment facts.** Host scheduler, service identity, overlap control, log destination, alert receiver, and measured cron reliability.

**Approval wording.** `Approve A11 as written.`

### A12 - Strict stdout and stderr boundary

**Recommendation.** Preserve C19:

- stdout contains exactly one newline-terminated canonical nine-field JSON object;
- the host validates key order, types, closed tuple, count grammar, and line count before recording it;
- `display_errors=0` and `WP_DEBUG_DISPLAY=false`;
- supported runtime throwables and warnings are caught and only the closed envelope is emitted;
- any malformed/multiple stdout line or unexpected stderr blocks the invocation and creates no lifecycle event;
- raw stderr is never exported to ordinary scheduler/application telemetry;
- the host records only the validated envelope, exit code, scheduler timestamps, and a random non-retained invocation ID;
- separately retained diagnostic stderr requires an owner-controlled restricted incident channel and is not archive operational telemetry.

**Rationale.** Plugin sanitization cannot make arbitrary PHP, WordPress, WP-CLI, or host stderr safe.

**Alternatives considered.** Free-form stdout, combined streams, regex redaction, and ordinary scheduler retention of stderr are rejected.

**Retained-data effect.** None.

**Failure tuple.** Malformed output or unexpected stderr: `blocked / archive_runtime_activation_blocked / activation` at the host boundary; no lifecycle command.

**Named regressions/evidence.**

- `ACTIVATION-STDOUT-EXACT-ONE-LINE`
- `ACTIVATION-STDOUT-CANONICAL-NINE-FIELDS`
- `ACTIVATION-STDOUT-WARNING-CONTAINED`
- `ACTIVATION-STDERR-ORDINARY-LOG-EXCLUDED`
- `ACTIVATION-OUTPUT-RAW-PATH-EXCEPTION-NOT-LEAKED`

**Unresolved environment facts.** Host wrapper implementation, restricted incident channel, and scheduler log policy.

**Approval wording.** `Approve A12 as written.`

### A13 - Feature flags and immediate kill switch

**Recommendation.** The exact activation order for one blog is:

1. the operator first verifies the applicable A11 scheduler/change record, A15 restricted evidence destination, and A17 backup/rollback evidence outside the module; during A01's manual phase the scheduler must remain disabled;
2. while both flags are exactly `0`, call a dedicated non-registering preflight operation that expects both flags off and validates only code-enforceable gates: code attestation, schema, tenant/blog binding, exact off/reset flags, source identity/credentials/grants, storage/key/capacity, review/capture parity availability, calculation policy, installed registry, and the A20 deployment authorization document, without constructing/registering a worker or claiming a task;
3. set `ghca_acd_archive_dual_layer = 1`;
4. set `ghca_acd_archive_enabled = 1`;
5. run the existing live admission, which requires both flags exactly `1`, immediately before registration; and
6. run that same live admission again immediately before each claim.

The off-state preflight is a separate runtime-descriptor/module path; it must not call the existing live `resolve()` contract with false expectations. There is no admission call in the intermediate state where only `dual_layer = 1`.

The module does not inspect or claim to validate host cron configuration, scheduler reliability, the restricted evidence repository, backup/restore evidence, or change-record approval. Those A11/A15/A17 facts are explicit operator prerequisites recorded outside runtime output.

`ghca_acd_archive_reset_enabled` is absent or exactly `0`. Both active flags must be exact direct per-blog options; no filter, network option, cache claim, or environment override is authority.

Emergency disable order is:

1. set `ghca_acd_archive_enabled = 0`;
2. stop/disable host cron;
3. remove the A20 deployment authorization document;
4. set `ghca_acd_archive_dual_layer = 0`;
5. validate constructed-dark output; and
6. leave schema, events, tasks, snapshots, receipts, projections, and artifacts untouched.

Any admission change between composition and claim prevents the claim.

**Rationale.** This orders writes so the broad mode exists before worker enablement and removes worker enablement first during rollback.

**Alternatives considered.** One flag, network flags, cached admission, automatic retry of failed admission, and data deletion rollback are rejected.

**Retained-data effect.** Options change operational state only; retained archive data is immutable.

**Failure tuple.** `blocked / archive_runtime_disabled / flags` or the exact failing gate tuple.

**Named regressions/evidence.**

- `ACTIVATION-FLAGS-ORDER-ENABLE`
- `ACTIVATION-FLAGS-OFF-PREFLIGHT-NONREGISTERING`
- `ACTIVATION-FLAGS-NO-INTERMEDIATE-ADMISSION`
- `ACTIVATION-PREFLIGHT-CODE-ENFORCEABLE-GATES-ONLY`
- `ACTIVATION-PREFLIGHT-HOST-EVIDENCE-OUTSIDE-MODULE`
- `ACTIVATION-MANUAL-PREFLIGHT-SCHEDULER-DISABLED`
- `ACTIVATION-FLAGS-ORDER-DISABLE`
- `ACTIVATION-FLAGS-RECHECK-BEFORE-CLAIM`
- `ACTIVATION-RESET-FLAG-OFF`
- `ACTIVATION-KILL-SWITCH-NO-DATA-MUTATION`

**Unresolved environment facts.** Named operator and change-management evidence for option writes and host-cron control.

**Approval wording.** `Approve A13 as written.`

### A14 - Multisite canary sequence

**Recommendation.** No network activation and no `switch_to_blog()` loop. Each blog has its own tenant, direct options, table prefix, storage capacity evidence, source descriptor, scheduler entry, authorization, evidence packet, and rollback.

Controlled testing activates exactly one owner-named blog. Eventual production ordering is sequential:

1. one controlled canary blog;
2. one separately authorized production canary blog for 24 hours;
3. each remaining blog one at a time only after the preceding blog's evidence is accepted.

No concurrent first activation across blogs.

**Rationale.** Per-blog authority prevents prefix/tenant confusion and bounds failures.

**Alternatives considered.** Network activation, wildcard scheduler, shared tenant option, and parallel rollout are rejected.

**Retained-data effect.** Per-blog immutable data remains isolated.

**Failure tuple.** `blocked / archive_runtime_tenant_invalid / tenant`.

**Named regressions/evidence.**

- `ACTIVATION-MULTISITE-NO-NETWORK-ACTIVATION`
- `ACTIVATION-MULTISITE-NO-SWITCH-LOOP`
- `ACTIVATION-MULTISITE-PER-BLOG-AUTHORITY`
- `ACTIVATION-MULTISITE-SEQUENTIAL-CANARY`

**Unresolved environment facts.** Blog inventory, rollout order, tenant IDs, prefixes, and per-blog owners.

**Approval wording.** `Approve A14 as written.`

### A15 - Observability and sanitized evidence packet

**Recommendation.** Produce one bounded canonical operational packet per blog/window containing only:

- decision/proposal and implementation commit IDs;
- exact runtime/database application versions;
- normalized non-secret endpoint identity digest, not hostname/database/account;
- code-attestation file digests;
- grant-manifest pass/fail and row counts, not grant text;
- storage containment/permission/capacity pass/fail and free-byte bucket, not paths;
- feature state transitions and UTC times;
- validated worker envelope counts and duration percentiles;
- task-state aggregate counts;
- missed-run/backlog/lease-loss/dead/immutable-conflict counts;
- suite/check counts and manifest digest; and
- named approver/operator role identifiers that are non-personal change-record IDs.

Maximum packet size is 64 KiB canonical UTF-8 JSON, maximum 10,000 values, maximum string 512 bytes. It must contain no names, emails, user/course/group IDs, tenant IDs, task/event/archive/artifact IDs, retained payloads, evidence rows, artifact bytes, credentials, SQL, grants, hosts, database names, account names, absolute paths, raw exceptions, stack traces, stdout beyond validated fields, or stderr.

**Rationale.** Activation evidence must prove gates without becoming a second PII or secret store.

**Alternatives considered.** Raw logs, database dumps, screenshots containing identifiers, and heuristic redaction are rejected.

**Retained-data effect.** None; the packet is operational evidence outside archive persistence and follows the owner's restricted release-evidence retention policy.

**Failure tuple.** Unsafe evidence blocks authorization: `blocked / archive_runtime_activation_blocked / activation`.

**Named regressions/evidence.**

- `ACTIVATION-EVIDENCE-CANONICAL-64K`
- `ACTIVATION-EVIDENCE-FIELD-ALLOWLIST`
- `ACTIVATION-EVIDENCE-PII-SECRET-PATH-ABSENT`
- `ACTIVATION-EVIDENCE-RAW-STDERR-ABSENT`
- `ACTIVATION-EVIDENCE-RETAINED-PAYLOAD-ABSENT`

**Unresolved environment facts.** Restricted evidence repository, retention period, approver access, and deletion owner.

**Approval wording.** `Approve A15 as written.`

### A16 - Failure classification, rollback, and recovery criteria

**Recommendation.** Preserve the closed runtime and task mappings. Roll back immediately when:

- any A01 stop condition occurs;
- two consecutive invocations return a blocked result;
- one invocation leaks prohibited output;
- one live lease is concurrently owned twice;
- an immutable row/object changes or overwrite is attempted;
- any source principal mutation/archive-read succeeds;
- source/capture parity fails without an expected controlled mutation;
- p99 lifecycle command exceeds 1,000 ms in the controlled sample;
- the scheduler misses the three-minute threshold; or
- storage falls below A04.

Rollback is A13 emergency disable. Do not cancel, delete, rewrite, mark completed, synthesize `ArchiveFailed`, or infer lifecycle state from task/worker failure. After repair, resume only through a new owner authorization; reclaim and receipt replay use accepted fencing/idempotency.

**Rationale.** Operational failure and lifecycle fact remain separate.

**Alternatives considered.** Automatic re-enable, failure-event synthesis, task deletion, and artifact overwrite are rejected.

**Retained-data effect.** No mutation of retained history. Valid pre-stop commits remain.

**Failure tuple.** Use the exact applicable Section 4 tuple; unknown pre-claim failure is load failure, unknown post-admission worker failure is worker internal failure.

**Named regressions/evidence.**

- `ACTIVATION-ROLLBACK-TRIGGER-CLOSED`
- `ACTIVATION-ROLLBACK-NO-LIFECYCLE-INVENTION`
- `ACTIVATION-ROLLBACK-RECEIPT-REPLAY`
- `ACTIVATION-ROLLBACK-LEASE-FENCE-PRESERVED`
- `ACTIVATION-ROLLBACK-REAUTHORIZATION-REQUIRED`

**Unresolved environment facts.** Incident commander, notification path, and change-record/re-authorization process.

**Approval wording.** `Approve A16 as written.`

### A17 - Retention and immutable rollback guarantees

**Recommendation.** Activation rollback changes only flags, scheduler state, and operational evidence. It must not update/delete event, stream, command, snapshot, artifact, ledger-item, task-history, or projection source rows; delete/quarantine committed objects; reuse storage keys; reset a case; or execute projection rebuild.

Normal fenced task-state updates and projection updates produced by an already committed authoritative event remain the only accepted mutable operational/projection behavior. Backup/restore must preserve database and private object consistency with RPO/RTO evidence before production. Orphan reconciliation remains authenticated, bounded, referenced-object-rechecked, and report-only.

**Rationale.** Disablement is not data rollback.

**Alternatives considered.** Cleanup migrations, task purges, artifact deletion, and event reversal are rejected.

**Retained-data effect.** Explicitly none; all accepted retained data remains immutable.

**Failure tuple.** Any immutable contradiction retains `archive_immutable_conflict` only when positively proven through the accepted fenced path; otherwise block operationally without lifecycle invention.

**Named regressions/evidence.**

- `ACTIVATION-DISABLE-RETAINED-ROWS-UNCHANGED`
- `ACTIVATION-DISABLE-COMMITTED-OBJECTS-UNCHANGED`
- `ACTIVATION-DISABLE-ORPHAN-REPORT-ONLY`
- `ACTIVATION-BACKUP-RESTORE-DATABASE-OBJECT-CONSISTENCY`

**Unresolved environment facts.** Backup product, schedule, restore environment, encryption, retention, and measured RPO/RTO.

**Approval wording.** `Approve A17 as written.`

### A18 - Future controlled-test implementation/test allowlist

**Recommendation.** A later, separately authorized implementation may add or modify only:

**Production**

- `includes/archive/application/class-archive-review-intake.php` (new);
- `includes/archive/class-archive-module.php`;
- `includes/archive/infrastructure/class-wordpress-archive-runtime-descriptor.php`; and
- `includes/archive/bootstrap.php` only for the exact manifest entry/count/digest required by the new review-intake class.

**Tests**

- `tests/archive/bootstrap.php`;
- `tests/archive/test-p3-boundaries.php`;
- `tests/archive/test-p3b3-runtime-composition.php`;
- `tests/archive/test-p3b3-activation-gates.php`;
- `tests/archive/test-p3b3-worker-runtime.php`;
- `tests/archive/test-p3b3-multisite.php`;
- `tests/archive/test-p3b3-activation-contracts.php` (new);
- `tests/archive/test-p3b3-activation-persistence.php` (new);
- `tests/archive/test-p3b3-activation-concurrency.php` (new); and
- `tests/archive/test-all.ps1`.

**Documentation**

- this proposal, only to record owner approval/amendments;
- `docs/superpowers/plans/2026-08-02-dual-layer-archive-slice-1b-p3b3-activation-contracts-traceability.md` (new).

No schema, metadata, entrypoint, existing task/handler, certificate, packet, verify/finalize, D16, controller, REST, download, reset, network, or current-site migration file is allowed. Any need outside this list is a new owner gate.

The future review-intake class is the only new production abstraction: it represents the missing accepted review-time operation and must reuse the existing C11 composition path. No container, factory, registry, or second source adapter is permitted.

**Rationale.** This is the minimum root change that can close review/capture parity and activation admission without broadening business behavior.

**Alternatives considered.** Modifying the entrypoint, adding a service container, adding controllers, or pre-authorizing certificate/packet/D16 files is rejected.

**Retained-data effect.** Review fingerprints produced after activation are retained; no existing retained data changes.

**Failure tuple.** Allowlist/manifest contradiction: `blocked / archive_runtime_load_failed / load`.

**Named regressions/evidence.**

- `ACTIVATION-ALLOWLIST-EXACT`
- `ACTIVATION-BOOTSTRAP-MANIFEST-EXACT`
- `ACTIVATION-ENTRYPOINT-REFERENCE-UNCHANGED`
- `ACTIVATION-RETAINED-P3B3-SUITE-NAMES-EXACT`
- `ACTIVATION-NO-SCHEMA-METADATA-NETWORK`
- `ACTIVATION-NO-CERTIFICATE-PACKET-D16-HANDLER`

**Unresolved environment facts.** Whether the existing human review surface can call the new application service without a new controller; if not, stop for a new surface decision.

**Approval wording.** `Approve A18 as the mechanical allowlist for a later controlled-test implementation slice; no file modification is authorized now.`

### A19 - Evidence required before production activation

**Recommendation.** Production authorization requires one owner-accepted packet per canary blog proving:

1. A02-A06 and A11-A17 pass on deployed representative configuration;
2. the complete PHP 8.3.30/8.5.7 by MySQL 8.0/MySQL 8.4/MariaDB 10.6 disposable matrix passes with exact expected counts;
3. retained kernel, legacy, boundary, digest, lint, `php -n`, whitespace, static, and forbidden-surface suites pass;
4. the two-hour manual controlled phase and 24-hour one-worker soak meet all stop conditions;
5. two-connection lease/source-snapshot races and crash/replay regressions pass;
6. backup/restore evidence meets primary RPO zero, DR RPO at most 15 minutes, and RTO at most four hours;
7. command p99 is at most 1,000 ms and the source read stays within its 2,000 ms through-close budget;
8. no PII/secret/path/raw-error leakage is present;
9. A07 certificate acquisition is separately accepted and implemented;
10. A08 packet materialization is separately accepted and implemented;
11. A09 verification/finalization is separately accepted and implemented;
12. A10 D16 is separately selected, implemented, and compatibility-tested; and
13. a separately authorized production canary plan names the first blog, operators, UTC window, rollback owner, alerts, and evidence owner.

Passing controlled capture/ledger testing alone is insufficient for production.

**Rationale.** The current constructed graph deliberately omits complete lifecycle activation.

**Alternatives considered.** Production with disabled certificate cases, production without finalization, and accepting reported rather than executable evidence are rejected.

**Retained-data effect.** None from approval. Later activated commands retain data under existing contracts.

**Failure tuple.** Any missing gate: `blocked / archive_runtime_activation_blocked / activation`; unresolved D16 specifically uses `blocked / archive_runtime_partial_retry_unresolved / activation`.

**Named regressions/evidence.**

- `ACTIVATION-PRODUCTION-EVIDENCE-COMPLETE`
- `ACTIVATION-PRODUCTION-CONTROLLED-EVIDENCE-INSUFFICIENT`
- `ACTIVATION-PRODUCTION-CERTIFICATE-PACKET-VERIFY-GATED`
- `ACTIVATION-PRODUCTION-D16-GATED`
- `ACTIVATION-PRODUCTION-RPO-RTO-EVIDENCE`

**Unresolved environment facts.** All deployed evidence, future A07-A10 implementation records, backup/restore measurements, and named production canary.

**Approval wording.** `Approve A19 as written; this does not authorize production activation.`

### A20 - Separate owner approvals

**Recommendation.** Require one bounded canonical deployment authorization document plus three non-interchangeable human approvals.

The runtime authority is a deployment-owned regular file identified only by `GHCA_ACD_ARCHIVE_ACTIVATION_AUTHORIZATION_FILE`. The constant is the accepted deployment injection point; its value must be an absolute canonical path. The file must:

- exist outside the public roots, plugin tree, private artifact root, uploads tree, and temporary directories;
- be a regular non-symlink file with no symlinked parent;
- be at most 2,048 bytes, UTF-8 without BOM, one exact `ghca-cjson-1` object, and no trailing bytes;
- be read with a bounded read, closed, and re-read for off-state preflight, live registration admission, and immediately before each claim;
- be writable only by the deployment owner and readable only by that owner and the runtime service identity (`0640` or narrower on POSIX; an equivalent reviewed Windows ACL);
- never be written by the plugin, ordinary site configuration, a request/task payload, a filter, the source/archive database, or an operator command;
- never be copied to output, logs, options, tasks, retained records, or the A15 packet; and
- be removed by the deployment owner during A13 emergency disable.

The document has exactly these keys in this canonical order:

```text
authorization_schema_version
blog_id
change_role_id
end_at_gmt
evidence_sha256
mode
operator_role_id
rollback_role_id
site_id
start_at_gmt
```

The exact value grammar is:

- `authorization_schema_version`: strict integer `1`;
- `blog_id` and `site_id`: strings matching `^[1-9][0-9]{0,19}$`;
- `change_role_id`, `operator_role_id`, and `rollback_role_id`: distinct non-personal change-role identifiers matching `^[A-Z0-9][A-Z0-9._:-]{0,63}$`;
- `mode`: exactly `controlled_testing` or `production`;
- `start_at_gmt` and `end_at_gmt`: exact UTC archive timestamps `YYYY-MM-DDTHH:MM:SS.000000Z`, with `start_at_gmt <= now < end_at_gmt`;
- a controlled-testing window: at most 48 hours and `evidence_sha256 = null`;
- a production window: at most 24 hours and `evidence_sha256` matching `^[a-f0-9]{64}$`.

The runtime compares `site_id`, `blog_id`, and `mode` with the independently resolved current-blog/runtime authority. Missing, extra, reordered, malformed, expired, not-yet-valid, overlong, mismatched, unsafe-file, or noncanonical content fails before registration or claim. The SHA-256 of the exact authorization file is recorded only in the restricted change record and the matching human approval; it is not runtime output.

The three exact human approvals are:

1. Decision approval:

   `Approve P3B3 Activation Decisions A01-A20 as written. This approval authorizes documentation of the contracts only; it does not authorize implementation, current-site access, controlled testing, or production activation.`

2. Controlled current-site authorization, available only after A18 implementation is accepted and all placeholders are supplied:

   `Authorize the P3B3 controlled current-site test for blog {BLOG_ID}, site {SITE_ID}, from {START_UTC} through {END_UTC}, with Product Owner {CHANGE_ROLE_ID}, WP-CLI operator {OPERATOR_ROLE_ID}, rollback owner {ROLLBACK_ROLE_ID}, and canonical deployment authorization SHA-256 {AUTHORIZATION_SHA256}, strictly under A01-A18. No production activation is authorized.`

3. Production authorization, available only after A19 is accepted and A07-A10 are resolved:

   `Authorize P3B3 production activation for blog {BLOG_ID}, site {SITE_ID}, from {START_UTC} through {END_UTC}, with Product Owner {CHANGE_ROLE_ID}, operator {OPERATOR_ROLE_ID}, rollback owner {ROLLBACK_ROLE_ID}, accepted evidence packet digest {EVIDENCE_SHA256}, and canonical deployment authorization SHA-256 {AUTHORIZATION_SHA256}, strictly under A01-A20.`

Approval of one phrase does not imply either later phrase. The human approval and file digest must match the exact deployed document. Authorization expires at its stated UTC end and cannot be reused for another blog or mode.

**Rationale.** Contract approval, site access/testing, and production change authority have different risk and evidence.

**Alternatives considered.** A site/network option, database row, environment claim containing the document, request/task field, filter, unsigned caller array, one blanket approval, implied authorization from a commit, and reusable multisite authorization are rejected.

**Retained-data effect.** None.

**Failure tuple.** Missing, malformed, expired, or blog-mismatched authorization: `blocked / archive_runtime_activation_blocked / activation`.

**Named regressions/evidence.**

- `ACTIVATION-APPROVALS-THREE-SEPARATE`
- `ACTIVATION-AUTHORIZATION-EXACT-BLOG-WINDOW`
- `ACTIVATION-AUTHORIZATION-EXPIRES`
- `ACTIVATION-DECISION-APPROVAL-NO-SITE-AUTHORITY`
- `ACTIVATION-AUTHORIZATION-CANONICAL-DOCUMENT-EXACT`
- `ACTIVATION-AUTHORIZATION-MISSING-EXTRA-REORDERED-REJECTED`
- `ACTIVATION-AUTHORIZATION-NOT-YET-VALID-REJECTED`
- `ACTIVATION-AUTHORIZATION-MODE-SITE-BLOG-MISMATCH-REJECTED`
- `ACTIVATION-AUTHORIZATION-UNSAFE-FILE-REJECTED`
- `ACTIVATION-AUTHORIZATION-NONEXPOSURE`
- `ACTIVATION-AUTHORIZATION-REMOVED-ON-ROLLBACK`
- `ACTIVATION-AUTHORIZATION-DIGEST-BINDS-HUMAN-APPROVAL`

**Unresolved environment facts.** Exact protected file path/ACL, deployment owner, service identity, file digest, and every placeholder in the controlled and production authorization phrases.

**Approval wording.** `Approve A20 as written.`

## 6. Dependency and gate diagram

```text
trusted installed code
    -> code attestor (A02)
protected canonical deployment authorization file
    -> off-state preflight + live admission + pre-claim recheck (A13, A20)
current-blog direct options + schema + tenant
    -> runtime descriptor (A03, A13, A14)
isolated source credential + exact B05 grants
    -> module private C11 evidence-source composer
    -> module public review operation
    -> injected review intake (A05)
    -> E07 bytes + E08 fingerprint parity (A06)
private root + public roots + cursor key
    -> immutable artifact store (A04)
archive database repositories
    -> Unit of Work
    -> task store/worker fence

controlled registry:
    capture_evidence
    materialize_ledger

production blockers:
    certificate acquisition (A07)
    packet materialization (A08)
    verify/finalize (A09)
    D16 lifecycle retry (A10)
```

## 7. Controlled-test regression manifest

The future A18 implementation must execute every named regression in A01-A20 plus:

- all accepted P3A/P3B1/P3B2a/P3B2b/P3B3 suites;
- real two-connection lease and source-snapshot races;
- crash after command commit before task completion;
- crash after immutable object commit before command commit;
- admission change between composition and claim;
- exact authorization-document grammar, trust boundary, expiry, mode/blog binding, non-exposure, and rollback removal;
- exact installed-type claim/reclaim filtering;
- no schema/metadata/entrypoint change;
- no network, certificate, packet, verify/finalize, reset, controller, REST, Action Scheduler, or WP-Cron surface;
- no induced current-site source mutation or source write credential;
- no current-site credential or payload in test output; and
- cleanup containment within disposable roots.

Every negative test must assert the exact exception or runtime envelope, exact reason/code/stage, no false lifecycle event, no unintended database residue, and no filesystem residue outside its isolated root.

## 8. Verification matrix for a future implementation

The future implementation must run:

- PHP 8.3.30 and PHP 8.5.7;
- MySQL 8.0, MySQL 8.4, and MariaDB 10.6;
- every permanent `tests/archive/test-all.ps1` cell;
- kernel, legacy, boundaries, digests, focused suites under `php -n`, and lint;
- `git diff --check`;
- exact changed-file/allowlist and bootstrap-manifest checks; and
- forbidden scans for runtime surfaces outside A18, current-site bootstrap/config loading, credentials, network, schema change, debug output, raw errors, and hardcoded keys.

PHP 8.4 is optional and must be reported unavailable while no real CLI exists.

## 9. Explicit unresolved owner gates

Approval of A01-A20 still leaves:

1. A18 implementation authorization and formal acceptance;
2. exact controlled blog/window/operator/rollback facts;
3. explicit current-site controlled-test authorization;
4. deployed code, endpoint, grant, root, ACL, capacity, key, scheduler, alert, and backup evidence;
5. owned certificate producer contract and implementation;
6. owned packet renderer contract and implementation;
7. verification/finalization implementation;
8. an owner-selected D16 retained lifecycle model and compatibility plan;
9. accepted sanitized controlled evidence; and
10. separate production authorization per blog.

No unresolved fact may be supplied by ordinary site configuration when the accepted contract requires code, deployment-secret, filesystem, database-identity, scheduler, or owner authority.

## 10. Proposal-only repository evidence

- Expected branch: `feature/dual-layer-archive-slice-1b-p3b3-activation-contracts`.
- Expected HEAD: `3f1682678d8b4790990d4d74728de61ffc884512`.
- Accepted P3B3 constructed-dark commit and traceability were present before editing.
- The plugin entrypoint contained only the accepted single archive bootstrap reference.
- Zero staged or tracked changes existed before this proposal.
- Only the pre-existing untracked `.claude/` tree existed.
- This proposal is the only authorized change.
- During proposal creation and revision, no current-site database, WordPress bootstrap, Docker, credential, network, production/test/schema/metadata/entrypoint file, activation, stage, commit, push, pull, merge, rebase, or remote was accessed or changed.
- After formal approval, the owner separately authorized staging, committing, and pushing only this proposal and creating an implementation-empty stacked branch.

## 11. Formal owner approval record

The owner formally approved A01-A20 as written. The approval is documentation-only and grants no implementation, current-site, controlled-testing, or production authority.

The exact recorded approval is:

**Approve P3B3 Activation Decisions A01-A20 as written. This approval authorizes documentation of the contracts only; it does not authorize implementation, current-site access, controlled testing, or production activation.**

Status: activation-contract decisions formally approved for documentation only. A separate explicit authorization remains required before implementing the A18 allowlist, accessing the current site, conducting controlled testing, or activating production behavior.
