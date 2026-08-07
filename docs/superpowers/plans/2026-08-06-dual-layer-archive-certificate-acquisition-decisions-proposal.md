# Dual-Layer Archive Certificate Acquisition Decisions Proposal

**Date:** 2026-08-06

**Status:** Formally approved for proposal-only scope

**Owner approval:** 2026-08-07 — Approved as written for proposal-only scope; implementation remains blocked pending selection and approval of one acquisition strategy.

**Branch:** `feature/dual-layer-archive-slice-1b-certificate-acquisition-proposal`

**Parent:** `913237923e6216de26e1aacf344e02843e431032`
**Scope:** owner decisions only; no implementation authorization

## 1. Purpose and authority

This record proposes Decisions CA01-CA20 for acquiring certificate-required evidence without weakening the accepted event, snapshot, digest, lease-fencing, private-storage, or constructed-dark contracts. Approval of this document would approve the stated contracts and deferrals only. It would not authorize production PHP, tests, current-site access, PDF generation, runtime wiring, controlled testing, activation, deployment, staging, commit, or push.

The authoritative order used was:

1. the active event-sourcing technical design and development handoff;
2. the accepted P3A worker/private-storage decisions;
3. the accepted P3B runtime decisions, especially D08 and D16;
4. accepted P3B2a evidence-capture and P3B2b source-adapter decisions and traceability;
5. accepted P3B3 runtime-composition and activation-contract decisions and traceability; and
6. current accepted implementation plus installed WordPress, LearnDash, and Certificate Builder source inspected read-only.

Any earlier statement that implies certificate generation is already safe is superseded by the narrower accepted gates and the source findings below.

## 2. Preflight evidence

| Check | Result |
|---|---|
| Branch | exact expected branch |
| HEAD | `913237923e6216de26e1aacf344e02843e431032` |
| Tracked/staged state | zero tracked changes; zero staged files |
| Permitted untracked paths | `.claude/`, this proposal, and `2026-08-07-audit-packet-live-to-dual-layer-archive-port-plan.md`; the two bystander paths were not read or modified |
| Entrypoint | exactly one accepted `require_once __DIR__ . '/includes/archive/bootstrap.php';`; zero other case-insensitive archive references |
| PHP 8.3.30 | boundaries 18/18; digests 9/9 |
| PHP 8.5.7 | boundaries 18/18; digests 9/9 |
| PHP 8.4 | unavailable; no `php.exe` found |
| Database matrix | intentionally not run for proposal-only work |

## 3. Confirmed facts and generator viability

### 3.1 Frozen archive facts

- A certificate-required course cannot be sealed until exactly one verified immutable certificate artifact is bound into the course, ordered evidence-asset manifest, `EvidenceSnapshotCaptured` payload, and Unit-of-Work side records.
- The isolated source transaction closes before certificate, filesystem, rendering, or network work.
- Certificate blobs commit before the database snapshot command. A later database failure leaves only non-authoritative orphans.
- The accepted E07/B10 certificate reference has exactly `certificate_post_id` and `source_record_version`. It deliberately excludes HTML, templates, URLs, cookies, paths, fonts, assets, bytes, and content digests.
- B09's certificate reference version covers assignment identity and certificate post type/status/modified time. It is not a digest of certificate post content, block rendering behavior, CSS, theme state, fonts, images, options, hooks, or generated PDF bytes.
- Snapshot v1 and the Unit of Work already validate ordered certificate descriptors. Current P3B2a code supplies empty manifests and fails closed when `certificate_required` is true.
- The private store already enforces an inclusive 16,777,216-byte certificate ceiling, streamed write/hash/read-back in chunks no larger than 1 MiB, basic PDF header/trailer/xref validation, immutable same-backend commit, containment, symlink rejection, and exact committed-object reuse.
- D16 lifecycle retry remains unresolved. Ordinary delivery retry, lease reclaim, fenced response-loss replay, and recovery of the original capture task remain valid; no new lifecycle retry model may be inferred.

### 3.2 Installed source inspected

| Source evidence | Confirmed behavior | Archive consequence |
|---|---|---|
| `sfwd-lms/includes/ld-certificates.php:567-755` | Browser route uses query parameters, nonces, current user, live course status/settings, global user substitution, actions, and termination | Not a server-side pure collector; browser/nonce flow is prohibited |
| `sfwd-lms/includes/ld-convert-post-pdf.php:110-235` | Loads live certificate/course/user records and request language/query state | Accepted captured facts are not the sole inputs |
| `sfwd-lms/includes/ld-convert-post-pdf.php:450-620` | Resolves site URLs/images, mutable certificate CSS/options, filters, actions, metadata, and certificate post content | Template and rendering provenance are outside E07/E08 |
| `sfwd-lms/includes/ld-convert-post-pdf.php:680-845` | Reads a live background image, permits hook mutation, and writes TCPDF output | Bytes are environment- and hook-dependent |
| `learndash-certificate-builder/src/controller/class-certificate-builder.php:211-280` | Requires singular WordPress request context, loads live certificate/course/user records, parses current post content, and delegates to the builder | Not callable from the dark worker using captured facts only |
| `learndash-certificate-builder/src/component/class-pdf.php:54-180` | Reads user-font options/files, applies filters, uses current posts/current user/site name, and streams browser-oriented output | Producer inputs and output identity are not closed |
| `learndash-certificate-builder/src/component/pdf/class-pdf-content.php:133-282` | Reads theme palette globals and attachment paths; falls back to attachment/stored URLs | Mutable theme/filesystem/network inputs are possible |
| `learndash-certificate-builder/src/traits/io.php:23-62` | Creates temporary and font paths under WordPress uploads | Violates the approved private-root-only model |
| installed mPDF metadata writer | Emits current-time creation/modification dates and a time-derived document ID; other paths use random temporary identifiers | Byte determinism is not established |
| installed TCPDF | Initializes document creation time with `time()` and writes it into metadata | Byte determinism is not established |

Installed versions observed from code are WordPress `7.0.2`, LearnDash `5.1.6.1`, dashboard plugin `1.2.0`, and LearnDash Certificate Builder `1.1.5`. Presence and version do not approve a producer, its license for incorporation, or a stable archival API.

### 3.3 Viability conclusion

The installed LearnDash legacy TCPDF path and Certificate Builder/mPDF path are **not viable as the archival producer in their current form**. Neither consumes only the accepted captured facts; neither has a closed immutable template/font/asset package; both expose mutable WordPress/global/filter behavior; both are time-dependent; and the builder uses the uploads tree and URL fallbacks.

No acquisition strategy is selected. Certificate acquisition must remain deferred until the owner separately approves exactly one of:

1. a versioned E07/E08 extension that captures and fingerprints every template/font/CSS/image/locale/rendering input plus an owned byte-deterministic local producer; or
2. an authenticated immutable upstream certificate object whose identity, version, bytes, byte count, and content digest can be independently verified without browser state.

This proposal approves only constraints common to both strategies. It does not choose a strategy or approve an implementation envelope, source identifier, producer literal, template-package schema, artifact-ID formula, or related golden vector.

## 4. Decision summary

| ID | Recommended selection | Retained-data effect |
|---|---|---|
| CA01 | Contract-only slice; no implementation | None |
| CA02 | Continue deferral; select exactly one strategy in a later amendment | None now; future strategy semantics retained |
| CA03 | Keep acquisition inside original fenced `capture_evidence`; no new task | None if adopted |
| CA04 | Collector returns only an open bounded readable stream; metadata remains coordinator-owned | Future boundary semantics retained |
| CA05 | Common input constraints only; exact strategy-specific envelope deferred | Blocking retained input decision |
| CA06 | Exactly one course-specific asset per required canonical course; shared references allowed | Snapshot/event manifest semantics retained |
| CA07 | Strategy-specific provenance contract required; exact fields unresolved | Blocking retained provenance decision |
| CA08 | Strategy-specific review/capture/acquisition parity required; exact fields unresolved | Blocking fingerprint/version decision |
| CA09 | No network path approved | Operational only |
| CA10 | Existing bounded PDF checks plus approved semantic parser; parser unresolved | Blocking validation decision |
| CA11 | Resource ceilings require representative evidence and explicit owner values | Blocking operational policy decision |
| CA12 | Byte-deterministic production required by the current schema; no nondeterministic alternative | Producer/retry semantics retained |
| CA13 | Reuse injected private store and invocation-owned staging only | No schema effect |
| CA14 | Existing descriptor invariants only; strategy-specific identity/provenance literals deferred | Blocking retained identity decision |
| CA15 | Parity and expected digest precede orphan reuse; final records commit in one fenced UoW | Existing atomic contract extended with real assets |
| CA16 | Authoritative invalidity may use lifecycle routes; local output failures remain operational and event-free | No new event code |
| CA17 | Authoritative replay first, then parity, deterministic derivation, and per-course object recovery | Operational/idempotency contract |
| CA18 | Closed privacy/logging envelope | Operational privacy contract |
| CA19 | Certificate-free bytes and all retained v1 contracts unchanged | Compatibility gate |
| CA20 | Proposed allowlist/matrix only; constructed-dark and downstream gates preserved | None |

