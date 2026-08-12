# Dual-Layer Archive Certificate Acquisition Strategy Decisions Proposal

**Date:** 2026-08-11

**Status:** Formally approved for documentation-only continued deferral on 2026-08-11; neither strategy is selected and implementation remains blocked

**Branch:** `feature/dual-layer-archive-slice-1b-certificate-acquisition-strategy-proposal`

**Parent:** `38714b396d26430487d746fd85330009c1ac6c15`

**Scope:** strategy evidence and owner decisions only; no implementation authorization

## 1. Purpose and binding boundary

This record evaluates the two acquisition strategies left open by formally approved Decisions CA01-CA20. It does not amend those decisions. It chooses neither strategy because repository evidence does not prove that either has a complete authority, provenance, determinism, validation, licensing, resource, and recovery contract.

Approval of CS01-CS20 would approve continued deferral and the stated evidence requirements only. It would not authorize production or test code, a collector signature, a producer/source literal, E07/E08 changes, an artifact-ID formula, a dependency, a network route, current-site testing, implementation, staging, commit, push, activation, or deployment.

### 1.1 Preflight evidence

| Check | Result |
|---|---|
| Branch | exact expected strategy-proposal branch |
| HEAD | `38714b396d26430487d746fd85330009c1ac6c15` |
| Staged/tracked state | zero staged files; zero tracked modifications |
| Authorized untracked bystanders | `.claude/` and the audit-packet proposal only; neither read nor modified |
| Accepted CA01-CA20 record | committed at current HEAD |
| Entrypoint | exactly one accepted archive bootstrap reference and no other archive reference |
| PHP 8.3.30 baseline | boundaries 18/18; digests 9/9 |
| PHP 8.5.7 baseline | boundaries 18/18; digests 9/9 |
| PHP 8.4 | unavailable; no CLI found |
| Database, Docker, generator, network, current site | not accessed |

## 2. Authority hierarchy and evidence inventory

The authority order for this proposal is:

1. formally approved CA01-CA20 certificate-acquisition decisions;
2. accepted event-sourcing technical design and P3B2a/P3B2b/P3B3 traceability;
3. accepted archive production contracts and constructed-dark composition code;
4. installed LearnDash, TCPDF, Certificate Builder, mPDF, FPDI, and license files as read-only feasibility evidence; and
5. explicit owner evidence supplied in a later checkpoint.

Installed code is evidence of what is present, not authority to use it as an archive producer. Version headers, ordinary configuration, source rows, task payloads, filters, globals, and environment claims are not code attestation. The installed LearnDash entrypoint contains a locally marked customization block, so its version header alone cannot establish a pristine or approved producer code identity. No secret or customization value is part of this proposal.

### 2.1 Confirmed repository facts

| Evidence | Confirmed fact | Consequence |
|---|---|---|
| Approved CA01-CA20 record | No strategy selected; stream-only collector; coordinator-owned metadata; deterministic recovery; exact event-free local-failure tuples | This proposal cannot weaken or bypass those constraints |
| Technical design Sections 10.3, 11.4, and 12 | Required certificate bytes are sealed evidence; source read closes before file work; snapshot/descriptors/event commit atomically | Certificate acquisition stays inside fenced `capture_evidence` |
| P3B2b source and traceability | B10 retains only `certificate_post_id` and `source_record_version`; no certificate content or acquisition | Current E07/E08 cannot prove template or upstream-byte identity |
| Evidence validator/preparer | Required certificates fail closed; certificate-free snapshots retain empty evidence assets | No implemented certificate path exists |
| Worker coordinator | Capture uses live-lease fencing, outcome recovery, prepared-result validation, and UoW submission | Any future path must enter through the existing fenced flow |
| Private artifact store | Bounded private staging, SHA-256 read-back, immutable commit, non-overwrite, PDF structure checks | Storage exists, but semantic PDF validation does not |
| Archive module | Installed handler registry contains capture and ledger only; certificate acquisition remains absent | Runtime stays constructed-dark for certificates |

### 2.2 Installed-generator facts

| Source | Confirmed behavior | Strategy impact |
|---|---|---|
| LearnDash `ld-certificates.php` | Uses current user, nonces, request state, live settings, actions, and user switching | Not a pure server-side archival producer |
| LearnDash `ld-convert-post-pdf.php` | Reads request fields, live post content, site/upload URLs, filters/actions, mutable settings, and browser-oriented output parameters | Inputs are not closed or immutable |
| Installed TCPDF | Initializes creation time with `time()` and has random-seed paths | Byte identity is not deterministic as configured |
| Certificate Builder controller/PDF code | Reads live post blocks/current user, applies filters, and streams browser output | Not isolated from mutable WordPress state |
| Certificate Builder PDF content/I/O | Uses attachment URLs, `uniqid()`, theme/plugin state, and WordPress uploads paths | Violates deterministic/private-only requirements |
| Installed mPDF | Emits current-time metadata/document IDs and uses random temporary names in reachable library code | Byte determinism is unproven and default behavior is unsuitable |
| Local license files | LearnDash includes GPLv2 text; TCPDF includes LGPLv3-or-later text; bundled mPDF includes GPLv2 text; FPDI includes MIT text | Component notices exist, but archive-owned redistribution, modification, fonts/assets, and combined-work obligations are unresolved |
| Repository search | No archive-owned certificate producer, immutable upstream-object authority, acquisition credential model, or approved semantic PDF parser exists | Neither strategy is implementable now |

### 2.3 Facts versus inferences

Confirmed source facts are the concrete calls, paths, metadata behavior, absence of archive components, and local license texts above. It is an inference that an owned deterministic producer could eventually be safer than network acquisition; that inference does not select Strategy A. It is not established that existing commercial-plugin code, templates, fonts, or assets may be copied into an archive-owned distribution. It is not established that any upstream certificate object exists, is immutable, or exposes an authenticated digest.

## 3. Executive strategy determination

**Recommendation: continue deferral.**

Strategy A is not selectable because no archive-owned producer or immutable rendering package exists, installed generators are mutable and nondeterministic, no bounded semantic validator is approved, resource ceilings are unmeasured, and redistribution/supply-chain obligations are unresolved.

Strategy B is not selectable because no real upstream immutable-object authority, stable version/digest contract, authenticated transport, credential source, tenant/course binding, or revocation/replacement model exists. A hypothetical service is not evidence.