## 5. Proposed decisions

### CA01 - Exact slice scope and exclusions

**Question.** What may this checkpoint decide?

**Recommendation.** Approve only the certificate-acquisition contracts and explicit deferrals in CA01-CA20. No code or test implementation, source-mapping change, schema/event/digest change, installed-plugin modification, current-site test, PDF generation, network call, task registration, runtime composition, activation, or deployment is authorized.

**Rejected alternatives.** Combining certificate acquisition with packet rendering, verification/finalization, downloads, D16, scheduling, or production activation is rejected. A speculative framework or new task family is rejected.

**Security/correctness justification.** Certificate generation adds mutable content, file, PDF-parser, and possibly network trust boundaries. They require independent owner decisions before bytes become immutable evidence.

**Affected classes/contracts.** Proposal documentation only; future `capture_evidence` handler, preparer, Build Coordinator, Unit of Work, artifact store, artifact repository, and snapshot store.

**Retained-data and compatibility impact.** None in this proposal.

**Named regression evidence required.** `CERT-BOUNDARY-PROPOSAL-ONLY`; `CERT-BOUNDARY-NO-RUNTIME-ACTIVATION`; `CERT-BOUNDARY-NO-SCHEMA-EVENT-OR-DIGEST-CHANGE`.

**Exact owner-approval wording.** `Approve CA01 as recommended: certificate-acquisition contracts only, with implementation and every listed downstream or runtime surface excluded.`

### CA02 - Certificate acquisition strategy

**Question.** Is an owned local generator, same-site route, external HTTP source, or deferral safe now?

**Recommendation.** Select continued deferral. A later owner amendment must choose exactly one mode: an owned byte-deterministic local producer using an immutable provenance package, or an authenticated immutable upstream object. Approve only the common requirements in this proposal: no installed LearnDash generator, browser route, or unauthenticated/network acquisition; closed provenance and version attestation; bounded streaming and semantic validation; deterministic expected-byte recovery under the current schema; fenced private storage; atomic UoW submission; and event-free operational failures. The owner must resolve CA04, CA05, CA07, CA08, CA10, CA11, and CA14 before authorizing implementation.

**Rejected alternatives.** The installed browser/same-site route is rejected because it depends on nonce/cookie/request/current-user/live-state behavior. External HTTP is not approved because no SSRF/authentication/immutable-source contract exists. Treating Certificate Builder 1.1.5 as owned merely because it is installed, combining both strategies in one input envelope, or choosing a strategy silently is rejected.

**Security/correctness justification.** Current source proves mutable live reads, filters/actions, uploads temporary paths, URL fallbacks, and time-dependent PDF metadata. It does not prove deterministic or immutable provenance.

**Affected classes/contracts.** Technical Design §11.4; D08; A07; future collector/producer.

**Retained-data and compatibility impact.** None while deferred. The selected mode, source identity, producer key/version, provenance representation, and any E07/E08 extension become retained evidence only after a later compatibility amendment.

**Named regression evidence required.** `CERT-STRATEGY-INSTALLED-GENERATOR-NOT-ARCHIVAL`; `CERT-STRATEGY-SAME-SITE-ROUTE-REJECTED`; `CERT-STRATEGY-EXTERNAL-HTTP-NOT-AUTHORIZED`; `CERT-STRATEGY-PRODUCER-GATE-BLOCKS-IMPLEMENTATION`; `CERT-STRATEGY-ONE-MODE-REQUIRED-BEFORE-IMPLEMENTATION`; `CERT-STRATEGY-NO-MIXED-INPUT-ENVELOPE`; `CERT-STRATEGY-SPECIFIC-PROVENANCE-DEFERRED`; `CERT-STRATEGY-PROVISIONAL-CONTRACT-NOT-AUTHORIZATION`.

**Exact owner-approval wording.** `Approve CA02 as recommended: continue deferral, approve only common constraints, and require a later amendment selecting exactly one acquisition strategy before implementation.`

### CA03 - Task and workflow placement

**Question.** Does certificate work remain in `capture_evidence` or require another durable task?

**Recommendation.** When the blocked producer gates are resolved, keep certificate acquisition inside the original fenced `capture_evidence` task after source read/fingerprint agreement and before snapshot preparation/submission. Do not add a task type. One capture attempt owns the deterministic certificate identities, immutable-object recovery, and final atomic snapshot submission.

**Rejected alternatives.** A separate certificate task is rejected because snapshot completeness cannot commit before all required bytes exist and no accepted intermediate lifecycle fact or task payload exists. Capturing after snapshot commit is rejected.

**Security/correctness justification.** This preserves the existing lease, task-derived outcome key, StartBuild attempt, response-loss replay, and one complete snapshot decision.

**Affected classes/contracts.** `GHCA_ACD_Archive_Evidence_Task_Handler`; `GHCA_ACD_Archive_Worker_Coordinator`; `GHCA_ACD_Archive_Task_Catalog`; no task-store schema change.

**Retained-data and compatibility impact.** No new retained task type or payload. Existing `capture_evidence` v1 bytes remain authoritative.

**Named regression evidence required.** `CERT-WORKFLOW-ORIGINAL-CAPTURE-TASK`; `CERT-WORKFLOW-NO-CERTIFICATE-TASK-TYPE`; `CERT-WORKFLOW-SOURCE-CLOSES-BEFORE-FILE-WORK`; `CERT-WORKFLOW-SNAPSHOT-WAITS-FOR-ALL-ASSETS`.

**Exact owner-approval wording.** `Approve CA03 as recommended: certificate acquisition remains inside the original fenced capture_evidence task and no new task type is created.`

### CA04 - Injected collector boundary

**Question.** What is the smallest testable production boundary?

**Recommendation.** Freeze only this common authority boundary: a later `GHCA_ACD_Archive_Certificate_Collector` receives coordinator-validated acquisition input plus the mandatory checkpoint and returns only one open bounded readable binary stream positioned at byte zero. Its exact method signature, input envelope, and seekability requirement remain provisional until one acquisition strategy is selected. Stream ownership transfers to the caller and the caller closes it in `finally`.

The collector does not return an array or result object and does not author `page_count`, `producer_key`, `producer_version`, `source_identifier`, a descriptor, or an evidence-asset record. The trusted bounded semantic validator computes page count and PDF semantics. Approved installed-code attestation plus coordinator-owned configuration supplies producer identity/version. The later selected and validated acquisition input supplies source identity. The coordinator constructs the descriptor and evidence-asset record. The collector performs no persistence, logging, lifecycle command, task disposition, path/URL creation, or other authoritative side effect.

**Rejected alternatives.** Returning metadata, a PDF string, absolute path, URL, WordPress object, descriptor, or result wrapper is rejected. A service container, factory family, or event bus is rejected.

**Security/correctness justification.** A stream preserves bounded copying. Coordinator-owned identity, attestation, semantic validation, and descriptor construction prevent collector-controlled values from becoming authority.

**Affected classes/contracts.** Proposed new contract; future evidence handler and collector implementation.

**Retained-data and compatibility impact.** None now. The exact strategy-specific boundary and producer/source semantics require a later retained-contract amendment.