Strategy A remains a research direction only because it could avoid a new network and credential boundary if an owned, redistributable, immutable stack is later defined and proven byte-identical. That directional observation is not a selection, approval, implementation envelope, or permission to reuse the installed generators.

## 4. Strategy comparison

| Criterion | Strategy A: owned deterministic local producer | Strategy B: authenticated immutable upstream object |
|---|---|---|
| Authority source | No archive-owned producer exists | No authoritative upstream source exists |
| Deterministic bytes | Installed TCPDF/mPDF defaults fail or do not prove determinism | Could consume stable bytes, but no object/version/digest contract exists |
| Provenance completeness | No closed template/font/CSS/image/locale/render package | No issuer/source/version/byte provenance exists |
| Recovery compatibility | Compatible only after deterministic expected bytes/digest can be derived | Compatible only after authoritative byte count/digest can be authenticated before orphan inspection |
| Required network access | None in the target design | Likely required, but no network route is authorized or evidenced |
| Mutable WordPress dependence | Installed generators depend on mutable WordPress state; an owned producer must not | Upstream must not render from mutable live HTML during acquisition |
| Licensing/redistribution | Component notices found; whole-stack and asset rights unresolved | Upstream service/object rights and retention rights unresolved |
| Parser requirement | Bounded semantic parser required | Same parser required; upstream status does not make PDF active content safe |
| Resource measurement | Not performed and prohibited in this checkpoint | Not performed; transport and validation ceilings unresolved |
| Schema impact | Likely versioned E07/E08 provenance extension; exact impact unresolved | Likely versioned authoritative-object descriptor; exact impact unresolved |
| Primary security risks | Template injection, font/asset substitution, parser differential, dependency compromise | SSRF, DNS/redirect abuse, credential leakage, issuer spoofing, revocation ambiguity |
| Current evidence | Mutable installed generators and component license files only | No concrete authority, object, endpoint, or credential contract |
| Remaining blockers | Producer/package, attestation, parser, licensing, measurements, versioning, golden vectors | Real authority, immutable version/digest, transport, credentials, revocation, parser, versioning |
| Recommendation | Not selectable; research direction only | Not selectable; continued deferral |

## 5. Security analysis common to both strategies

- **Template injection:** local templates must be canonical immutable bytes with a closed grammar; upstream objects do not authorize mutable HTML rendering during acquisition.
- **Font/asset substitution:** every local font/image/CSS asset needs an immutable identity and digest; file paths and ordinary options are not authority.
- **Traversal and symlinks:** only the validated private-root artifact store and invocation-owned contained temporary boundary may be used.
- **External URLs:** local production must reject them; Strategy B needs a separately approved transport contract before any URL exists.
- **Active PDF content and pages:** a bounded semantic parser must reject JavaScript, actions, launch behavior, attachments, embedded files, rich media, XFA, `AcroForm`, external references, encryption, malformed streams, and trailing payloads; it must also prove `page_count >= 1` and enforce the eventual owner-approved CS15 maximum page ceiling.
- **Metadata nondeterminism:** ambient timestamps, locale, timezone, random IDs, filesystem mtimes, and environment paths cannot affect local bytes.
- **Parser differentials:** one pinned parser/version and adversarial corpus must establish the accepted interpretation; generator acceptance is not parser evidence.
- **Dependency compromise:** producer, parser, fonts, templates, assets, and transitive dependencies require code/package manifests and non-configurable attestation.
- **Tenant/course confusion:** artifact identity, role, expected bytes/digest, and source provenance must bind tenant, stream, archive, task/build, and canonical course.
- **Credential leakage:** Strategy B has no approved credential path; future secrets may not enter task rows, events, receipts, paths, or ordinary logs.
- **Cross-tenant reuse:** digest equality alone never authorizes reuse across a different tenant/course identity.
- **Collector metadata:** the collector returns only a stream; coordinator and validator own all authoritative metadata.
- **Partial streams/timeouts:** only explicitly enumerated transient interruption is retryable; cleanup and fencing remain mandatory.
- **Orphan/collision confusion:** current parity and expected digest precede open/reuse; immutable mismatch is never overwritten.
- **Licensing:** a license filename is not legal approval for the proposed combined distribution, modifications, fonts, templates, or customer deployment.

## 6. Decision summary

| ID | Status | Proposed decision |
|---|---|---|
| CS01 | Proposed | Proposal-only scope; no implementation |
| CS02 | Proposed | Closed evidence hierarchy; facts separated from inference |
| CS03 | Proposed | All safety criteria are mandatory; no compensating-score selection |
| CS04 | Blocked | Strategy A is not currently feasible |
| CS05 | Blocked | Strategy B is not currently feasible |
| CS06 | Proposed | Continue deferral; select no strategy |
| CS07 | Blocked | Producer/source authority literals remain unresolved |
| CS08 | Blocked | Provenance package/object descriptor remains unresolved |
| CS09 | Blocked | E07/E08 versioning impact requires a later compatibility decision |
| CS10 | Proposed constraint | Current schema requires deterministic or authenticated immutable expected bytes |
| CS11 | Blocked | Exact strategy-specific input envelope remains unresolved |
| CS12 | Proposed constraint | Course-specific identity/binding preserved; exact formula blocked |
| CS13 | Proposed constraint | Collector remains stream-only and non-authoritative |
| CS14 | Blocked | No semantic PDF parser is approved |
| CS15 | Blocked | No measured resource ceilings exist |
| CS16 | Blocked | Licensing and supply-chain attestation incomplete |
| CS17 | Proposed constraint | Preserve exact closed event-safe failure tuples |
| CS18 | Proposed constraint | Preserve fencing and deterministic recovery order |
| CS19 | Blocked | Implementation allowlist cannot be frozen honestly |
| CS20 | Proposed | Keep all remaining gates and downstream deferrals closed |

## 7. Proposed decisions CS01-CS20

### CS01 - Proposal scope and non-goals

**Status.** Proposed for documentation approval only.

**Exact proposed decision.** Approve only the strategy evaluation, continued deferral, and future evidence gates in CS01-CS20. Do not authorize a producer, upstream authority, collector implementation, parser, dependency, schema/version change, test implementation, runtime wiring, or site access.

**Evidence and rationale.** CA01-CA20 require a separate strategy choice before implementation. Neither strategy has the evidence required for that choice.

**Security and correctness consequences.** Constructed-dark behavior and every retained contract remain unchanged.