**Named regression evidence required.** `CERT-COLLECTOR-RETURNS-STREAM-ONLY`; `CERT-COLLECTOR-METADATA-NOT-AUTHORITY`; `CERT-COLLECTOR-PAGE-COUNT-VALIDATOR-DERIVED`; `CERT-COLLECTOR-PRODUCER-ATTESTATION-COORDINATOR-OWNED`; `CERT-COLLECTOR-SOURCE-IDENTITY-COORDINATOR-OWNED`; `CERT-COLLECTOR-STREAM-NOT-STRING-PATH-OR-URL`; `CERT-COLLECTOR-CALLER-CLOSES-ON-EVERY-EXIT`; `CERT-COLLECTOR-INVALID-STREAM-BEFORE-STORE-OR-UOW`.

**Exact owner-approval wording.** `Approve CA04's common authority boundary: the collector returns only an open bounded readable stream, while all metadata, validation, descriptor construction, persistence, and disposition remain outside it; exact strategy-specific signatures remain deferred.`

### CA05 - Strategy-independent collector input constraints

**Question.** Which values may influence generated certificate bytes?

**Recommendation.** Approve only strategy-independent input rules. The coordinator must bind archive/task identity, the canonical course and employee evidence needed for that course, the authoritative B10 certificate reference, the selected acquisition-source identity, the selected producer/code attestation, and the mandatory checkpoint. Inputs must be closed, bounded, validated, and derived from authoritative captured evidence or non-configurable installed-code attestation. No clock, locale default, random value, global user, request, cookie, nonce, unapproved URL/path, option, filter result, or live WordPress/LearnDash object may influence acquired bytes.

The exact top-level fields, order, nested schemas, source identifier, producer literals, and any local `template_package` or immutable-upstream descriptor are provisional. They require a later owner amendment after CA02 selects one strategy. No mixed envelope containing fields for both strategies is permitted.

**Rejected alternatives.** Passing only post IDs to a live generator, permitting arbitrary arrays, letting the collector query WordPress, or approving a local-template envelope while the immutable-upstream strategy remains open is rejected.

**Security/correctness justification.** The selected input must enumerate every byte-affecting or byte-identifying value. Deferring the exact envelope prevents one unresolved strategy from silently constraining the other.

**Affected classes/contracts.** Future collector contract; E07/B10; capture handler; generator attestation.

**Retained-data and compatibility impact.** The future exact envelope, package/upstream schema, source identity, and attestation fields are retained provenance decisions and require a later amendment before implementation.

**Named regression evidence required.** `CERT-INPUT-SELECTED-STRATEGY-EXACT-ENVELOPE`; `CERT-INPUT-NO-MIXED-STRATEGY-FIELDS`; `CERT-INPUT-NO-LIVE-LOOKUP`; `CERT-INPUT-REFERENCE-VERSION-MISMATCH`; `CERT-INPUT-PRODUCER-ATTESTATION-MISMATCH`; `CERT-INPUT-UNAPPROVED-PROVISIONAL-FIELD-REJECTED`.

**Exact owner-approval wording.** `Approve CA05's strategy-independent input constraints only; defer the exact envelope, source identity, producer literals, and local-package or immutable-upstream schema until one strategy is selected.`

### CA06 - Required-course membership and ordering

**Question.** How do required courses map to certificate assets?

**Recommendation.** Derive the expected certificate list only from validated E07 courses in existing canonical course order. Every course with `certificate_required = true` must have exactly one non-null B10 reference and exactly one independently bound course-specific asset. Every false course must have no reference and no asset. Reject missing, substituted, reordered, additional, or duplicate results for the same course before staging or UoW submission. `snapshot.courses[n].certificate_artifact_id` is set from the asset for that same course. `source.evidence_assets`, event `certificate_asset_ids`, event `certificate_content_digests`, and UoW descriptors use the identical order.

Canonical course IDs, role keys, and artifact IDs are each unique. Role keys and artifact IDs are distinct per course. Repeated `certificate_post_id` values and repeated B10 certificate references across different courses are allowed because LearnDash may assign one template to multiple courses. Each such course still produces independently bound bytes and a distinct course role/artifact identity. The existing `MAX_EVIDENCE_ASSETS = 10000` is an absolute structural ceiling, not an operational promise.

**Rejected alternatives.** Sorting by artifact ID, certificate post ID, filename, or generator completion order is rejected. Optional omission for a required course is rejected.

**Security/correctness justification.** One canonical order makes the snapshot, event, descriptors, ledger, packet, and final verifier agree without heuristic matching.

**Affected classes/contracts.** Evidence validator/preparer; snapshot store; Build Coordinator; Unit of Work; artifact repository.

**Retained-data and compatibility impact.** Uses the existing snapshot/event ordering contract.

**Named regression evidence required.** `CERT-MEMBERSHIP-CERTIFICATE-FREE-UNCHANGED`; `CERT-MEMBERSHIP-ONE-REQUIRED`; `CERT-MEMBERSHIP-MULTIPLE-CANONICAL-ORDER`; `CERT-MEMBERSHIP-MISSING-SUBSTITUTED`; `CERT-MEMBERSHIP-REORDERED-OR-ADDITIONAL`; `CERT-MEMBERSHIP-COURSE-ASSET-ROUNDTRIP`; `CERT-MEMBERSHIP-SHARED-REFERENCE-ALLOWED`; `CERT-MEMBERSHIP-SHARED-REFERENCE-DISTINCT-COURSE-ASSETS`; `CERT-MEMBERSHIP-DUPLICATE-COURSE-ASSET-REJECTED`; `CERT-MEMBERSHIP-DUPLICATE-ARTIFACT-ID-REJECTED`.

**Exact owner-approval wording.** `Approve CA06 as recommended: exactly one distinct course-specific certificate asset per required course in canonical order, while allowing different courses to share the same authoritative certificate reference.`

### CA07 - Template, font, asset, and producer provenance

**Question.** What provenance is sufficient for an immutable archival certificate?

**Recommendation.** Keep implementation blocked. The selected strategy needs one exact closed provenance contract. For owned local production, a later amendment must define the immutable template/content/CSS/page/locale/font/image/rendering package, package digest/version, producer key/version, code manifest, attestation, and licensing. For an authenticated immutable upstream object, a later amendment must instead define authoritative object issuer/source identity, immutable object/version identity, authenticated byte count and content digest, acquisition attestation, and licensing. These are alternatives, not a combined envelope. Ordinary options, source rows, request/task fields, filters, globals, or environment claims are not authority.

**Rejected alternatives.** `certificate_post_id`, `post_modified_gmt`, installed plugin version, or a hash of only post content is insufficient. Loading theme state, user fonts, uploads assets, or hooks at render time is rejected.

**Security/correctness justification.** Current generator bytes depend on inputs not covered by B10. Approving them without a manifest would make provenance unverifiable and retries nondeterministic.

**Affected classes/contracts.** E07/E08 source/fingerprint contracts; B09/B10; P3B3 attestation; future producer and snapshot evidence asset.

**Retained-data and compatibility impact.** Blocking retained-data decision. Any selected provenance schema, source identity, producer literal, or digest domain requires a separately highlighted version/migration plan; this proposal creates none.

**Named regression evidence required.** `CERT-PROVENANCE-SELECTED-STRATEGY-CLOSED-MANIFEST`; `CERT-PROVENANCE-LOCAL-TEMPLATE-DRIFT-BEFORE-GENERATION`; `CERT-PROVENANCE-LOCAL-TEMPLATE-DRIFT-AFTER-GENERATION`; `CERT-PROVENANCE-LOCAL-FONT-CSS-IMAGE-DIGESTS`; `CERT-PROVENANCE-UPSTREAM-OBJECT-AUTHENTICATED`; `CERT-PROVENANCE-INSTALLED-CODE-ATTESTATION`; `CERT-PROVENANCE-LICENSE-GATE`.

**Exact owner-approval wording.** `Approve CA07 deferral: implementation remains blocked until the selected strategy has one exact closed provenance, attestation, digest, and licensing contract.`

### CA08 - Review, capture, generation, and submission drift

**Question.** How is one reviewed source identity preserved across rendering?