**Explicit exclusions.** Production/test code, generator execution, database/Docker/network/current-site access, activation, deployment, and downstream slices.

**Remaining owner or implementation gates.** All gates in CS04-CS20.

**Named regression requirements.** `CERT-STRATEGY-BOUNDARY-PROPOSAL-ONLY`; `CERT-STRATEGY-BOUNDARY-NO-IMPLEMENTATION`; `CERT-STRATEGY-BOUNDARY-CONSTRUCTED-DARK-UNCHANGED`.

**Exact owner-approval wording.** `Approve CS01 as written: this checkpoint documents strategy evidence and deferral only and authorizes no implementation or runtime behavior.`

### CS02 - Evidence inventory and authority hierarchy

**Status.** Proposed.

**Exact proposed decision.** Use the authority hierarchy in Section 2. Treat repository source as evidence, accepted records as contract authority, and future owner evidence as necessary for unresolved facts. Version strings and ordinary configuration never substitute for code/package attestation.

**Evidence and rationale.** Installed generators and local license files prove presence and behavior but not archival approval, pristine provenance, or redistribution rights.

**Security and correctness consequences.** Prevents locally modified or substituted code from becoming trusted through a matching version label.

**Explicit exclusions.** No inference from configuration, source data, request/task fields, globals, or environment claims.

**Remaining owner or implementation gates.** Exact code/package manifests and independent attestation source.

**Named regression requirements.** `CERT-STRATEGY-EVIDENCE-LOCAL-SOURCE-CITED`; `CERT-STRATEGY-EVIDENCE-FACT-INFERENCE-SEPARATED`; `CERT-STRATEGY-EVIDENCE-VERSION-NOT-ATTESTATION`.

**Exact owner-approval wording.** `Approve CS02 as written: accepted records govern, local source supplies bounded evidence, and version or configuration claims are not attestation.`

### CS03 - Strategy evaluation criteria

**Status.** Proposed.

**Exact proposed decision.** A strategy is selectable only if it simultaneously proves authority, provenance completeness, deterministic/authenticated expected bytes, CA recovery compatibility, semantic PDF validation, bounded resources, licensing, supply-chain attestation, tenant/course binding, privacy, and PHP/database compatibility without weakening frozen contracts. No weighted score or preferred-direction label may waive a failed criterion.

**Evidence and rationale.** Every criterion protects a separate retained-evidence or trust boundary. Compensating controls cannot make an unknown byte source authoritative.

**Security and correctness consequences.** Selection becomes evidence-based and fail-closed.

**Explicit exclusions.** No selection for schedule convenience or implementation momentum.

**Remaining owner or implementation gates.** A complete strategy evidence package satisfying every criterion.

**Named regression requirements.** `CERT-STRATEGY-CRITERIA-ALL-MANDATORY`; `CERT-STRATEGY-CRITERIA-NO-PREFERENCE-BYPASS`; `CERT-STRATEGY-CRITERIA-FROZEN-CA-COMPATIBLE`.

**Exact owner-approval wording.** `Approve CS03 as written: every strategy criterion is mandatory and no preference or delivery pressure may waive one.`

### CS04 - Deterministic local-producer feasibility

**Status.** Implementation-blocked; Strategy A not selected.

**Exact proposed decision.** Do not select installed LearnDash TCPDF or Certificate Builder/mPDF. Do not claim an owned deterministic producer exists. Strategy A may return for decision only with an archive-owned, redistributable, immutable rendering package that eliminates live WordPress reads, hooks, uploads/URLs, ambient time, random values, and non-private storage and proves byte identity across approved runtimes.

**Evidence and rationale.** Installed code performs mutable reads and callbacks and emits time/random-dependent behavior. No archive-owned producer/package exists.

**Security and correctness consequences.** Avoids sealing environment-dependent bytes as immutable evidence.

**Explicit exclusions.** Wrapping, subclassing, or calling installed generators is not approved; patching vendor files is not approved.

**Remaining owner or implementation gates.** Concrete owned producer, immutable package, deterministic vectors, parser, licensing, and measurements.

**Named regression requirements.** `CERT-STRATEGY-LOCAL-INSTALLED-GENERATORS-REJECTED`; `CERT-STRATEGY-LOCAL-NO-OWNED-PRODUCER`; `CERT-STRATEGY-LOCAL-NO-IMMUTABLE-PACKAGE`; `CERT-STRATEGY-LOCAL-NONDETERMINISM-DETECTED`; `CERT-STRATEGY-LOCAL-NO-AMBIENT-STATE`.

**Exact owner-approval wording.** `Approve CS04 as written: Strategy A remains unselected until a concrete archive-owned immutable and byte-deterministic producer package is proven.`

### CS05 - Immutable upstream-object feasibility

**Status.** Implementation-blocked; Strategy B not selected.

**Exact proposed decision.** Do not select Strategy B without a real authoritative issuer/source and immutable object/version contract that authenticates tenant/course/reference binding, byte count, SHA-256, acquisition identity, replay, retention, replacement, and revocation before any transport is designed or used.

**Evidence and rationale.** No such object source, endpoint, credential model, or authority exists in the repository or accepted evidence.

**Security and correctness consequences.** Prevents a hypothetical endpoint or mutable object from becoming archive authority.

**Explicit exclusions.** No URL, HTTP client, credential, DNS/redirect rule, same-site route, cookie, nonce, or object-store assumption is approved.

**Remaining owner or implementation gates.** Concrete authority documentation and a separate transport/security proposal.

**Named regression requirements.** `CERT-STRATEGY-UPSTREAM-NO-AUTHORITY`; `CERT-STRATEGY-UPSTREAM-NO-TRANSPORT`; `CERT-STRATEGY-UPSTREAM-NO-CREDENTIAL-MODEL`; `CERT-STRATEGY-UPSTREAM-HYPOTHETICAL-REJECTED`.

**Exact owner-approval wording.** `Approve CS05 as written: Strategy B remains unselected until a real authenticated immutable upstream authority and separately approved transport model exist.`

### CS06 - Selected strategy or continued deferral

**Status.** Proposed.

**Exact proposed decision.** Select continued deferral. Select neither Strategy A nor Strategy B. A later proposal may select exactly one strategy only after its complete evidence package exists.

**Evidence and rationale.** Both comparison columns contain implementation-blocking unknowns. Choosing either would invent contracts.