**Recommendation.** Keep implementation blocked because E07/E08 v1 does not bind every possible acquisition input. A later owner decision must choose either a versioned E07/E08 local-package extension or an authenticated immutable upstream object/version contract. Once chosen, review and capture must use the identical adapter, normalization, acquisition descriptor version, digest domain, producer/source version, and code attestation. The handler compares reviewed and captured source fingerprints before acquisition, checkpoints before acquisition, verifies the selected strategy's provenance identity before and after acquisition, and checkpoints again before storage commit and UoW submission. Any positive mismatch follows the accepted `DetectSourceDrift` path; uncertainty remains operational and event-free.

**Rejected alternatives.** A second unfenced live reread merged into the snapshot, relying solely on `post_modified_gmt`, or ignoring mutation after the source transaction is rejected.

**Security/correctness justification.** A consistent source snapshot proves database coherence only. It does not freeze mutable files/options/hooks after rollback.

**Affected classes/contracts.** E08 fingerprint; B11 parity; evidence handler; Build Coordinator; future final verifier.

**Retained-data and compatibility impact.** A new fingerprint/package version would affect retained data and must leave version 1 readable.

**Named regression evidence required.** `CERT-DRIFT-REVIEW-CAPTURE-PARITY`; `CERT-DRIFT-REFERENCE-SOURCE-VERSION-MISMATCH`; `CERT-DRIFT-SELECTED-PROVENANCE-BEFORE-ACQUISITION`; `CERT-DRIFT-SELECTED-PROVENANCE-AFTER-ACQUISITION`; `CERT-DRIFT-NO-TORN-SNAPSHOT`; `CERT-DRIFT-UNCERTAIN-READ-NO-LIFECYCLE-FACT`.

**Exact owner-approval wording.** `Approve CA08 deferral and common parity rules: no implementation until the selected strategy's acquisition inputs and provenance are versioned and bound, with positive drift using the existing DetectSourceDrift path.`

### CA09 - Network policy

**Question.** May certificate acquisition use HTTP, REST, loopback, or other network transport?

**Recommendation.** No network path is approved. The collector must reject or avoid HTTP/HTTPS, REST, loopback, sockets, DNS lookup, remote fonts/images/CSS, public object URLs, browser cookies, nonces, and TLS overrides. Database transport remains only the already accepted B05/C17 evidence-source exception and is closed before certificate work.

**Rejected alternatives.** Same-site URL fetching, cookie forwarding, disabled TLS verification, redirects, or a broad host allowlist are rejected. A future network proposal would need exact SSRF, DNS rebinding, IP range, scheme/host/port/path, redirect, authentication, TLS, size, media, timeout, and credential contracts.

**Security/correctness justification.** No authenticated immutable endpoint exists, and the installed route is browser-state dependent.

**Affected classes/contracts.** C17; A07; future collector; static forbidden-surface scans.

**Retained-data and compatibility impact.** Operational only.

**Named regression evidence required.** `CERT-NETWORK-NO-HTTP-HTTPS-REST-LOOPBACK`; `CERT-NETWORK-NO-COOKIE-OR-NONCE`; `CERT-NETWORK-NO-DISABLED-TLS`; `CERT-NETWORK-NO-REMOTE-ASSET`; `CERT-NETWORK-DATABASE-EXCEPTION-NOT-EXPANDED`.

**Exact owner-approval wording.** `Approve CA09 as recommended: no certificate network path, browser credential, nonce, remote asset, loopback, or TLS exception is authorized.`

### CA10 - PDF validation and active-content policy

**Question.** Which PDF bytes are acceptable?

**Recommendation.** Preserve the inclusive minimum of 1 byte before format validation and maximum of 16,777,216 bytes. Preserve existing streamed SHA-256, 8-byte `%PDF-1.0` through `%PDF-1.7` or `%PDF-2.0` header, final `startxref`/`%%EOF`, and bounded classic/xref-stream target checks. Add a certificate-specific semantic validation gate before acceptance that proves the document is parseable, has at least one page, stays within the CA11 page ceiling, and rejects encryption, JavaScript, actions/open actions/additional actions, launch actions, embedded files/attachments/file specifications, rich media, XFA, AcroForm, malformed object/xref streams, trailing payloads, and unresolved external references.

Lexical substring scanning is not sufficient because relevant objects may be compressed or encoded. No parser/dependency is approved by this proposal. The owner must approve a bounded parser, exact version/license/code manifest, and adversarial fixture results before implementation.

**Rejected alternatives.** Header-only validation, loading the complete PDF into memory, trusting MIME/extension, or reusing another plugin's private vendor directory without approval is rejected.

**Security/correctness justification.** The private store proves basic structure and bytes, not absence of active or embedded content.

**Affected classes/contracts.** Private store remains unchanged; proposed certificate validator; producer manifest.

**Retained-data and compatibility impact.** New certificates would be subject to stricter validation. Existing stored artifacts are not mutated.

**Named regression evidence required.** `CERT-PDF-BYTE-CEILING-EQUALITY`; `CERT-PDF-BYTE-CEILING-ONE-OVER`; `CERT-PDF-MALFORMED-TRUNCATED-WRONG-MEDIA`; `CERT-PDF-ENCRYPTED-REJECTED`; `CERT-PDF-JAVASCRIPT-ACTION-REJECTED`; `CERT-PDF-ATTACHMENT-RICHMEDIA-XFA-REJECTED`; `CERT-PDF-COMPRESSED-OBJECT-ACTIVE-CONTENT-REJECTED`; `CERT-PDF-BOUNDED-STREAMING`.

**Exact owner-approval wording.** `Approve CA10's byte and rejection policy, while keeping implementation blocked until a bounded parser/version/license and adversarial fixtures are separately approved.`

### CA11 - Page, memory, duration, temporary-storage, and checkpoint ceilings

**Question.** What exact resource limits govern one certificate and one capture invocation?

**Recommendation.** Do not guess values. Keep the following four values implementation-blocking until sanitized representative single- and multi-certificate fixtures are measured on PHP 8.3.30 and 8.5.7 and the owner approves exact integers: maximum pages per certificate, maximum worker memory in bytes, maximum elapsed milliseconds per certificate and per capture invocation, and maximum invocation-owned temporary bytes. The already-frozen 16 MiB object limit, 1 MiB stream chunk, 120-second lease horizon, and 30-second heartbeat policy remain ceilings but do not answer those four values.

Once approved, the collector must checkpoint before generation, at least once per streamed MiB and never less often than every 30 seconds during external work, after generation, before staging commit, and before UoW submission. Mandatory cleanup runs after fence loss, timeout, or failure without claiming success.

**Rejected alternatives.** PHP `memory_limit`, web-server timeout, mPDF/TCPDF defaults, or a nominal one-page assumption are rejected as policy.

**Security/correctness justification.** Installed generators can allocate fonts/images/layout structures far beyond output size. Only measured representative evidence can justify safe limits.

**Affected classes/contracts.** Future collector/validator; evidence handler; worker checkpoint callback; activation capacity evidence.

**Retained-data and compatibility impact.** Operational policy only, except changing accepted page semantics may affect which future evidence can be captured.

**Named regression evidence required.** `CERT-RESOURCE-PAGE-CEILING-EQUALITY-AND-OVERFLOW`; `CERT-RESOURCE-MEMORY-CEILING`; `CERT-RESOURCE-PER-CERTIFICATE-DURATION`; `CERT-RESOURCE-PER-CAPTURE-DURATION`; `CERT-RESOURCE-TEMPORARY-BYTE-CEILING`; `CERT-CHECKPOINT-BEFORE-DURING-AFTER-GENERATION`; `CERT-CHECKPOINT-BEFORE-COMMIT-AND-UOW`.

**Exact owner-approval wording.** `Approve CA11 deferral: no implementation until representative evidence supports exact owner-approved page, memory, elapsed-time, and temporary-storage integers.`

### CA12 - Determinism and retry semantics

**Question.** Must retries regenerate byte-identical PDFs?

**Recommendation.** Under the existing schema and blob-first/UoW model, require the selected producer to yield byte-deterministic certificate bytes for identical authoritative source/provenance inputs, proven by independent PHP 8.3.30/8.5.7 golden vectors. No nondeterministic-production alternative is approved. Supporting nondeterministic production would require a separately approved durable candidate journal or equivalent schema, plus versioning, recovery, compatibility, retention, and migration contracts; none is approved here.

A completed authoritative snapshot/event/descriptor/receipt may be replayed immediately. Without that completed outcome, no retry may inspect or trust an unreferenced committed object until the current consistent evidence read has passed reviewed/captured fingerprint parity, selected package/source parity, and producer attestation, and the worker has deterministically regenerated or independently derived the expected bytes, byte count, and SHA-256. Only then may it open the deterministic key and reuse exact bytes. An occupied key with a different digest is `archive_immutable_conflict`, never an overwrite opportunity. Time, locale, randomness, environment paths, and uncontrolled PDF metadata must not influence produced bytes.

**Rejected alternatives.** Nondeterministic production under the current schema, first-object-wins without a durable expected digest, opening an orphan before current parity/digest derivation, last-write-wins, generating a new artifact ID on each retry, or accepting a different retry digest is rejected.

**Security/correctness justification.** Immutable recovery must make response loss and crash windows converge on one evidence object.

**Affected classes/contracts.** Collector/producer; artifact store; capture handler; Build Coordinator recovery.

**Retained-data and compatibility impact.** Producer/version and retry identity are retained semantics.

**Named regression evidence required.** `CERT-DETERMINISM-CROSS-RUNTIME-GOLDEN`; `CERT-RETRY-EXACT-COMMITTED-OBJECT-REUSE`; `CERT-RETRY-IMMUTABLE-DIGEST-MISMATCH`; `CERT-RETRY-NO-NEW-ARTIFACT-ID`; `CERT-RETRY-NO-TIME-LOCALE-RANDOM-METADATA`; `CERT-RECOVERY-DETERMINISTIC-PRODUCER-REQUIRED`; `CERT-RECOVERY-NONDETERMINISTIC-REQUIRES-NEW-DURABLE-CONTRACT`; `CERT-RECOVERY-PARITY-BEFORE-ORPHAN-REUSE`; `CERT-RECOVERY-EXPECTED-DIGEST-BEFORE-OPEN`; `CERT-RECOVERY-PARTIAL-ASSETS-DETERMINISTIC`.

**Exact owner-approval wording.** `Approve CA12 as recommended: require byte-deterministic production under the current schema, derive expected bytes and digest after current parity before orphan inspection, and require a separate durable-contract decision for any nondeterministic producer.`

### CA13 - Staging and private-store rules

**Question.** Where may temporary and committed certificate bytes exist?

**Recommendation.** Use only the injected validated `GHCA_ACD_Archive_Artifact_Store`. The coordinator derives the later-approved logical identities, calls `create_staging()`, streams collector output through `write_staging(..., certificate)`, and commits through the later-approved deterministic committed key. A local producer, if selected, may use only an invocation-owned, constructor-injected temporary boundary proven inside the same validated private root; it may not receive or construct the absolute root/path. Cleanup targets only paths/handles created by that invocation.

Preserve absolute-path, `..`, mixed-separator, control-byte, identifier, symlink, containment, overwrite, collision, and public-root rejection. Existing committed-object reuse is accepted only after CA12 current parity and expected-byte/digest derivation, followed by exact kind, byte count, SHA-256, and PDF validation. No uploads fallback or public URL exists.

**Rejected alternatives.** WordPress uploads, system temp without a validated boundary, caller-built paths, predictable names, rename-overwrite, or deleting/quarantining arbitrary orphans is rejected.

**Security/correctness justification.** The installed Certificate Builder's uploads temp path is incompatible with the private-storage trust boundary.

**Affected classes/contracts.** Existing artifact-store interface/private implementation; future collector/handler.

**Retained-data and compatibility impact.** Reuses existing logical storage-key grammar; no absolute path enters retained data.

**Named regression evidence required.** `CERT-STORAGE-TRAVERSAL-ABSOLUTE-MIXED-SEPARATOR`; `CERT-STORAGE-SYMLINK-ESCAPE`; `CERT-STORAGE-PUBLIC-ROOT-REJECTED`; `CERT-STORAGE-STAGING-NO-OVERWRITE`; `CERT-STORAGE-COMMITTED-COLLISION`; `CERT-STORAGE-EXACT-REUSE`; `CERT-STORAGE-CLEANUP-CONTAINED`.

**Exact owner-approval wording.** `Approve CA13 as recommended: injected private-store staging and immutable commit only, with no uploads, public URL, caller path, overwrite, or containment exception.`

### CA14 - Artifact identity, descriptor, storage key, and manifest gates

**Question.** What exact retained certificate identity and descriptor are produced?

**Recommendation.** Approve only strategy-independent retained constraints: one distinct artifact ID and `course:<course_id>` role per certificate-required course; `artifact_kind = certificate`; schema version 1; validated byte count and SHA-256; `content_digest_algorithm = sha256`; `media_type = application/pdf`; ID-only filename and private-store key; attested bounded producer key/version; and ordered manifest binding under CA06.

The following illustrates the existing descriptor field set, but fields marked provisional are not approved literals:

| Field | Exact value |
|---|---|
| `artifact_id` | distinct per course; exact derivation provisional |
| `artifact_kind` | `certificate` |
| `artifact_schema_version` | integer `1` |
| `byte_count` | validated integer 1..16,777,216 |
| `content_digest` | 64-character lowercase SHA-256 of committed bytes |
| `content_digest_algorithm` | `sha256` |
| `filename` | ID-only PDF filename; exact literal grammar provisional |
| `media_type` | `application/pdf` |
| `producer_key` | exact future selected-strategy literal matching `^[a-z][a-z0-9._-]{0,63}$`; provisional |
| `producer_version` | exact future selected-strategy attested literal matching the retained version grammar; provisional |
| `role_key` | `course:<course_id>` |
| `storage_adapter` | `private_local` |
| `storage_key` | store-derived committed key from validated immutable identities; exact certificate grammar provisional |

The exact artifact-ID formula/domain, filename literal, storage-key derivation vector, source identifier, producer literals, selected-strategy provenance fields, and related golden vectors remain implementation-blocking. A later amendment must prove which of them are strategy-independent and freeze the rest after strategy selection. The ordered certificate manifest still consists of one descriptor-derived asset row per required course in CA06 order. Placeholders are forbidden.

**Rejected alternatives.** Treating the illustrative formula or a LearnDash-specific source identifier as approved, random per-retry identities, names/emails/course titles in keys or filenames, URLs, paths, or an unversioned producer are rejected.

**Security/correctness justification.** Stable course-specific identity is required for recovery, but freezing unproven literals before strategy selection would create an accidental retained contract.

**Affected classes/contracts.** Evidence preparer; Build Coordinator; Unit of Work; artifact repository; snapshot store; private store.

**Retained-data and compatibility impact.** The future artifact-ID domain, filename/key vectors, source identifier, producer literals, and provenance fields are retained contracts and require a later amendment before implementation.

**Named regression evidence required.** `CERT-IDENTITY-SELECTED-STRATEGY-LITERAL-GOLDEN`; `CERT-IDENTITY-P3B1-P3B2A-DOMAINS-UNCHANGED`; `CERT-DESCRIPTOR-EXISTING-FIELD-SET`; `CERT-STORAGE-KEY-SELECTED-STRATEGY-GOLDEN`; `CERT-SOURCE-IDENTIFIER-SELECTED-STRATEGY-GOLDEN`; `CERT-MANIFEST-IDS-DIGESTS-EXACT-ORDER`; `CERT-IDENTITY-PROVISIONAL-FORMULA-NOT-AUTHORIZED`.

**Exact owner-approval wording.** `Approve CA14's strategy-independent descriptor and ordered-manifest constraints only; defer artifact-ID, filename, storage-key, source-identifier, producer, provenance, and golden-vector literals to a later strategy amendment.`

### CA15 - Transaction ordering and atomic submission

**Question.** When do filesystem and database operations occur?

**Recommendation.** Preserve this exact order under the live task fence:

1. recover and replay an exact authoritative completed capture outcome if present;
2. otherwise perform the consistent evidence read and unconditionally close the B12 source transaction/session;
3. verify reviewed/captured fingerprint parity, selected package/source parity, and producer/source attestation;
4. deterministically regenerate or independently derive expected bytes, byte count, and SHA-256 for every required course;
5. only then inspect each unreferenced committed key, reuse an exact verified object, and commit each missing validated candidate object outside a database transaction;
6. build the final snapshot with course artifact IDs and ordered evidence assets;
7. validate the complete prepared snapshot/descriptors/event manifest before a UoW call;
8. recheck the task fence; and
9. in one existing UoW transaction insert the snapshot, every certificate descriptor, append `EvidenceSnapshotCaptured`, project/enqueue, advance the stream, write the receipt, and commit.