**Security and correctness consequences.** Certificate-required capture remains fail-closed and production activation remains blocked.

**Explicit exclusions.** No mixed strategy, fallback from one strategy to the other, or runtime strategy switch.

**Remaining owner or implementation gates.** One complete selectable strategy and explicit owner amendment.

**Named regression requirements.** `CERT-STRATEGY-DECISION-CONTINUED-DEFERRAL`; `CERT-STRATEGY-DECISION-NONE-SELECTED`; `CERT-STRATEGY-DECISION-NO-MIXED-FALLBACK`; `CERT-STRATEGY-DECISION-NO-ALLOWLIST-AUTHORIZED`.

**Exact owner-approval wording.** `Approve CS06 as written: continue deferral, select neither strategy, and require a later evidence-complete owner decision selecting exactly one mode.`

### CS07 - Exact producer or source authority

**Status.** Requirements defined; exact authority blocked.

**Exact proposed decision.** A future Strategy A selection must freeze producer key/version, complete code-manifest digest, package digest, build/release provenance, and non-configurable installed-code attestation. A future Strategy B selection must freeze issuer/source key/version, immutable object/version identity, authenticated byte-count/digest authority, tenant/course binding, and non-configurable client attestation. Exact literals are not approved here.

**Evidence and rationale.** Current version headers and B10 references do not identify producer code, template bytes, or upstream object bytes.

**Security and correctness consequences.** Blocks code/package/source substitution.

**Explicit exclusions.** Options, environment variables alone, task payloads, source rows, filters, or globals cannot assert authority.

**Remaining owner or implementation gates.** Exact selected-strategy descriptor and attestation source.

**Named regression requirements.** `CERT-STRATEGY-AUTHORITY-CODE-ATTESTATION-REQUIRED`; `CERT-STRATEGY-AUTHORITY-ORDINARY-CONFIG-REJECTED`; `CERT-STRATEGY-AUTHORITY-UPSTREAM-ISSUER-REQUIRED`; `CERT-STRATEGY-AUTHORITY-LITERALS-DEFERRED`.

**Exact owner-approval wording.** `Approve CS07's authority requirements while deferring every producer, source, version, digest, and attestation literal until one strategy is selected.`

### CS08 - Immutable provenance package or upstream descriptor

**Status.** Requirements defined; exact provenance blocked.

**Exact proposed decision.** Strategy A requires canonical immutable template/content, CSS, page/locale/formatting rules, font files, images/assets, rendering configuration, metadata rules, producer/parser dependencies, per-item digests, and one package manifest digest. Strategy B requires authenticated issuer/object/version, byte count/digest, acquisition provenance, course/reference binding, retention, replacement, and revocation. These are alternative closed descriptors, never one mixed envelope.

**Evidence and rationale.** B10 contains only certificate post ID and source-record version. Installed rendering inputs exceed that contract.

**Security and correctness consequences.** Detects template, font, asset, package, issuer, and object substitution.

**Explicit exclusions.** External URLs, host paths, mutable options, unpinned fonts, ambient locale, and inferred upstream semantics.

**Remaining owner or implementation gates.** Exact selected descriptor, canonicalization/versioning, and independent vectors.

**Named regression requirements.** `CERT-STRATEGY-PROVENANCE-CLOSED-PACKAGE`; `CERT-STRATEGY-PROVENANCE-FONT-SUBSTITUTION`; `CERT-STRATEGY-PROVENANCE-ASSET-SUBSTITUTION`; `CERT-STRATEGY-PROVENANCE-NO-EXTERNAL-URL`; `CERT-STRATEGY-PROVENANCE-UPSTREAM-OBJECT-CLOSED`; `CERT-STRATEGY-PROVENANCE-NO-MIXED-DESCRIPTOR`.

**Exact owner-approval wording.** `Approve CS08's mutually exclusive provenance requirements while keeping both exact descriptor schemas blocked.`

### CS09 - E07/E08 parity and versioning impact

**Status.** Compatibility decision blocked.

**Exact proposed decision.** Do not change E07/E08 v1. A selected strategy must later show how review and capture bind identical acquisition provenance using one versioned normalized document and fingerprint domain. Any changed subset receives a new version, independent golden vectors, retained-v1 read support, and explicit migration/no-rewrite policy.

**Evidence and rationale.** Existing E07/E08 binds B10 assignment identity but not local render inputs or upstream object bytes.

**Security and correctness consequences.** Prevents review/capture drift and reinterpretation of retained evidence.

**Explicit exclusions.** No silent v1 semantic expansion, historical rewrite, or archive-generated artifact digest inside the pre-capture reviewed fingerprint.

**Remaining owner or implementation gates.** Selected-strategy normalized document, digest domain, version, vectors, and compatibility plan.

**Named regression requirements.** `CERT-STRATEGY-PARITY-E07-E08-IMPACT-GATED`; `CERT-STRATEGY-PARITY-REVIEW-CAPTURE-ACQUISITION`; `CERT-STRATEGY-PARITY-V1-REMAINS-READABLE`; `CERT-STRATEGY-PARITY-POSITIVE-DRIFT-EXISTING-ROUTE`; `CERT-STRATEGY-PARITY-NO-HISTORICAL-REWRITE`.

**Exact owner-approval wording.** `Approve CS09 as written: leave E07/E08 v1 unchanged and require a separate versioned compatibility decision for selected-strategy provenance parity.`

### CS10 - Byte determinism or authoritative-byte contract

**Status.** Common constraint proposed; exact byte contract blocked.

**Exact proposed decision.** Under the current schema, Strategy A must produce byte-identical PDF bytes from identical authoritative inputs across PHP 8.3.30 and 8.5.7. Strategy B must authenticate immutable expected bytes, byte count, and SHA-256 from the approved upstream authority. In both cases current source/provenance parity and expected digest derivation precede orphan open/reuse. Nondeterminism requires a separately approved durable candidate journal/schema and is excluded.

**Evidence and rationale.** Blob-first recovery cannot safely identify an unreferenced committed candidate without independently expected bytes and digest.

**Security and correctness consequences.** Prevents first-object-wins recovery and silent byte replacement.

**Explicit exclusions.** No ambient timestamp/random metadata, digest learned only from the orphan, last-write-wins, or new artifact identity per retry.

**Remaining owner or implementation gates.** Exact bytes or authenticated object fixtures and independent vectors.