No handler-controlled value is interpreted after lifecycle commit. The coordinator synthesizes the existing exact completed/committed task outcome from receipt/history. Database failure leaves only non-authoritative committed objects for report-only orphan reconciliation.

**Rejected alternatives.** Holding a source/lifecycle transaction during PDF/file work, descriptor insertion before bytes, or snapshot/event commit without all assets is rejected.

**Security/correctness justification.** This preserves the accepted atomic side-record/event contract and makes external-work crashes recoverable.

**Affected classes/contracts.** Evidence handler/preparer; Build Coordinator; Unit of Work; stores/repository.

**Retained-data and compatibility impact.** Extends already frozen empty-certificate paths to their existing non-empty side-record contract.

**Named regression evidence required.** `CERT-ORDER-AUTHORITATIVE-OUTCOME-REPLAY-FIRST`; `CERT-ORDER-SOURCE-CLOSE-BEFORE-GENERATION`; `CERT-ORDER-PARITY-AND-DIGEST-BEFORE-ORPHAN-OPEN`; `CERT-ORDER-BYTES-BEFORE-DESCRIPTOR`; `CERT-ORDER-ALL-ASSETS-BEFORE-SNAPSHOT`; `CERT-ORDER-SNAPSHOT-DESCRIPTORS-EVENT-ATOMIC`; `CERT-ORDER-DB-ROLLBACK-NO-AUTHORITATIVE-RESIDUE`; `CERT-ORDER-ORPHAN-REPORT-ONLY`.

**Exact owner-approval wording.** `Approve CA15 as recommended: replay authority first, close the source read before file work, prove current parity and expected digests before orphan reuse, commit bytes outside transactions, and commit all authoritative records atomically through the existing UoW.`

### CA16 - Closed failure grammar

**Question.** How are certificate failures classified without inventing lifecycle facts?

**Recommendation.** Reuse existing codes and category/context classification only; never classify by message text.

| Condition | Category | Reason | Context | Lifecycle route |
|---|---|---|---|---|
| missing, invalid, or contradictory authoritative B10 assignment/reference | invalid | `archive_certificate_invalid` | `certificate_gate` | existing permanent lifecycle route only after authoritative recovery |
| missing/unattested producer, template, or generation dependency | operational_blocked | `task_handler_failed` | `certificate_generate` | none; operational retry/dead-letter only |
| missing/incompatible parser | operational_blocked | `task_handler_failed` | `certificate_validate` | none; operational retry/dead-letter only |
| positive source/package/reference drift | invalid | `archive_source_drift` | `fingerprint_compare` | exact existing `DetectSourceDrift` decision |
| explicitly enumerated transient generation timeout or transient stream interruption | retryable | `task_handler_failed` | `certificate_generate` | none; operational retry/dead-letter only under accepted E14 rules |
| fence loss/cancellation checkpoint | existing lease-loss category | existing fence reason | current phase | stale worker performs no disposition or outcome |
| deterministically rejected local malformed, truncated, wrong-media, encrypted, active-content, oversized, over-page-limit, or otherwise invalid PDF bytes | operational_blocked | `task_handler_failed` | `certificate_validate` | none; operational retry/dead-letter only |
| producer defect, resource exhaustion, or deterministic generation failure | operational_blocked | `task_handler_failed` | `certificate_generate` | none; operational retry/dead-letter only; no `ArchiveFailed` |
| staging/write/commit/open/access failure without positive immutable conflict | existing private-store category | sanitized existing store reason | `certificate_store` | E14/D05 operational classification; no invented lifecycle fact |
| occupied deterministic key with proven different immutable bytes | integrity | `archive_immutable_conflict` | `certificate_commit` | no additional lifecycle fact; manual integrity review |
| positive retained descriptor/snapshot/event mismatch | integrity | `archive_immutable_conflict` | `authoritative_recovery` | no additional lifecycle fact |
| unknown or ambiguous throwable | operational_blocked | `task_handler_failed` | exact current phase | operational retry/dead-letter only; no lifecycle event |

Raw generator/parser/driver exception text is discarded. Attempt five replays matching authoritative success first and matching authoritative failure second, then performs the CA12/CA15 deterministic recovery algorithm. Response loss after success never becomes `ArchiveFailed`. Only a separately approved authenticated immutable upstream object contract may later classify malformed upstream bytes as authoritative invalid evidence; local output never has that authority. Unknown or ambiguous failures remain event-free. Classification never uses message text.

**Rejected alternatives.** New event codes, error-prefix classification, or using dead task state as proof of archive failure is rejected.

**Security/correctness justification.** Operational inability to acquire bytes is not evidence that the archive facts are invalid.

**Affected classes/contracts.** Evidence exceptions; worker coordinator classification; Build Coordinator failure allowlist; task-store operational errors.

**Retained-data and compatibility impact.** No new event type or lifecycle code.

**Named regression evidence required.** `CERT-FAILURE-ASSIGNMENT-INVALID`; `CERT-FAILURE-PRODUCER-UNAVAILABLE-OPERATIONAL`; `CERT-FAILURE-TIMEOUT-RETRYABLE`; `CERT-FAILURE-IMMUTABLE-CONFLICT-INTEGRITY`; `CERT-FAILURE-UNKNOWN-NO-LIFECYCLE-EVENT`; `CERT-FAILURE-SANITIZED-TUPLES`; `CERT-FAILURE-LOCAL-MALFORMED-OPERATIONAL`; `CERT-FAILURE-LOCAL-ACTIVE-CONTENT-OPERATIONAL`; `CERT-FAILURE-LOCAL-RESOURCE-LIMIT-NO-LIFECYCLE`; `CERT-FAILURE-AUTHORITATIVE-REFERENCE-INVALID`; `CERT-FAILURE-UNKNOWN-EVENT-FREE`; `CERT-FAILURE-TUPLE-LOCAL-PDF-REJECTED-BLOCKED-VALIDATE`; `CERT-FAILURE-TUPLE-PRODUCER-DEPENDENCY-BLOCKED-GENERATE`; `CERT-FAILURE-TUPLE-PARSER-BLOCKED-VALIDATE`; `CERT-FAILURE-TUPLE-PRODUCER-DEFECT-BLOCKED-GENERATE`; `CERT-FAILURE-TUPLE-TRANSIENT-TIMEOUT-RETRYABLE-GENERATE`.

**Exact owner-approval wording.** `Approve CA16 as recommended: authoritative assignment invalidity and positive drift keep their existing routes, while every local output, producer, parser, resource, unknown, lease, and operational failure remains event-free and uses only closed retry/dead-letter handling.`

### CA17 - Crash, response-loss, concurrency, and fencing recovery

**Question.** What happens at each external-work crash boundary?

**Recommendation.** Before side effects, recover a matching `EvidenceSnapshotCaptured` receipt/event/snapshot and descriptors; if exact, replay the authoritative completed decision without acquisition. Otherwise perform the consistent evidence read, prove reviewed/captured fingerprint and selected package/source/producer parity, then deterministically regenerate or independently derive each course's expected bytes, byte count, and SHA-256. Only after those steps may the worker inspect an unreferenced committed key, reuse exact verified bytes, commit missing objects, and submit the atomic UoW decision. Partial multi-certificate recovery applies that complete sequence independently to every course.

Checkpoint under the current lease before generation, during streaming, after generation, before private commit, after each commit, before prepared-result acceptance, and immediately before UoW. A stale worker closes its streams and claims no heartbeat, commit success, retry, dead-letter, or lifecycle outcome. Two workers cannot both own one task; deterministic non-overwrite keys prevent a stale generator from replacing bytes.

Crash outcomes are:

- before/after staging: no authority; later retry uses a new staging object;
- after immutable object commit but before UoW: retry repeats the current source/parity and deterministic expected-digest derivation before opening and reusing the exact object;
- after UoW commit but before task completion: receipt/history/snapshot/descriptors replay success;
- response loss: the same replay path; and
- partial multi-certificate commit: after current parity and per-course expected-digest derivation, exact committed objects are reused, missing ones are produced, and no snapshot commits until all are valid.

**Rejected alternatives.** Deleting a possibly referenced committed object, inferring failure from lease expiry, or permitting a stale worker to report success is rejected.

**Security/correctness justification.** Authority remains in the fenced database decision; immutable bytes are candidates until referenced atomically.

**Affected classes/contracts.** Worker coordinator; task store fence; capture handler; artifact store; Build Coordinator/UoW.

**Retained-data and compatibility impact.** Operational/idempotency only; no new lifecycle transition.

**Named regression evidence required.** `CERT-CRASH-BEFORE-AFTER-STAGING`; `CERT-CRASH-BEFORE-AFTER-OBJECT-COMMIT`; `CERT-CRASH-PARTIAL-MULTI-ASSET-RECOVERY`; `CERT-RESPONSE-LOSS-AFTER-SNAPSHOT-COMMIT`; `CERT-FENCE-LOSS-CLEANUP-NO-DISPOSITION`; `CERT-TWO-CONNECTION-ONE-TASK-OWNER`; `CERT-STALE-WORKER-CANNOT-SUBMIT-UOW`; `CERT-RECOVERY-AUTHORITATIVE-COMPLETION-BYPASSES-GENERATION`; `CERT-RECOVERY-ORPHAN-OPEN-AFTER-CURRENT-PARITY`; `CERT-RECOVERY-EACH-COURSE-EXPECTED-DIGEST`.

**Exact owner-approval wording.** `Approve CA17 as recommended: authoritative completion replay comes first; otherwise current parity and deterministic per-course expected digests precede all orphan reuse, with checkpoints at every external boundary and no stale-worker authority.`

### CA18 - Privacy and operational logging

**Question.** Which certificate data may leave private artifacts?

**Recommendation.** Certificate bytes and employee PII exist only in bounded process memory/invocation temporary storage and committed private artifacts. Task rows, task errors, worker envelopes, receipts, lifecycle events, and ordinary logs may contain only existing immutable IDs, stable codes/categories/stages, byte counts, SHA-256 digests, producer key/version, role key, and bounded timings where already approved.

Names, emails, usernames, course/certificate titles, certificate bytes or excerpts, HTML, CSS, font/image bytes, source values, URLs, absolute or relative host paths, SQL, database/host/account names, credentials, cookies, nonces, raw exception text, stack traces, and parser diagnostics are prohibited. Filenames/storage keys contain immutable IDs only. Temp handles and paths are never logged.

**Rejected alternatives.** Debug logging raw producer/parser failures, logging generated filenames, or storing certificate content in task payloads/receipts is rejected.

**Security/correctness justification.** Immutable evidence has a long lifetime; operational telemetry must not become a second evidence store.

**Affected classes/contracts.** Collector/validator/handler; task operational errors; C19 worker envelope; host logging boundary.

**Retained-data and compatibility impact.** No new PII-bearing retained field.

**Named regression evidence required.** `CERT-PRIVACY-NO-BYTES-PII-HTML-IN-TASK-EVENT-RECEIPT`; `CERT-PRIVACY-NO-PATH-URL-SQL-CREDENTIAL`; `CERT-PRIVACY-NO-RAW-EXCEPTION-OR-STACK`; `CERT-PRIVACY-ID-ONLY-FILENAME-KEY`; `CERT-PRIVACY-SANITIZED-WORKER-ENVELOPE`.

**Exact owner-approval wording.** `Approve CA18 as recommended: certificate bytes and PII remain only in bounded private processing and artifacts, with the closed sanitized operational envelope.`

### CA19 - Compatibility and retained-data preservation

**Question.** What must remain byte-identical and readable?

**Recommendation.** Certificate-free capture remains byte-identical, including snapshot/event/task/receipt/digest bytes and P3B1/P3B2a ID domains. Existing tasks, snapshots, events, descriptors, and ledgers remain readable. No schema, task payload, event type/payload, snapshot schema, canonical JSON rule, digest domain, aggregate transition, immutable-row update/delete, or source adapter v1 mapping changes in this proposal.

Any CA07/CA08 change to E07/E08, source fingerprint, template provenance, snapshot evidence assets, or producer semantics requires a separately highlighted retained-data compatibility decision, new version/golden vectors where applicable, and an explicit migration/read policy. No historical PHP 7.4 evidence is rewritten; the active distribution floor remains PHP 8.3.

**Rejected alternatives.** Reusing a version number after semantic change, rewriting retained rows, or modifying certificate-free output to simplify implementation is rejected.

**Security/correctness justification.** Existing immutable evidence and idempotency identities cannot be reinterpreted silently.

**Affected classes/contracts.** All retained task/event/snapshot/artifact/digest contracts.

**Retained-data and compatibility impact.** Explicitly none unless a later owner-approved versioned amendment is made.

**Named regression evidence required.** `CERT-COMPAT-CERTIFICATE-FREE-BYTES-IDENTICAL`; `CERT-COMPAT-RETAINED-TASKS-SNAPSHOTS-READABLE`; `CERT-COMPAT-ID-DOMAINS-UNCHANGED`; `CERT-COMPAT-NO-SCHEMA-EVENT-DIGEST-MUTATION`; `CERT-COMPAT-NO-IMMUTABLE-UPDATE-DELETE`.

**Exact owner-approval wording.** `Approve CA19 as recommended: preserve certificate-free bytes and every retained v1 contract; any provenance or fingerprint expansion requires a separate versioned compatibility decision.`

### CA20 - Proposed allowlist, verification, dark boundary, and remaining gates

**Question.** What could a later implementation checkpoint touch and prove?

**Recommendation.** Approval of CA01-CA20 still authorizes no implementation. After CA02, CA04, CA05, CA07, CA08, CA10, CA11, and CA14 are resolved by a strategy-selection amendment, a separate owner authorization may freeze an exact implementation allowlist. The common candidate paths below are provisional and do not authorize a local or upstream implementation:

**Provisional common additions**

- `includes/archive/contracts/interface-archive-certificate-collector.php` for the common stream-only authority boundary; its exact method signature remains blocked
- `includes/archive/infrastructure/class-archive-certificate-pdf-validator.php`
- `tests/archive/test-certificate-acquisition.php`
- `tests/archive/test-certificate-acquisition-persistence.php`
- `docs/superpowers/plans/2026-08-07-dual-layer-archive-certificate-acquisition-traceability.md`

**Possible modifications**

- `includes/archive/application/class-archive-evidence-task-handler.php`
- `includes/archive/application/class-archive-evidence-snapshot-preparer.php`
- `includes/archive/application/class-archive-build-coordinator.php`
- `includes/archive/application/class-archive-unit-of-work.php`
- `includes/archive/bootstrap.php` only to load class definitions, never to construct/register/activate them
- `tests/archive/test-p3-boundaries.php`
- `tests/archive/test-p3b2a-evidence-capture.php`
- `tests/archive/test-p3b2a-evidence-persistence.php`
- `tests/archive/test-all.ps1`
- this proposal only to record later owner amendments/approval

No strategy-specific production implementation path is authorized or listed. The later strategy amendment must add exactly one such path, freeze the collector method/envelope, and may not silently broaden the common candidate list. No need is presently shown to modify task catalog/store, schema/migrator, event/domain/digester/canonical JSON, private-store/repository/snapshot-store behavior, entrypoint, module runtime composition, feature flags, controller, cron, CLI registration, or metadata. Any other need is a new owner gate.

A later implementation must run PHP 8.3.30 and 8.5.7 against disposable MySQL 8.0, MySQL 8.4, and MariaDB 10.6, with every retained suite, new unit/persistence/failure/concurrency suite, `php -n` focused runs, lint, boundaries, digests, runner uniqueness, `git diff --check`, and forbidden-surface scans. PHP 8.4 remains optional/unavailable until an actual CLI exists. Representative sanitized certificate fixtures and exact CA11 resource evidence are required.

Packet materialization, verify/finalize, D16, downloads, scheduling changes, current-site access, controlled testing, and production activation remain excluded. Runtime continues constructed-dark.