**Named regression requirements.** `CERT-STRATEGY-BYTES-CROSS-RUNTIME-GOLDEN-REQUIRED`; `CERT-STRATEGY-BYTES-EXPECTED-DIGEST-BEFORE-OPEN`; `CERT-STRATEGY-BYTES-NONDETERMINISTIC-JOURNAL-REQUIRED`; `CERT-STRATEGY-BYTES-UPSTREAM-AUTH-DIGEST`; `CERT-STRATEGY-BYTES-FIRST-OBJECT-WINS-REJECTED`.

**Exact owner-approval wording.** `Approve CS10's current-schema byte contract: deterministic local bytes or authenticated immutable upstream bytes, with expected digest derived before orphan inspection and no nondeterministic fallback.`

### CS11 - Strategy-specific input and envelope grammar

**Status.** Exact grammar blocked.

**Exact proposed decision.** Do not freeze a collector method or input schema until one strategy is selected. The later envelope must be exact, ordered, bounded, strategy-specific, derived from authoritative evidence/attestation, and contain no fields from the unselected strategy. The checkpoint remains a separate callable, never serialized input.

**Evidence and rationale.** Local production needs package inputs; upstream acquisition needs authoritative object/acquisition inputs. A common exact envelope would either omit authority or mix trust models.

**Security and correctness consequences.** Prevents field smuggling, ambient input, and strategy confusion.

**Explicit exclusions.** Arbitrary arrays, URLs/paths in local input, cookies/nonces, live WordPress objects, clocks, randomness, globals, options, and mixed fallback fields.

**Remaining owner or implementation gates.** Exact selected-strategy method signature and closed field grammar.

**Named regression requirements.** `CERT-STRATEGY-INPUT-ENVELOPE-BLOCKED`; `CERT-STRATEGY-INPUT-NO-MIXED-STRATEGY`; `CERT-STRATEGY-INPUT-NO-AMBIENT-VALUES`; `CERT-STRATEGY-INPUT-EXACT-FIELDS-FUTURE`; `CERT-STRATEGY-INPUT-CHECKPOINT-NOT-SERIALIZED`.

**Exact owner-approval wording.** `Approve CS11 as written: no collector method or input envelope is authorized until one strategy supplies an exact closed grammar.`

### CS12 - Artifact identity, course binding, and dedupe identity

**Status.** Common binding constraints proposed; exact identity formula blocked.

**Exact proposed decision.** Preserve one distinct course-specific asset, role key, and artifact ID per certificate-required canonical course. Shared B10 references across courses remain allowed. Future identity and dedupe must bind tenant, stream, archive, causal task/build, canonical course, selected source/producer version, and selected provenance version. Exact formula/domain, filename, storage key, and source identifier remain unapproved.

**Evidence and rationale.** Reference equality does not make two course evidence roles identical. Cross-tenant or cross-course digest equality is not reuse authority.

**Security and correctness consequences.** Prevents tenant/course confusion and collision-based substitution.

**Explicit exclusions.** No email/name/title in identity, random per-retry ID, cross-tenant reuse, or blanket duplicate-reference rejection.

**Remaining owner or implementation gates.** Exact selected-strategy identity domain and independent literal vectors.

**Named regression requirements.** `CERT-STRATEGY-IDENTITY-COURSE-SPECIFIC`; `CERT-STRATEGY-IDENTITY-SHARED-REFERENCE-ALLOWED`; `CERT-STRATEGY-IDENTITY-CROSS-TENANT-REUSE-REJECTED`; `CERT-STRATEGY-IDENTITY-DOMAIN-GOLDEN-BLOCKED`; `CERT-STRATEGY-IDENTITY-RETAINED-V1-UNCHANGED`.

**Exact owner-approval wording.** `Approve CS12's course-specific and tenant-bound identity constraints while deferring every new identity, key, filename, source, and dedupe literal.`

### CS13 - Stream-only collector boundary

**Status.** Common constraint proposed.

**Exact proposed decision.** Preserve the CA04 boundary: the collector returns only one open bounded readable binary stream. It returns no page count, producer/source identity, digest, descriptor, artifact identity, path, URL, disposition, or result object. The trusted validator derives PDF semantics; the coordinator derives identities, digests, descriptors, persistence, and disposition. Caller ownership begins on return and closes the stream in `finally`.

**Evidence and rationale.** Collector-supplied metadata would cross the trust boundary without independent proof.

**Security and correctness consequences.** Limits untrusted output to bytes and centralizes authority.

**Explicit exclusions.** No persistence, logging, lifecycle command, task disposition, path creation, network decision, or metadata authority in the collector.

**Remaining owner or implementation gates.** Exact strategy-specific method/input signature.

**Named regression requirements.** `CERT-STRATEGY-COLLECTOR-STREAM-ONLY`; `CERT-STRATEGY-COLLECTOR-METADATA-NONAUTHORITATIVE`; `CERT-STRATEGY-COLLECTOR-FINALLY-CLOSE`; `CERT-STRATEGY-COLLECTOR-PARTIAL-STREAM-CLEANUP`; `CERT-STRATEGY-COLLECTOR-NO-SIDE-EFFECTS`.

**Exact owner-approval wording.** `Approve CS13 as written: the collector exposes only a bounded readable stream and owns no authoritative metadata or side effect.`

### CS14 - Semantic PDF parser and validation contract

**Status.** Implementation-blocked; no parser approved.

**Exact proposed decision.** Require a pinned bounded semantic PDF parser with exact version, license, code-manifest digest, memory/time/page/input limits, fail-closed error mapping, and adversarial corpus. It must validate structure, prove `page_count >= 1`, enforce the eventual owner-approved CS15 maximum page ceiling, and reject encryption, JavaScript, actions/open actions/additional actions, launch behavior, attachments, embedded files/file specifications, rich media, XFA, `AcroForm`, unresolved external references, malformed object/xref streams, and trailing payloads. Lexical scanning and generator success are insufficient.

**Evidence and rationale.** The private store performs bounded structural checks, not complete semantic active-content analysis. Installed TCPDF no longer supplies its historical parser; mPDF/FPDI are not approved security validators.

**Security and correctness consequences.** Reduces active-content and parser-differential risk before immutable acceptance.

**Explicit exclusions.** No unbounded parse, complete-file read, MIME/extension trust, vendor-private parser reuse, or acceptance based on one parser warning string.

**Remaining owner or implementation gates.** Parser selection, licensing, attestation, corpus, and measurements.

**Named regression requirements.** `CERT-STRATEGY-PARSER-SELECTION-BLOCKED`; `CERT-STRATEGY-PARSER-ACTIVE-CONTENT-REJECTED`; `CERT-STRATEGY-PARSER-DIFFERENTIAL-CORPUS`; `CERT-STRATEGY-PARSER-BOUNDED-RESOURCES`; `CERT-STRATEGY-PARSER-DEPENDENCY-ATTESTED`; `CERT-STRATEGY-PARSER-LEXICAL-SCAN-INSUFFICIENT`; `CERT-STRATEGY-PARSER-ACROFORM-REJECTED`; `CERT-STRATEGY-PARSER-ZERO-PAGE-REJECTED`; `CERT-STRATEGY-PARSER-PAGE-CEILING-ENFORCED`.

**Exact owner-approval wording.** `Approve CS14's semantic validation requirements while keeping parser selection and implementation blocked.`

### CS15 - Resource ceilings and measurement plan

**Status.** Implementation-blocked; operator evidence required.

**Exact proposed decision.** Preserve the existing inclusive 1..16,777,216-byte certificate limit and 1 MiB storage chunk. Do not guess page, memory, per-certificate duration, per-capture duration, or temporary-storage ceilings. Measure representative sanitized smallest, typical, largest, multi-course, complex-font, image-heavy, and adversarial fixtures on PHP 8.3.30 and 8.5.7 using the final selected producer/source and parser, then obtain owner approval of exact integers.

**Evidence and rationale.** No generator was executed and no representative fixture evidence exists. Output size alone does not bound rendering/parser memory.

**Security and correctness consequences.** Prevents denial of service and hidden host-limit dependence.

**Explicit exclusions.** PHP/web-server defaults, nominal one-page assumptions, and unmeasured values are not policy.

**Remaining owner or implementation gates.** `DEFERRED_OPERATOR_EVIDENCE: CS15-REPRESENTATIVE-FIXTURE-MEASUREMENTS` and exact approved integers.

**Named regression requirements.** `CERT-STRATEGY-RESOURCE-MEASUREMENT-REQUIRED`; `CERT-STRATEGY-RESOURCE-CEILING-EQUALITY-AND-OVERFLOW`; `CERT-STRATEGY-RESOURCE-TIMEOUT-CHECKPOINT`; `CERT-STRATEGY-RESOURCE-TEMP-CONTAINMENT`; `CERT-STRATEGY-RESOURCE-HOST-DEFAULTS-REJECTED`.

**Exact owner-approval wording.** `Approve CS15's measurement plan and retain implementation blocking until representative evidence supports exact page, memory, duration, and temporary-storage ceilings.`

### CS16 - Licensing, redistribution, dependency, and supply-chain attestation

**Status.** Implementation-blocked; legal/operator evidence required.

**Exact proposed decision.** A selected strategy must inventory every producer/source client, parser, transitive dependency, template, font, image, CSS asset, license/version/source, modification, notice obligation, redistribution right, vulnerability status, build provenance, and code/package digest. Owner-controlled legal review must approve the combined distribution and customer deployment. Runtime attestation must compare the exact approved manifest through a non-configurable code authority.

**Evidence and rationale.** Local license texts identify some component licenses but do not settle the proposed combined work, locally modified code, commercial-plugin-derived logic, fonts, templates, assets, or service retention terms.

**Security and correctness consequences.** Prevents dependency/package substitution and unauthorized redistribution.

**Explicit exclusions.** No license inference from presence, plugin header, vendor directory, or general open-source label.

**Remaining owner or implementation gates.** `DEFERRED_OPERATOR_EVIDENCE: CS16-LEGAL-REDISTRIBUTION-APPROVAL` and an exact software bill of materials/code manifest.

**Named regression requirements.** `CERT-STRATEGY-LICENSE-EXACT-INVENTORY`; `CERT-STRATEGY-LICENSE-REDISTRIBUTION-GATE`; `CERT-STRATEGY-SUPPLY-CODE-MANIFEST`; `CERT-STRATEGY-SUPPLY-DEPENDENCY-SUBSTITUTION`; `CERT-STRATEGY-SUPPLY-VERSION-HEADER-INSUFFICIENT`.

**Exact owner-approval wording.** `Approve CS16's licensing and supply-chain evidence requirements while keeping every dependency and redistribution decision blocked pending legal and manifest evidence.`

### CS17 - Exact closed failure classification

**Status.** Proposed common constraint.

**Exact proposed decision.** Preserve these exact event-safe tuples; classification never uses message text:

| Condition | Category | Reason | Context | Lifecycle |
|---|---|---|---|---|
| invalid/missing/contradictory authoritative B10 assignment/reference | invalid | `archive_certificate_invalid` | `certificate_gate` | existing authoritative invalidity route only after recovery |
| positive source/provenance drift | invalid | `archive_source_drift` | `fingerprint_compare` | existing `DetectSourceDrift` route |
| deterministically rejected local PDF bytes | operational_blocked | `task_handler_failed` | `certificate_validate` | none |
| missing/unattested producer, template, or generation dependency | operational_blocked | `task_handler_failed` | `certificate_generate` | none |
| missing/incompatible parser | operational_blocked | `task_handler_failed` | `certificate_validate` | none |
| producer defect, resource exhaustion, or deterministic generation failure | operational_blocked | `task_handler_failed` | `certificate_generate` | none |
| explicitly enumerated transient timeout or stream interruption | retryable | `task_handler_failed` | `certificate_generate` | none |
| unknown/ambiguous failure during production or stream acquisition | operational_blocked | `task_handler_failed` | `certificate_generate` | none |
| unknown/ambiguous failure during semantic PDF validation | operational_blocked | `task_handler_failed` | `certificate_validate` | none |

Strategy B transport failures are not classified here because no transport is authorized. A future Strategy B decision must add one exact tuple per closed transport condition without changing the common tuples.

**Evidence and rationale.** Local producer/parser failure is not authoritative evidence that archive facts are invalid.

**Security and correctness consequences.** Prevents invented lifecycle facts and ambiguous implementation choices.

**Explicit exclusions.** No category/context alternatives, error-text parsing, new event code, or `ArchiveFailed` for local/operational uncertainty.

**Remaining owner or implementation gates.** Selected-strategy exceptions and, only for Strategy B, a separate exact transport table.