**Rejected alternatives.** Pre-authorizing implementation before blocked decisions, adding runtime wiring, or broadening the allowlist opportunistically is rejected.

**Security/correctness justification.** A mechanical allowlist and complete disposable matrix make the future change reviewable without opening production surfaces.

**Affected classes/contracts.** Proposed paths only; no present code change.

**Retained-data and compatibility impact.** None from this proposal.

**Named regression evidence required.** `CERT-MATRIX-PHP83-PHP85-THREE-DATABASES`; `CERT-MATRIX-RETAINED-SUITES`; `CERT-MATRIX-PHP-N-FOCUSED`; `CERT-BOUNDARY-ZERO-RUNTIME-REGISTRATION`; `CERT-BOUNDARY-ZERO-CURRENT-SITE-ACCESS`; `CERT-BOUNDARY-ZERO-SCHEMA-CHANGE`; `CERT-BOUNDARY-D16-PACKET-VERIFY-DOWNLOAD-DEFERRED`; `CERT-ALLOWLIST-STRATEGY-PATHS-REQUIRE-AMENDMENT`.

**Exact owner-approval wording.** `Approve CA20 as recommended: the common candidate paths and matrix are proposals only, strategy-specific paths require a later amendment, constructed-dark remains unchanged, and all downstream, current-site, controlled-test, and activation gates remain closed.`

## 6. Requirement-to-regression traceability

Every regression identifier is defined exactly once in its decision's **Named regression evidence required** line. This table maps each mandatory requirement to that owning named-regression set without duplicating identifiers.

| Requirement | Owning named-regression set |
|---|---|
| Certificate-free capture unchanged | CA06 membership and CA19 compatibility |
| One/multiple required certificates and canonical order | CA06 membership |
| Missing/duplicate-course/substituted/reordered/additional assets | CA06 membership |
| Shared reference with distinct course assets | CA06 membership |
| Reference/source-version mismatch | CA05 input and CA08 drift |
| Selected-strategy provenance drift before/after acquisition | CA07 provenance and CA08 drift |
| Producer attestation mismatch | CA05 input and CA07 provenance |
| No cookies/nonces/network/loopback/disabled TLS | CA09 network |
| Byte equality/overflow and PDF rejection | CA10 PDF |
| Page/memory/duration/temp ceilings | CA11 resource/checkpoint |
| Checkpoints and cancellation cleanup | CA11 resource/checkpoint and CA17 fencing |
| Traversal/symlink/collision/public-root | CA13 storage |
| Deterministic expected digest before committed-object reuse/mismatch | CA12 retry, CA13 storage, CA15 ordering, and CA17 recovery |
| Crash around staging/object/database commit | CA17 crash/fence/concurrency |
| Snapshot/artifact/event binding | CA14 manifest and CA15 transaction ordering |
| Rollback residue and report-only orphan | CA15 transaction ordering |
| Authoritative invalidity versus local operational failure | CA16 failure |
| Sanitized failure tuples | CA16 failure and CA18 privacy |
| Stream-only collector and coordinator-owned metadata | CA04 collector |
| One strategy and no mixed/provisional envelope | CA02 strategy and CA05 input |
| PHP/database compatibility and dark boundaries | CA20 matrix/boundary |

Every negative implementation regression must assert the exact exception class, category/reason/context tuple, zero unintended database residue, zero unintended filesystem residue outside its isolated root, zero sensitive output, and exact fence behavior.

## 7. Required golden and concurrency evidence before implementation acceptance

The later owner-authorized implementation must provide independent literals rather than deriving expected values through production code:

- the later-approved certificate artifact-ID domain vectors;
- the later-approved source identifier, filename, role key, storage key, descriptor, evidence-asset row, ordered ID/digest arrays, complete snapshot JSON/digest, and `EvidenceSnapshotCaptured` payload vectors;
- identical certificate-free snapshot/event/receipt vectors from before the slice;
- selected-strategy producer/provenance and byte-deterministic PDF vectors on PHP 8.3.30 and 8.5.7;
- two real archive database connections proving one task owner and stale-fence rejection;
- crash injection before/after staging, each immutable object commit, UoW commit, and task completion; and
- three database-engine cells per runtime using only approved disposable database names and credentials.

## 8. Unresolved implementation-blocking owner gates

1. Selection of exactly one acquisition strategy: owned byte-deterministic local production or an authenticated immutable upstream object.
2. The selected strategy's exact CA04 method signature and CA05 closed input envelope, with no mixed-strategy fields.
3. The selected strategy's exact provenance descriptor: local template/font/CSS/image/locale/rendering package, or authoritative upstream object/source/version/digest contract.
4. Whether E07/E08 requires a versioned extension, including exact review/capture/acquisition parity and compatibility rules.
5. Exact artifact-ID formula/domain, source identifier, filename/storage-key vectors, producer key/version, code manifest, installed-code attestation, licensing disposition, and independent golden vectors.
6. A bounded semantic PDF parser, version, license, code-manifest digest, and adversarial fixture corpus.
7. Exact CA11 page, memory, per-certificate duration, per-capture duration, and temporary-storage integers based on representative sanitized evidence.
8. Representative sanitized certificate fixtures and proof that the selected acquisition inputs are closed and byte deterministic.
9. If nondeterministic production is ever proposed, a separate durable candidate journal/schema and compatibility/migration design; none is approved here.
10. A separate implementation authorization after gates 1-9 are approved.

These are deliberate stop conditions. Approval of this proposal approves their deferral, not guessed values or hidden defaults.

## 9. Explicit exclusions after proposal approval

- no certificate implementation or generator execution;
- no E07/E08, source query, snapshot, event, task, schema, canonical JSON, or digest-domain change;
- no packet materialization, verify/finalize, D16 lifecycle retry, download, reset, or projection rebuild;
- no HTTP, REST, loopback, remote storage, browser cookie, nonce, or TLS change;
- no WordPress hook, WP-Cron, Action Scheduler, CLI registration, controller, activation, or entrypoint change;
- no current-site, `wp-load.php`, `wp-config.php`, Docker, credential, or production database access;
- no stage, commit, push, deploy, activation, or pull request; and
- no `.claude/` access or modification.

## 10. Exact owner decision request

> **Approve Certificate Acquisition Decisions CA01-CA20 as written, including proposal-only scope; continued deferral of the installed LearnDash generators and all unapproved network routes; retention inside the original fenced capture_evidence task; the common stream-only collector boundary with coordinator-owned metadata; exact selection of one acquisition strategy before implementation; canonical one-course-specific-certificate-asset ordering with shared certificate references allowed; strategy-specific provenance, input, identity, source, producer, and E07/E08 contracts remaining provisional and implementation-blocking; bounded PDF and private-storage rules; explicit deferral of unmeasured page, memory, duration, and temporary-storage ceilings; byte-deterministic production under the current schema with current parity and expected-digest derivation before orphan reuse; blob-first and atomic snapshot/descriptor/event ordering; authoritative-invalidity versus event-free local/operational failure semantics; fencing, privacy, and compatibility contracts; the proposed but unauthorized common implementation candidates and PHP 8.3.30/8.5.7 three-database matrix; and every packet, verify/finalize, D16, download, scheduling, current-site, controlled-test, and activation deferral.**

Approval of that sentence does not authorize implementation. After approval, the unresolved gates in Section 8 still require specific owner decisions before any production or test code is changed.

## 11. Proposal verification handoff

This proposal must be verified by:

- PHP 8.3.30 boundaries and digests;
- PHP 8.5.7 boundaries and digests;
- UTF-8 without BOM and zero trailing whitespace;
- `git diff --check` plus an explicit untracked-proposal whitespace check;
- exactly 20 unique CA decision headings and 20 exact owner-approval clauses;
- unique named regression identifiers and a recalculated regression count;
- contradiction searches for nondeterministic current-schema recovery, collector-returned metadata, duplicate-reference rejection, local malformed-output lifecycle failure, and prematurely approved strategy-specific contracts;
- zero production, test, schema, metadata, entrypoint, or runtime changes;
- zero staged files; and
- `.claude/` remaining untouched.

The revised named-regression inventory is 148 identifiers, each defined once.

No disposable database matrix belongs to this proposal-only checkpoint.