**Named regression requirements.** `CERT-STRATEGY-FAILURE-TUPLE-AUTHORITATIVE-REFERENCE-INVALID`; `CERT-STRATEGY-FAILURE-TUPLE-POSITIVE-DRIFT`; `CERT-STRATEGY-FAILURE-TUPLE-LOCAL-PDF-BLOCKED-VALIDATE`; `CERT-STRATEGY-FAILURE-TUPLE-PRODUCER-MISSING-BLOCKED-GENERATE`; `CERT-STRATEGY-FAILURE-TUPLE-PARSER-MISSING-BLOCKED-VALIDATE`; `CERT-STRATEGY-FAILURE-TUPLE-PRODUCER-DEFECT-BLOCKED-GENERATE`; `CERT-STRATEGY-FAILURE-TUPLE-TRANSIENT-RETRYABLE-GENERATE`; `CERT-STRATEGY-FAILURE-TUPLE-UNKNOWN-GENERATE-BLOCKED`; `CERT-STRATEGY-FAILURE-TUPLE-UNKNOWN-VALIDATE-BLOCKED`.

**Exact owner-approval wording.** `Approve CS17's exact common failure tuples and event-free operational semantics; no transport failure contract is approved.`

### CS18 - Fencing, concurrency, recovery, and orphan reuse

**Status.** Proposed common constraint.

**Exact proposed decision.** Keep certificate acquisition inside the original fenced `capture_evidence` task. Replay an exact authoritative completed outcome first. Otherwise perform the consistent read, close it, verify reviewed/captured and selected provenance parity, derive every course's expected bytes/count/SHA-256, then inspect/reuse exact unreferenced committed objects, commit missing blobs, validate the complete prepared result, recheck the lease, and submit one atomic UoW decision. Partial multi-certificate recovery repeats the complete expected-digest rule per course.

**Evidence and rationale.** Existing task fencing and UoW atomicity already protect authority; deterministic expected bytes make blob-first crash recovery safe.

**Security and correctness consequences.** A stale worker cannot claim success, replace bytes, or turn lease loss into an event.

**Explicit exclusions.** No new task type, orphan deletion/quarantine, open-before-parity, digest learned from orphan, overwrite, cross-course reuse, or lifecycle inference from worker death.

**Remaining owner or implementation gates.** Selected strategy's exact identities and expected-byte derivation.

**Named regression requirements.** `CERT-STRATEGY-RECOVERY-AUTHORITATIVE-OUTCOME-FIRST`; `CERT-STRATEGY-RECOVERY-PARITY-BEFORE-ORPHAN`; `CERT-STRATEGY-RECOVERY-DIGEST-BEFORE-OPEN`; `CERT-STRATEGY-RECOVERY-PARTIAL-MULTI-COURSE`; `CERT-STRATEGY-RECOVERY-FENCE-LOSS-NO-DISPOSITION`; `CERT-STRATEGY-RECOVERY-TWO-CONNECTION-ONE-OWNER`; `CERT-STRATEGY-RECOVERY-IMMUTABLE-COLLISION-NO-OVERWRITE`.

**Exact owner-approval wording.** `Approve CS18 as written: preserve the original fenced task and authoritative-replay, parity, expected-digest, immutable-object, and atomic-UoW recovery order.`

### CS19 - Proposed implementation allowlist and verification matrix

**Status.** Implementation allowlist blocked; verification requirements proposed.

**Exact proposed decision.** Do not approve or guess an implementation allowlist. Exact production/test files depend on the selected producer/source, parser, input/provenance versioning, and identity contract. A later selection proposal must provide the smallest mechanical exact-path allowlist and reject every path outside it. No factory, service container, generic rendering framework, new task type, or unrelated refactor is permitted.

A later authorized implementation must run PHP 8.3.30 and 8.5.7 against disposable MySQL 8.0, MySQL 8.4, and MariaDB 10.6; every retained archive suite; selected-strategy unit, persistence, crash, failure, parser, security, and two-connection tests; focused `php -n`; lint; boundaries; digests; runner uniqueness; `git diff --check`; and forbidden-surface scans. PHP 8.4 remains optional until a real CLI exists.

**Evidence and rationale.** No strategy-specific class or contract can be honestly named before selection. The retained matrix is already the acceptance floor.

**Security and correctness consequences.** Prevents speculative scaffolding and scope creep.

**Explicit exclusions.** No present production/test/schema/metadata/entrypoint/runner modification and no database matrix in this checkpoint.

**Remaining owner or implementation gates.** Strategy selection, exact contracts, exact allowlist, and explicit implementation authorization.

**Named regression requirements.** `CERT-STRATEGY-ALLOWLIST-BLOCKED-UNTIL-SELECTION`; `CERT-STRATEGY-MATRIX-PHP83-PHP85-THREE-DATABASES`; `CERT-STRATEGY-MATRIX-RETAINED-SUITES`; `CERT-STRATEGY-BOUNDARY-NO-RUNTIME-WIRING`; `CERT-STRATEGY-BOUNDARY-NO-SCHEMA-CHANGE`; `CERT-STRATEGY-RUNNER-EXACTLY-ONCE`.

**Exact owner-approval wording.** `Approve CS19's future verification floor while keeping the implementation allowlist wholly blocked until one strategy and its exact contracts are approved.`

### CS20 - Remaining gates, deferrals, and exact owner approval

**Status.** Proposed.

**Exact proposed decision.** Approval of CS01-CS20 records continued deferral only. Strategy selection requires a new owner amendment resolving CS04-CS16 and CS19. Implementation then requires a separate explicit authorization. Packet materialization, verification/finalization, D16, downloads, scheduling changes, current-site/controlled testing, production activation, deployment, and schema/event/snapshot/digest changes remain deferred.

**Evidence and rationale.** Certificate acquisition is only one of several production activation gates, and its own authority remains unresolved.

**Security and correctness consequences.** Prevents proposal approval from being treated as implementation or activation authority.

**Explicit exclusions.** Every downstream/runtime/current-site surface listed above.

**Remaining owner or implementation gates.** The complete gate list in Section 9 and two later approvals: strategy selection, then implementation authorization.

**Named regression requirements.** `CERT-STRATEGY-GATE-NO-IMPLEMENTATION-AUTHORITY`; `CERT-STRATEGY-GATE-DOWNSTREAM-DEFERRED`; `CERT-STRATEGY-GATE-CURRENT-SITE-BLOCKED`; `CERT-STRATEGY-GATE-OWNER-AMENDMENT-REQUIRED`; `CERT-STRATEGY-GATE-PRODUCTION-ACTIVATION-BLOCKED`.

**Exact owner-approval wording.** `Approve CS20 as written: record continued deferral only and keep strategy selection, implementation, current-site testing, downstream work, and activation behind separate owner gates.`

## 8. Requirement-to-regression and operator-evidence matrix

Every regression identifier is defined exactly once in its owning decision. This table references decision-owned sets without repeating identifiers.

The proposal defines exactly 103 unique named regression identifiers.

| Requirement | Evidence classification | Owning set |
|---|---|---|
| Proposal-only and constructed-dark boundary | future executable regression | CS01 named set |
| Evidence authority and fact/inference separation | document/static regression | CS02 named set |
| Mandatory selection criteria | document/static regression | CS03 named set |
| Installed local generator rejection and determinism gaps | source/static regression | CS04 named set |
| No hypothetical upstream selection | source/static regression | CS05 named set |
| Continued deferral and no mixed fallback | document/static regression | CS06 named set |
| Code/source authority and attestation | future executable regression | CS07 named set |
| Template/font/asset/upstream provenance substitution | future executable regression | CS08 named set |
| E07/E08 parity/version compatibility | future golden/integration regression | CS09 named set |
| Deterministic/authenticated expected bytes and recovery | future golden/crash regression | CS10 named set |
| Exact selected-strategy input grammar | future executable regression | CS11 named set |
| Course/tenant identity and shared references | future golden/persistence regression | CS12 named set |
| Stream-only collector boundary | future unit/failure regression | CS13 named set |
| Semantic PDF security validation | future adversarial-parser regression | CS14 named set |
| Resource ceilings | deferred operator measurements plus future limit regressions | CS15 named set and operator evidence |
| Licensing and redistribution | deferred legal/operator approval plus future attestation regressions | CS16 named set and operator evidence |
| Exact failure tuples | future unit/persistence regression | CS17 named set |
| Fence/crash/orphan recovery | future concurrency/failure regression | CS18 named set |
| Exact allowlist and complete matrix | future static/database regression | CS19 named set |
| Remaining gates and no activation | document/static/boundary regression | CS20 named set |

Every future negative regression must assert the exact exception class and category/reason/context tuple where applicable, zero lifecycle event unless explicitly authoritative, zero database residue, zero filesystem residue outside the isolated root, zero sensitive output, and exact fence/disposition behavior.

## 9. Implementation-blocking owner gates

1. Select exactly one strategy using the mandatory CS03 criteria.
2. For Strategy A, supply an archive-owned producer and complete immutable package; for Strategy B, supply a real immutable authority/object contract.
3. Freeze exact producer/source identity, version, code/package attestation, and provenance descriptor.
4. Approve the E07/E08 versioning, normalization, digest, golden-vector, and retained-v1 compatibility plan.
5. Freeze the exact strategy-specific collector method and input envelope.
6. Freeze course/tenant-bound artifact and dedupe identity literals and vectors.
7. Select and attest a bounded semantic PDF parser and adversarial corpus.
8. Approve measured page, memory, duration, and temporary-storage ceilings.
9. Complete legal/redistribution review and software/package/font/asset manifest approval.
10. If Strategy B is selected, separately approve transport, SSRF/DNS/redirect/TLS/authentication/credential/timeout/revocation contracts and exact failure tuples.
11. Freeze the smallest exact implementation allowlist.
12. Issue a separate explicit implementation authorization.

No gate is satisfied merely by approving this proposal.

## 10. Explicit deferrals

- certificate acquisition implementation and generator/parser execution;
- E07/E08, source query, snapshot, event, task, schema, canonical JSON, or digest changes;
- packet materialization, verification/finalization, D16, downloads, reset, and projection rebuild;
- HTTP, REST, loopback, DNS, remote storage, credentials, cookies, nonces, or TLS changes;
- hooks, scheduling, CLI/controller registration, activation, current-site/controlled testing, deployment, and production access;
- staging, commit, push, pull request, and remote changes for this checkpoint; and
- `.claude/` and the audit-packet proposal.

## 11. Exact owner decision request

> **Approve Certificate Acquisition Strategy Decisions CS01-CS20 as written: continue deferral and select neither Strategy A nor Strategy B; retain Strategy A only as an unselected research direction; require an evidence-complete owner amendment selecting exactly one strategy; preserve all CA01-CA20 stream-only, course-binding, deterministic/authenticated-byte, event-safe failure, fencing, private-storage, atomic-UoW, compatibility, and constructed-dark constraints; keep producer/source authority, provenance, E07/E08 versioning, input grammar, identity literals, semantic parser, resource ceilings, licensing, supply-chain attestation, transport, implementation allowlist, and golden vectors blocked; and authorize no implementation, current-site testing, network route, downstream slice, scheduling change, activation, or deployment.**

Approval of this sentence authorizes documentation of continued deferral only. It does not select a certificate-acquisition strategy or authorize implementation.

**Formal owner approval recorded 2026-08-11.** CS01-CS20 are approved as written for documentation-only continued deferral. Strategy A and Strategy B remain unselected. Certificate implementation remains blocked pending a separately approved, evidence-complete strategy decision. This approval does not authorize an implementation branch, certificate work, current-site testing, a network route, activation, deployment, or any other deferred surface.

## 12. Proposal verification handoff

This proposal must pass:

- PHP 8.3.30 boundaries and digests;
- PHP 8.5.7 boundaries and digests;
- PHP 8.4 reported unavailable unless a real CLI exists;
- exactly 20 unique CS decision headings and 20 exact approval clauses;
- unique regression identifiers and honest operator-evidence classification;
- UTF-8 without BOM and zero trailing whitespace;
- contradiction searches for selected/approved strategy, authorized allowlist, installed-generator approval, network authorization, ambiguous failure tuples, weakened CA constraints, and stale implementation authority;
- `git diff --check` plus an explicit untracked-proposal whitespace check;
- zero production, test, schema, metadata, entrypoint, manifest, or runner changes;
- zero staged files and zero tracked modifications; and
- only the proposal plus the two untouched authorized bystanders untracked.

No database matrix, Docker operation, current-site check, generator/parser execution, or network request belongs to this proposal-only checkpoint.
