# Dual-Layer Archive Packet Renderer and Parser Feasibility Decisions Proposal

**Status:** proposal ready for owner review, not approved
**Date:** 2026-08-14
**Branch:** `feature/dual-layer-archive-slice-1b-packet-renderer-parser-feasibility-proposal`
**Parent and current HEAD:** `907151dc1acce4d987956801cbc00a618b6425e4`
**Scope:** proposal-only feasibility evidence; no renderer, parser, dependency, implementation, test, or runtime authority

## 1. Outcome

Current local evidence is insufficient to select either an archive-owned deterministic PDF renderer or an independent bounded semantic PDF parser.

This proposal therefore:

- selects no renderer and no parser;
- rejects the installed LearnDash TCPDF, Certificate Builder/mPDF integration, TCPDF parser, and both local FPDI copies as archive components in their present form;
- retains an archive-owned minimal renderer and sanitized archive-owned forks only as unresolved research candidates, not approved dependencies;
- preserves all PM01-PM20, CA01-CA20, and CS01-CS20 deferrals and retained-data contracts;
- approves no page, memory, duration, parser-expansion, object-count, recursion, or temporary-storage ceiling;
- defines the exact evidence needed for a later, separately authorized controlled research phase; and
- leaves packet implementation, its file allowlist, producer literals, package digests, golden vectors, D16, activation, and every downstream surface blocked.

The installed libraries prove that PDF production and structural import capability exist. They do not prove archive-safe byte determinism, independent semantic security validation, bounded hostile-document processing, complete redistribution authority, or cross-runtime/cross-OS reproducibility.

## 2. Preflight and authority

The mandatory starting state was confirmed before the proposal branch was created:

| Check | Confirmed value |
|---|---|
| Starting branch | `feature/dual-layer-archive-slice-1b-audit-packet-port-proposal` |
| Starting HEAD | `907151dc1acce4d987956801cbc00a618b6425e4` |
| Approved packet proposal at HEAD | `docs/superpowers/plans/2026-08-07-audit-packet-live-to-dual-layer-archive-port-plan.md` |
| Staged files | zero |
| Tracked modifications | zero |
| Permitted pre-existing untracked path | `.claude/` only; not opened or accessed |
| Entrypoint archive reference | exactly one accepted `require_once __DIR__ . '/includes/archive/bootstrap.php';` statement and no other archive reference |
| New branch | created directly at the same HEAD; no fetch, pull, merge, rebase, commit, or push |

Authority is applied in this order:

1. the accepted event-sourcing technical design and development handoff;
2. the formally approved packet architecture at HEAD, especially PM01-PM20;
3. the approved certificate-acquisition and certificate-strategy records, especially CA10-CA20 and CS06-CS20;
4. accepted P3B1, P3B2a, P3B2b, P3B3, and activation decisions and traceability;
5. accepted production contracts for canonical JSON, digests, tasks, fencing, snapshots, artifacts, private storage, events, and aggregate state; and
6. installed PDF code and license files as discovery evidence only.

Installed code does not override a frozen archive contract. Live packet or certificate code is not archive authority.

## 3. Frozen contract reconciliation

No contradiction was found that permits a package selection. The following PM/CA/CS constraints remain mandatory:

- packet v1 consumes only the authoritative sealed Snapshot v1 and exact retained bindings;
- `source.evidence_assets` is exactly `[]`; every course is certificate-free;
- `case.program_key` remains packet-input and digest bound;
- generic retained eight-field packet tasks fail event-free as `task_payload_invalid` before renderer, object, UoW, receipt, or lifecycle effects;
- `producer_version` is the implementation/template version and is never the package digest;
- `producer_package_digest` remains a distinct attested authority;
- the packet object remains within inclusive `1..67,108,864` bytes and all storage reads/writes/hashes use chunks no larger than `1,048,576` bytes;
- private-store PDF header/tail/xref checks remain structural checks, not semantic validation;
- `PacketMaterialized` means only that a candidate packet artifact was retained;
- all operational renderer/parser/storage/resource failures remain event-free under PM17;
- PM18 fencing, recovery-first ordering, exact object reuse, and no-overwrite rules remain unchanged;
- D16, certificate-bearing packets, verification/finalization, scheduling, downloads, current-site testing, controlled activation, production activation, and deployment remain deferred; and
- no package selection may silently create a new event, schema, canonical JSON format, digest domain, task type, snapshot field, descriptor field, or retained-data meaning.

## 4. Exact local source and license evidence

All paths below were inspected read-only. No package or renderer/parser code was executed.

### 4.1 Renderer-related evidence

| Candidate | Exact local evidence | Version evidence | License evidence | Static findings | Disposition |
|---|---|---|---|---|---|
| LearnDash TCPDF | `../sfwd-lms/includes/lib/tcpdf/` | `tcpdf.php` declares `6.11.2` | `LICENSE.TXT` states GNU LGPL version 3 or later | Constructor uses `time()`; file IDs/random seeds use random and microtime paths; creation/modification metadata and `/ID` are emitted; URL/cURL, temp-file, zlib, cache, font, and host-path behavior exists | `REJECTED_AS_INSTALLED`; archive-owned fork remains unselected research only |
| LearnDash Certificate Builder integration | `../learndash-certificate-builder/` and `src/component/class-pdf.php` | plugin header declares `1.1.5`, PHP `7.4` floor | no root `license.txt` was found; component licenses exist separately | reads `get_option()`, `get_post()`, current user, uploads path; applies filters; browser-oriented output is configurable | `REJECTED_AS_INSTALLED` |
| Certificate Builder prefixed mPDF | `../learndash-certificate-builder/vendor-prefixed/mpdf/mpdf/` | `vendor-prefixed/composer/installed.json` records `mpdf/mpdf` `v8.2.7` and PHP ranges through `~8.5.0` | installed metadata says `GPL-2.0-only`; `external/mpdf/mpdf/LICENSE.txt` contains GPL version 2 | current-time metadata/document IDs, random/temp names, time-based cache cleanup, optional HTTP/cURL fetching, mutable temp/config/font inputs, and zlib-dependent compression paths | `REJECTED_AS_INSTALLED`; archive-owned fork remains unselected research only |
| Archive-owned minimal renderer | no implementation exists locally | none | none | could reduce the surface, but layout, Unicode/font shaping, PDF correctness, licensing, maintenance, and deterministic output are wholly unproved | `UNRESOLVED_RESEARCH_CANDIDATE` |
| Other local PHP renderer | no other concrete package was found in installed plugin package metadata or PDF-related directories | none | none | absence is not proof of suitability | `NO_LOCAL_CANDIDATE_FOUND` |
| Continued renderer deferral | approved PM12 state | n/a | n/a | only disposition supported by complete current evidence | `CONTINUE_DEFERRAL` |

TCPDF static evidence includes `tcpdf.php` initialization of `file_id` through `TCPDF_STATIC::getRandomSeed()`, `doc_creation_timestamp = time()`, emitted `CreationDate`, `ModDate`, and `/ID`, plus cURL-capable and temporary-object paths. These are not automatically fatal to a controlled fork, but no locally approved patch, closed configuration, immutable manifest, or byte vector proves their elimination.

mPDF static evidence includes default compression, time-based metadata/cache behavior, `tempnam()` and `uniqid()` paths, configurable temporary directories, HTTP clients, and large bundled font/config surfaces. The existing Certificate Builder wrapper additionally injects mutable WordPress state. Version compatibility metadata is necessary evidence, but it is not runtime compatibility or determinism evidence.

### 4.2 Parser-related evidence

| Candidate | Exact local evidence | Version/license evidence | Semantic and bounding evidence | Disposition |
|---|---|---|---|---|
| TCPDF parser | `../sfwd-lms/includes/lib/tcpdf/tcpdf_parser.php` | distributed with TCPDF `6.11.2`; TCPDF `LICENSE.TXT` is LGPL-3.0-or-later | parses structural/xref data but no closed semantic rejection API or explicit object, recursion, stream-expansion, memory, and elapsed limits were found; same trust root as TCPDF renderer | `REJECTED_AS_SEMANTIC_VALIDATOR` |
| Certificate Builder prefixed FPDI | `../learndash-certificate-builder/vendor-prefixed/setasign/fpdi/` | `Fpdi.php` declares `2.6.4`; installed metadata and `external/setasign/fpdi/LICENSE.txt` say MIT | rejects encrypted files and detects many malformed xref cases, but the free parser rejects compressed xref streams and has no closed policy for JavaScript, actions, launch, files, rich media, XFA, AcroForm, or external references; no approved decompression/object/time bounds | `REJECTED_AS_SEMANTIC_VALIDATOR` |
| Plugin-bundled FPDI | `includes/lib/fpdi/` | `Fpdi.php`, `Tcpdf/Fpdi.php`, and `Tfpdf/Fpdi.php` declare `2.6.0`; source headers mention MIT but no local license file was found in this copy | same functional/semantic gaps; also incomplete local redistribution evidence | `REJECTED_AS_SEMANTIC_VALIDATOR` |
| mPDF internal import/parser paths | prefixed mPDF tree above | mPDF `v8.2.7`, GPL-2.0-only evidence above | renderer-internal parsing is not an independent trust root and no required fail-closed bounded semantic policy was found | `REJECTED_AS_INDEPENDENT_VALIDATOR` |
| Other local semantic parser or external CLI | no concrete package or approved executable was found locally | none | no evidence | `NO_LOCAL_CANDIDATE_FOUND` |
| Continued parser deferral | approved PM13/CS14 state | n/a | only disposition supported by complete current evidence | `CONTINUE_DEFERRAL` |

FPDI import success is useful for page import, not proof that a hostile PDF lacks active content or parser abuse paths. Its encryption rejection and xref error handling do not close the required semantic policy. A renderer's own parser is not independent validation.

### 4.3 Package, dependency, font, and license evidence

The prefixed Composer inventory records at least mPDF `v8.2.7` (`GPL-2.0-only`), FPDI `v2.6.4` (MIT), myclabs/deep-copy `1.13.4` (MIT), paragonie/random_compat `v9.99.100` (MIT), psr/http-message `2.0` (MIT), and psr/log `1.1.4` (MIT). This is an inventory source, not an immutable archive SBOM or attestation.

The TCPDF font directory contains 13 PHP metric files and 9 compressed font-data files; no matching license/readme/info file was found in that directory. The mPDF font directory contains 65 TTF and 6 OTF files plus heterogeneous font information/license text files, including DejaVu, Dhyana, GNU FreeFont, Jomolhari, Khmer, Lateef, Lohit Kannada, OCR-B, SyrCOM, Taamey David, Tharlon, and XW Zar notices. Presence of notices is not a legal conclusion or proof that a proposed archive subset may be redistributed. An exact selected font subset, notices, modification obligations, and owner/legal approval remain required.

## 5. Candidate comparison

### 5.1 Renderer comparison

| Requirement | TCPDF 6.11.2 as installed | Certificate Builder/mPDF 8.2.7 as installed | Archive-owned fork | Minimal archive-owned renderer |
|---|---|---|---|---|
| Runs without WordPress/LearnDash | library may, installed integration does not establish a closed package | raw library may, installed wrapper does not | unproved | unproved |
| Snapshot-only inputs | not enforced | not enforced | possible, unproved | possible, unproved |
| Ambient time/randomness eliminated | no | no | unproved | unproved |
| Stable metadata/object IDs/order | unproved | unproved | unproved | unproved |
| Pinned immutable fonts/assets/templates | no approved manifest | no approved manifest | unproved | unproved |
| Network/hooks/uploads prohibited mechanically | no | no | unproved | unproved |
| PHP 8.3.30/8.5.7 byte identity | no evidence | metadata compatibility only; no byte evidence | no evidence | no evidence |
| Windows/eventual production OS byte identity | no evidence | no evidence | no evidence | no evidence |
| Measurable bounded resources | theoretically measurable, not measured | theoretically measurable, not measured | not measured | not measured |
| Complete licensing/SBOM/attestation | no | no | no | no |
| Selection outcome | rejected as installed | rejected as installed | unresolved research | unresolved research |

### 5.2 Parser comparison

| Requirement | TCPDF parser | FPDI 2.6.4 | FPDI 2.6.0 | mPDF internal parser |
|---|---|---|---|---|
| Independent from a selected renderer | no if TCPDF selected | no if mPDF/Certificate Builder selected; otherwise still unapproved | potentially separate from TCPDF but bundled with the plugin's live PDF stack | no |
| Encryption rejection | partial evidence | explicit rejection | explicit rejection by same family | unproved as closed policy |
| Full active-content rejection | no evidence | no evidence | no evidence | no evidence |
| Xref table and xref-stream support | structural parsing exists | compressed xref stream unsupported by free parser | same family limitation | not established as validation API |
| Unresolved-reference/trailing-payload proof | not closed | not closed | not closed | not closed |
| Object/recursion/decompression ceilings | not found | not approved | not approved | not approved |
| Page count and maximum enforcement | not approved | page import count is not policy proof | same | not approved |
| Adversarial corpus and cross-runtime results | absent | absent | absent | absent |
| Selection outcome | rejected | rejected | rejected | rejected |

## 6. RF01-RF20 decisions

### RF01 — Checkpoint scope and non-goals

**Status.** Proposed; documentation only.

**Exact decision.** This checkpoint evaluates locally present renderer/parser evidence and either selects a fully proved component or continues deferral. It selects no component and authorizes no implementation, empirical execution, harness, dependency, package change, current-site read, PDF generation, parser execution, database, Docker, network, activation, or deployment.

**Evidence and rationale.** The approved packet architecture explicitly blocks PM12-PM15 and implementation until component, provenance, licensing, vector, and resource evidence is complete.

**Security and correctness implications.** Prevents a feasibility record from becoming an implicit supply-chain or runtime authorization.

**Explicit exclusions.** Packet code, tests, schema, metadata, entrypoint changes, package installation, Composer edits, and implementation allowlists.

**Remaining owner/operator evidence.** Every RF08-RF19 gate and a later separate implementation authorization.

**Evidence identifiers.** `[RETAINED_EXISTING] RF01-PROPOSAL-ONLY-BOUNDARY`; `[RETAINED_EXISTING] RF01-CONSTRUCTED-DARK-PRESERVED`; `[FUTURE_EXECUTABLE] RF01-NO-UNAUTHORIZED-SURFACE`; `[DEFERRED_OPERATOR_EVIDENCE] RF01-SEPARATE-IMPLEMENTATION-AUTHORITY`.

**Exact approval clause.** `Approve RF01's proposal-only scope and authorize no renderer, parser, research execution, or packet implementation.`

### RF02 — Evidence hierarchy

**Status.** Proposed.

**Exact decision.** Frozen PM/CA/CS contracts and accepted implementation outrank installed package capabilities. Exact local source, version metadata, license text, and static behavior are admissible feasibility evidence. Reputation, documentation not present locally, package names, hypothetical patches, and unexecuted compatibility claims are not selection evidence.

**Evidence and rationale.** Local code proves specific risks but lacks the tests, manifests, legal approval, and measurements needed to prove suitability.

**Security and correctness implications.** Blocks authority inversion and assumption-driven dependency approval.

**Explicit exclusions.** Web research, undocumented vendor claims, current-site behavior, and treating live packet output as a golden vector.

**Remaining owner/operator evidence.** Approved immutable package inputs, local license record, reproducible commands, and recorded outputs.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] RF02-AUTHORITY-ORDER-MECHANICAL`; `[FUTURE_EXECUTABLE] RF02-LOCAL-EVIDENCE-CITED`; `[FUTURE_EXECUTABLE] RF02-REPUTATION-NOT-EVIDENCE`; `[DEFERRED_OPERATOR_EVIDENCE] RF02-OWNER-EVIDENCE-ACCEPTANCE`.

**Exact approval clause.** `Approve RF02's evidence hierarchy and reject undocumented or non-local assumptions as selection evidence.`

### RF03 — Renderer evaluation criteria

**Status.** Proposed selection gate.

**Exact decision.** A renderer is selectable only when exact package/version/license provenance, closed snapshot-only inputs, no ambient WordPress/LearnDash/network/hook/path/time/locale/random state, pinned templates/fonts/assets/configuration, stable metadata/object order/compression/document IDs, PHP 8.3.30/8.5.7 and approved-OS byte identity, bounded-resource feasibility, immutable attestation, and independent literal vectors are all proved.

**Evidence and rationale.** Any failed criterion breaks deterministic expected-byte recovery or private archive authority.

**Security and correctness implications.** Prevents mutable output, dependency substitution, and retry-dependent artifacts.

**Explicit exclusions.** Weighted scoring, compensating controls for byte nondeterminism, and normalization after rendering.

**Remaining owner/operator evidence.** One candidate satisfying every criterion without an unapproved retained-data change.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] RF03-RENDERER-GATE-CLOSED`; `[FUTURE_EXECUTABLE] RF03-AMBIENT-INPUTS-REJECTED`; `[FUTURE_EXECUTABLE] RF03-DETERMINISM-ALL-RUNTIMES`; `[FUTURE_EXECUTABLE] RF03-NO-WEIGHTED-WAIVER`.

**Exact approval clause.** `Approve RF03's conjunctive renderer selection gate; no criterion may be waived by package reputation or scoring.`

### RF04 — Installed renderer findings

**Status.** Evidence-backed rejection as installed.

**Exact decision.** Reject installed LearnDash TCPDF `6.11.2` and Certificate Builder `1.1.5`/mPDF `v8.2.7` as archive renderers in their present installed forms. Their existing wrappers and reachable defaults do not satisfy snapshot-only authority, deterministic metadata, no-network/no-hook boundaries, immutable package ownership, or vector requirements.

**Evidence and rationale.** Sections 4.1 and 5.1 identify exact local files and static risks.

**Security and correctness implications.** Avoids live-state rereads, random/time-dependent bytes, mutable fonts/temp paths, and browser-oriented behavior.

**Explicit exclusions.** This rejection is not a permanent judgment on a future owner-approved, independently vendored and materially changed fork.

**Remaining owner/operator evidence.** A fork would be a new candidate with its own source manifest, license review, deterministic controls, measurements, and vectors.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] RF04-TCPDF-AS-INSTALLED-REJECTED`; `[FUTURE_EXECUTABLE] RF04-MPDF-AS-INSTALLED-REJECTED`; `[FUTURE_EXECUTABLE] RF04-WORDPRESS-WRAPPER-REJECTED`; `[DEFERRED_OPERATOR_EVIDENCE] RF04-FORK-TREATED-AS-NEW-CANDIDATE`.

**Exact approval clause.** `Approve RF04's rejection of the installed TCPDF and Certificate Builder/mPDF paths as archive renderers.`

### RF05 — Archive-owned renderer feasibility

**Status.** Unresolved research candidates; no selection.

**Exact decision.** Retain two research directions only: a sanitized archive-owned fork of a locally evidenced renderer and a minimal archive-owned PDF writer for the fixed PM08 packet. Neither is selected. A fork must remove or close all unused network, dynamic HTML/CSS, hook, font-discovery, ambient metadata, random, cache, and temp behavior. A minimal writer must independently prove Unicode/font shaping, layout correctness, PDF conformance, maintenance viability, and licensing.

**Evidence and rationale.** Both directions may be technically feasible, but no implementation or empirical proof exists.

**Security and correctness implications.** Keeps the research surface narrow without mistaking lower code volume for correctness.

**Explicit exclusions.** Building either candidate under this approval, selecting a base library, or inferring a producer key/version.

**Remaining owner/operator evidence.** RF19 authorization, exact source snapshots, closed patches, fixture output, and legal review.

**Evidence identifiers.** `[DEFERRED_OPERATOR_EVIDENCE] RF05-FORK-FEASIBILITY-MEASURED`; `[DEFERRED_OPERATOR_EVIDENCE] RF05-MINIMAL-WRITER-FEASIBILITY-MEASURED`; `[FUTURE_EXECUTABLE] RF05-UNUSED-SURFACE-ABSENT`; `[FUTURE_EXECUTABLE] RF05-UNICODE-LAYOUT-PROVED`.

**Exact approval clause.** `Approve RF05's two unselected research directions and select no archive-owned renderer yet.`

### RF06 — Parser evaluation criteria

**Status.** Proposed selection gate.

**Exact decision.** A parser is selectable only when it is independently attested from the renderer and, within approved input, object, recursion, decompressed-stream, memory, elapsed, and temporary-storage limits, rejects encryption; JavaScript; actions/open actions/additional actions; launch; embedded files/attachments/file specifications; rich media; XFA; AcroForm; external references; malformed objects/xref tables/xref streams; unresolved indirect references; trailing payload; recursive graph abuse; and decompression bombs. It must establish at least one page and enforce the future approved maximum.

**Evidence and rationale.** Structural import and lexical token searches do not establish a closed semantic policy.

**Security and correctness implications.** Provides a fail-closed independent trust boundary against hostile immutable PDFs.

**Explicit exclusions.** Renderer success, FPDI import success, `%PDF` checks, regex/token scans, browser display, and same-library re-import.

**Remaining owner/operator evidence.** Exact package/version/license, bounded API, adversarial corpus, cross-runtime results, and attestation.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] RF06-PARSER-GATE-CLOSED`; `[FUTURE_EXECUTABLE] RF06-ACTIVE-CONTENT-COMPLETE`; `[FUTURE_EXECUTABLE] RF06-STRUCTURAL-ABUSE-BOUNDED`; `[FUTURE_EXECUTABLE] RF06-PAGE-RANGE-ENFORCED`.

**Exact approval clause.** `Approve RF06's conjunctive independent semantic-parser selection gate.`

### RF07 — Installed parser findings

**Status.** Evidence-backed rejection.

**Exact decision.** Reject TCPDF's parser, FPDI `2.6.4`, plugin-bundled FPDI `2.6.0`, and mPDF internal parser paths as the required independent bounded semantic validator. Their available source does not prove the closed semantic policy and resource bounds; the local FPDI `2.6.0` copy also lacks a colocated license file.

**Evidence and rationale.** Section 4.2 records the exact source and the material gaps. FPDI's explicit encryption rejection is retained as a fact, not generalized into semantic safety.

**Security and correctness implications.** Avoids parser differential blindness and unbounded hostile-input acceptance.

**Explicit exclusions.** Treating rejected parser components as selected through a wrapper or lexical pre-scan.

**Remaining owner/operator evidence.** A different exact parser package or a separately owned parser implementation must pass RF06.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] RF07-TCPDF-PARSER-REJECTED`; `[FUTURE_EXECUTABLE] RF07-FPDI-264-REJECTED`; `[FUTURE_EXECUTABLE] RF07-FPDI-260-REJECTED`; `[FUTURE_EXECUTABLE] RF07-RENDERER-PARSER-TRUST-ROOT-REJECTED`.

**Exact approval clause.** `Approve RF07's rejection of every locally installed parser candidate as the archive semantic validator.`

### RF08 — Renderer selection or continued deferral

**Status.** Continued deferral recommended.

**Exact decision.** Select no renderer. PM12 remains implementation blocking. The only approved outcome is to continue deferral until one exact candidate satisfies RF03 through controlled evidence accepted by a later owner amendment.

**Evidence and rationale.** Every concrete local candidate fails at least determinism, immutable ownership, provenance, or licensing completeness; unimplemented candidates have no evidence.

**Security and correctness implications.** Preserves deterministic orphan recovery and prevents mutable package substitution.

**Explicit exclusions.** Producer key/version, package digest, template, font subset, configuration, filename, implementation path, or golden hash selection.

**Remaining owner/operator evidence.** RF19 research followed by an evidence-complete selection decision.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] RF08-NO-RENDERER-SELECTED`; `[FUTURE_EXECUTABLE] RF08-PM12-STILL-BLOCKED`; `[FUTURE_EXECUTABLE] RF08-NO-PRODUCER-LITERALS`; `[DEFERRED_OPERATOR_EVIDENCE] RF08-LATER-SELECTION-AMENDMENT`.

**Exact approval clause.** `Approve RF08's continued renderer deferral and select no renderer or producer literal.`

### RF09 — Parser selection or continued deferral

**Status.** Continued deferral recommended.

**Exact decision.** Select no parser. PM13 and CS14 remain implementation blocking. A later parser selection must satisfy RF06 and must not share the selected renderer's decisive parse/acceptance trust root.

**Evidence and rationale.** No local component provides proved semantic coverage and bounded hostile-input execution.

**Security and correctness implications.** Prevents structural checks or importer behavior from being promoted into a security guarantee.

**Explicit exclusions.** Parser key/version, parser manifest digest, acceptance API, limits, or exception mapping.

**Remaining owner/operator evidence.** Exact candidate acquisition, local license review, adversarial execution, and owner selection.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] RF09-NO-PARSER-SELECTED`; `[FUTURE_EXECUTABLE] RF09-PM13-CS14-STILL-BLOCKED`; `[FUTURE_EXECUTABLE] RF09-NO-PARSER-LITERALS`; `[DEFERRED_OPERATOR_EVIDENCE] RF09-LATER-SELECTION-AMENDMENT`.

**Exact approval clause.** `Approve RF09's continued parser deferral and select no parser or parser authority.`

### RF10 — Immutable package manifest and SBOM

**Status.** Requirements proposed; bytes and digest blocked.

**Exact decision.** A selected renderer and parser each require a closed immutable manifest and SBOM covering every shipped source file, autoloader, patch, template, font, asset, configuration file, transitive dependency, license/notice, build input, and expected relative path. Manifest canonical bytes, digest domain, literal SHA-256, signing/attestation authority, update process, and rollback policy require a later owner decision; none is invented here.

**Evidence and rationale.** Composer installed metadata and component license files are useful inventory but do not bind the deployed byte set.

**Security and correctness implications.** Detects silent library, font, asset, or patch substitution.

**Explicit exclusions.** Version-string-only attestation, mutable Composer resolution at runtime, remote package lookup, and ordinary options/environment claims.

**Remaining owner/operator evidence.** Reproducible manifest generation, independent review, SBOM, vulnerability disposition, and immutable deployment proof.

**Evidence identifiers.** `[DEFERRED_OPERATOR_EVIDENCE] RF10-RENDERER-MANIFEST-COMPLETE`; `[DEFERRED_OPERATOR_EVIDENCE] RF10-PARSER-MANIFEST-COMPLETE`; `[DEFERRED_OPERATOR_EVIDENCE] RF10-SBOM-AND-VULNERABILITY-REVIEW`; `[FUTURE_EXECUTABLE] RF10-DEPLOYED-BYTES-ATTESTED`.

**Exact approval clause.** `Approve RF10's manifest and SBOM evidence requirements while approving no manifest bytes, digest, or authority.`

### RF11 — Producer identity and package attestation

**Status.** Existing distinction retained; literals blocked.

**Exact decision.** Preserve three separate authorities: `producer_key`, `producer_version` as implementation/template version, and `producer_package_digest` as the closed package-manifest authority. A non-configurable code attestor must prove the exact approved deployed package before rendering and before UoW submission. Equality or substitution between version and digest is forbidden.

**Evidence and rationale.** PM11-PM12 already freeze the semantic distinction; no local candidate supplies approved literals.

**Security and correctness implications.** Prevents a caller or mutable setting from claiming an approved producer.

**Explicit exclusions.** Task/request/option/global/filter/environment authority and deriving the package digest from output bytes.

**Remaining owner/operator evidence.** Exact literals and non-configurable attestor implementation after component selection.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] RF11-PRODUCER-AUTHORITIES-DISTINCT`; `[FUTURE_EXECUTABLE] RF11-CONFIG-SPOOF-REJECTED`; `[FUTURE_EXECUTABLE] RF11-ATTEST-BEFORE-RENDER`; `[FUTURE_EXECUTABLE] RF11-ATTEST-BEFORE-UOW`.

**Exact approval clause.** `Approve RF11's retained producer identity separation and keep all producer literals and attestation implementation blocked.`

### RF12 — Deterministic metadata, font, layout, and compression rules

**Status.** Required controls identified; exact configuration blocked.

**Exact decision.** A later renderer selection must close PDF version, page geometry, units, coordinate rounding, text normalization, fallback rules, line breaking, table pagination, font subset and embedding, glyph ordering, metadata, creation/modification fields, document IDs, object numbering/order, stream order, compression algorithm/level, locale, timezone, and all error/warning handling. Ambient clock, randomness, system fonts, font discovery, URL assets, mutable caches, and host defaults are prohibited.

**Evidence and rationale.** Installed renderer source exposes time/random/compression/font/temp variability, and no fixed packet package exists.

**Security and correctness implications.** Makes identical authoritative inputs capable of producing expected bytes before orphan reuse.

**Explicit exclusions.** Ignoring metadata in comparisons, post-render normalization, and per-platform expected hashes.

**Remaining owner/operator evidence.** Exact settings, immutable font/template/assets, and literal byte vectors.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] RF12-METADATA-DETERMINISTIC`; `[FUTURE_EXECUTABLE] RF12-FONTS-AND-LAYOUT-PINNED`; `[FUTURE_EXECUTABLE] RF12-COMPRESSION-OBJECT-ORDER-STABLE`; `[FUTURE_EXECUTABLE] RF12-AMBIENT-STATE-ABSENT`.

**Exact approval clause.** `Approve RF12's deterministic rendering control requirements while freezing no renderer-specific configuration.`

### RF13 — Semantic PDF validation policy

**Status.** Retained policy; implementation blocked.

**Exact decision.** Preserve PM13/CA10/CS14 exactly. The selected parser must fail closed for every prohibited semantic or structural construct, must prove `page_count >= 1`, and must enforce the later owner-approved page maximum. Validation must occur before authoritative descriptor/event submission and cannot rely on lexical scans or the renderer's success.

**Evidence and rationale.** No local parser satisfies the policy. Private-store structural validation remains necessary but insufficient.

**Security and correctness implications.** Prevents immutable retention of active, external, encrypted, malformed, or parser-abusive content.

**Explicit exclusions.** Policy weakening, warning-only acceptance, unsupported-construct fallback, and exception-message classification.

**Remaining owner/operator evidence.** Exact parser, limits, corpus, fail-closed result grammar, and differential evidence.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] RF13-SEMANTIC-POLICY-COMPLETE`; `[FUTURE_EXECUTABLE] RF13-ZERO-PAGE-REJECTED`; `[FUTURE_EXECUTABLE] RF13-PAGE-MAX-ENFORCED`; `[FUTURE_EXECUTABLE] RF13-LEXICAL-SCAN-INSUFFICIENT`.

**Exact approval clause.** `Approve RF13's unchanged semantic PDF policy while keeping parser implementation and limits blocked.`

### RF14 — Cross-runtime reproducibility and golden vectors

**Status.** Requirement retained; feasibility unproved.

**Exact decision.** Identical packet input and attested package must yield byte-identical PDF bytes, byte count, content SHA-256, page count, and parser result on PHP 8.3.30 and PHP 8.5.7. The same must be demonstrated on Windows development and the exact future production OS/image. If cross-OS bytes differ, no component may be selected under packet v1 without a separate owner-approved platform-constrained producer/version and retained-compatibility decision. PHP 8.4 is supplemental only.

**Evidence and rationale.** No renderer was executed and no production OS is approved. Version metadata does not prove byte identity.

**Security and correctness implications.** Preserves expected-byte recovery and avoids platform-dependent immutable artifacts.

**Explicit exclusions.** One-runtime evidence, per-OS hashes accepted as equivalent, normalization after output, and learning hashes from committed objects.

**Remaining owner/operator evidence.** Independent literal task/input/identity/manifest/descriptor/PDF/event vectors and exact OS image attestation.

**Evidence identifiers.** `[DEFERRED_OPERATOR_EVIDENCE] RF14-PHP83-PHP85-BYTE-VECTOR`; `[DEFERRED_OPERATOR_EVIDENCE] RF14-WINDOWS-PRODUCTION-OS-BYTE-VECTOR`; `[FUTURE_EXECUTABLE] RF14-INDEPENDENT-LITERAL-VECTORS`; `[FUTURE_EXECUTABLE] RF14-PER-PLATFORM-HASH-WAIVER-REJECTED`.

**Exact approval clause.** `Approve RF14's cross-runtime and cross-OS reproducibility gate while approving no golden bytes or platform exception.`

### RF15 — Representative and adversarial fixture corpus

**Status.** Corpus categories proposed; fixture bytes blocked.

**Exact decision.** A future corpus must contain sanitized synthetic smallest-valid, typical, largest-valid Snapshot v1 under existing canonical limits, longest-approved strings, long program/course names, NFC Unicode, complex pinned-font coverage, null/empty optional fields, multi-page tables, and fixed course-order cases. The parser corpus must contain independently constructed encryption, JavaScript, all action forms, launch, files/attachments, rich media, XFA, AcroForm, external reference, zero-page, malformed object/xref/xref-stream, unresolved reference, trailing payload, recursive graph, and compressed-stream-bomb cases.

**Evidence and rationale.** No such approved repository corpus exists. The maximum supported course count is not inferred; the future largest-valid fixture must prove the count permitted by the existing 1 MiB/10,000-value canonical contract.

**Security and correctness implications.** Ensures measurements and semantic results cover normal and hostile boundaries.

**Explicit exclusions.** Current-site data, production PII, live PDFs, copyrighted customer assets, and fixtures generated by the parser under test alone.

**Remaining owner/operator evidence.** Fixture provenance, legal/privacy review, literal hashes, independent malicious-fixture construction, and owner approval.

**Evidence identifiers.** `[DEFERRED_OPERATOR_EVIDENCE] RF15-REPRESENTATIVE-CORPUS-APPROVED`; `[DEFERRED_OPERATOR_EVIDENCE] RF15-ADVERSARIAL-CORPUS-APPROVED`; `[FUTURE_EXECUTABLE] RF15-MAXIMUM-VALID-SNAPSHOT-FIXTURE`; `[FUTURE_EXECUTABLE] RF15-NO-CURRENT-SITE-DATA`.

**Exact approval clause.** `Approve RF15's corpus requirements while approving no fixture bytes, provenance, or inferred course ceiling.`

### RF16 — Resource-measurement methodology

**Status.** Method proposed; all new ceilings blocked.

**Exact decision.** Preserve only packet bytes `1..67,108,864` and chunk maximum `1,048,576`. For every RF15 fixture, a controlled run must separately record rendered bytes, page count, peak PHP memory above a measured baseline, renderer duration, parser duration, total duration through cleanup, temporary-storage peak bytes/files, parsed object count, cumulative decompressed-stream bytes, and maximum reference/recursion depth. Runs require PHP 8.3.30 and 8.5.7 on Windows and the exact production OS, cold and warm repetitions, deterministic outputs, and fail-closed boundary/one-over fixtures. Exact inclusive ceilings require a later owner decision based on results and operational margin.

**Evidence and rationale.** Output bytes do not bound layout or hostile parse expansion; no empirical data was authorized here.

**Security and correctness implications.** Prevents denial of service and dependence on host defaults.

**Explicit exclusions.** Invented limits, `memory_limit` as measurement, web timeouts, averages without maxima, and measurements from a different component/version.

**Remaining owner/operator evidence.** Raw sanitized measurements, methodology review, exact integers, cancellation mechanism, and cleanup proof.

**Evidence identifiers.** `[DEFERRED_OPERATOR_EVIDENCE] RF16-MEASUREMENT-DATASET-COMPLETE`; `[DEFERRED_OPERATOR_EVIDENCE] RF16-CROSS-RUNTIME-OS-MEASUREMENTS`; `[FUTURE_EXECUTABLE] RF16-CEILING-EQUALITY-AND-OVERFLOW`; `[FUTURE_EXECUTABLE] RF16-CLEANUP-INCLUDED-IN-DURATION`.

**Exact approval clause.** `Approve RF16's measurement methodology and retained byte/chunk limits; approve no other resource ceiling.`

### RF17 — Licensing and redistribution gate

**Status.** Local facts recorded; legal/owner decision blocked.

**Exact decision.** No component may be selected until owner-controlled legal review accepts the exact license set, redistribution method, notices, source/modification obligations, combined-work implications, font/asset rights, transitive dependencies, and update/vulnerability process for the exact vendored bytes. This proposal makes no legal guarantee.

**Evidence and rationale.** TCPDF LGPL-3.0-or-later, mPDF GPL-2.0-only, FPDI MIT, transitive MIT packages, heterogeneous font notices, the missing root Certificate Builder license path, and the plugin FPDI copy's missing local license file require component-specific review.

**Security and correctness implications.** Prevents unapproved redistribution and incomplete supply-chain records.

**Explicit exclusions.** Inferring rights from plugin installation, SPDX strings alone, or a license file belonging to another bundled copy.

**Remaining owner/operator evidence.** Written legal/owner disposition, notice bundle, source-offer/patch policy where applicable, font subset rights, and SBOM approval.

**Evidence identifiers.** `[DEFERRED_OPERATOR_EVIDENCE] RF17-COMPONENT-LICENSE-REVIEW`; `[DEFERRED_OPERATOR_EVIDENCE] RF17-FONT-ASSET-RIGHTS-REVIEW`; `[DEFERRED_OPERATOR_EVIDENCE] RF17-NOTICE-AND-SOURCE-OBLIGATIONS`; `[DEFERRED_OPERATOR_EVIDENCE] RF17-SUPPLY-CHAIN-OWNER-ACCEPTANCE`.

**Exact approval clause.** `Approve RF17's licensing and redistribution gate while making no legal conclusion or package selection.`

### RF18 — Security, failure, fencing, and recovery implications

**Status.** Existing PM17-PM19 rules retained; component-specific mapping blocked.

**Exact decision.** Missing/unattested renderer remains `operational_blocked / task_handler_failed / packet_generate`; missing/unattested parser remains `operational_blocked / task_handler_failed / packet_validate`; deterministic rendering/resource failures and semantic rejection remain the exact PM17 event-free tuples. Explicit transient interruption remains the only renderer retryable class already enumerated by PM17. All six exact fence reasons and zero-disposition lease-loss behavior remain unchanged. Recovery must derive expected bytes before object reuse, never overwrite, and replay authoritative success first.

**Evidence and rationale.** Component selection must fit the accepted closed grammar rather than invent vendor-message classifications.

**Security and correctness implications.** Prevents lifecycle facts from operational library failure and prevents stale-worker authority.

**Explicit exclusions.** Raw parser/renderer exceptions, new reason codes, message-based classification, automatic ArchiveFailed, object deletion, or D16 inference.

**Remaining owner/operator evidence.** Reachable exception/return-state enumeration for selected packages and mechanical one-tuple mapping.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] RF18-MISSING-RENDERER-EXACT-TUPLE`; `[FUTURE_EXECUTABLE] RF18-MISSING-PARSER-EXACT-TUPLE`; `[FUTURE_EXECUTABLE] RF18-FENCE-REASONS-UNCHANGED`; `[FUTURE_EXECUTABLE] RF18-RECOVERY-EXPECTED-BYTES-FIRST`.

**Exact approval clause.** `Approve RF18's unchanged PM17-PM19 failure, fence, and recovery implications; authorize no component-specific implementation.`

### RF19 — Proposed controlled research phase and authorization boundary

**Status.** Research plan proposed; execution not authorized.

**Exact decision.** A later owner authorization may permit one isolated, synthetic-data research phase with two sequential gates:

1. **Local-candidate gate:** evaluate only exact locally evidenced TCPDF `6.11.2`, mPDF `v8.2.7`, FPDI `v2.6.4`, FPDI `2.6.0`, and an owner-reviewed minimal-writer prototype. This gate may confirm rejection or feasibility; it cannot select a semantic parser that fails RF06.
2. **New-parser gate:** before any download, installation, or execution, a separate amendment must name the exact parser package/version/source archive, expected archive SHA-256, license, transitive dependencies, acquisition URL/authority, and research-only install path. No unnamed parser or latest-version resolution is authorized.

The proposed research-only paths are:

- `tests/archive/feasibility/packet-renderer-parser/` for a future explicitly authorized harness;
- `tests/archive/fixtures/packet-renderer-parser/` for reviewed synthetic fixtures;
- `docs/superpowers/evidence/packet-renderer-parser/` for sanitized machine-readable results, manifests, notices, and a human review summary; and
- a unique OS temporary directory created by the harness and resolved beneath the process temp root for invocation-owned files only.

The future harness boundary is a direct PHP CLI command naming one research test file and one candidate ID. It may load only the research copy, canonical fixture bytes, and standard-library measurement helpers. It may not bootstrap WordPress, load `wp-config.php`/`wp-load.php`, use global `$wpdb`, read current-site paths/data, use network at runtime, access cookies/nonces/URLs, or write outside the research evidence root and validated invocation temp root. Downloads and package installation remain prohibited until the new-parser amendment expressly authorizes the exact artifact. Every run must emit only sanitized JSON/Markdown measurements, byte hashes, package/file manifests, pass/fail policy results, stderr-leak checks, and cleanup evidence. Generated fixture PDFs remain research evidence, never archive artifacts.

Cleanup must close handles, remove only the resolved invocation-owned temporary directory, retain reviewed evidence outputs, and prove no path escape. Stop on package/hash/license mismatch, unexpected dependency/network request, nondeterministic repeated bytes, unbounded behavior, sensitive output, unsupported construct, cleanup failure, or any need for current-site data.

**Evidence and rationale.** Static analysis can reject installed paths but cannot prove deterministic output, parser behavior, or resource ceilings. The second gate prevents this proposal from inventing a parser candidate.

**Security and correctness implications.** Makes empirical work reproducible, synthetic, isolated, and owner-bounded.

**Explicit exclusions.** Execution under RF01-RF20 approval, production paths, Composer mutation, current-site data, live certificates/packets, network during a run, and selection by a research harness alone.

**Remaining owner/operator evidence.** Separate exact authorization, reviewed candidate archives/hashes/licenses, approved fixtures, available production-OS runner, and output-retention decision.

**Evidence identifiers.** `[DEFERRED_OPERATOR_EVIDENCE] RF19-RESEARCH-AUTHORIZATION-SEPARATE`; `[FUTURE_EXECUTABLE] RF19-RESEARCH-PATH-CONTAINMENT`; `[FUTURE_EXECUTABLE] RF19-NO-CURRENT-SITE-OR-NETWORK`; `[FUTURE_EXECUTABLE] RF19-STOP-CONDITIONS-ENFORCED`.

**Exact approval clause.** `Approve RF19's controlled-research design only; do not authorize its execution, downloads, installation, harness creation, or package selection.`

### RF20 — Remaining gates and exact owner response

**Status.** Continued deferral recommended.

**Exact decision.** Approval of RF01-RF20 records the feasibility evidence, rejects local packages as installed archive components, and continues renderer/parser deferral. It does not approve a research execution, implementation allowlist, producer/parser literals, package digest, fixtures, ceilings, vectors, packet handler, runtime registration, or activation. A later evidence-complete component selection and a still-later explicit implementation authorization are both required.

**Evidence and rationale.** The required renderer, parser, resource, provenance, licensing, and reproducibility evidence is absent.

**Security and correctness implications.** Leaves the archive constructed-dark and prevents partial feasibility from becoming production authority.

**Explicit exclusions.** Packet/certificate implementation; verification/finalization; D16; schema/digest/event/task changes; scheduling; controllers; downloads; current-site testing; controlled activation; production activation; deployment.

**Remaining owner/operator evidence.** Sections 8 and 9 in full.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] RF20-NO-IMPLEMENTATION-ALLOWLIST`; `[FUTURE_EXECUTABLE] RF20-PM-CA-CS-DEFERRALS-INTACT`; `[DEFERRED_OPERATOR_EVIDENCE] RF20-COMPONENT-SELECTION-SEPARATE`; `[DEFERRED_OPERATOR_EVIDENCE] RF20-IMPLEMENTATION-AUTHORIZATION-SEPARATE`.

**Exact approval clause.** `Approve RF20's continued deferral, remaining gates, and two-checkpoint selection-then-implementation sequence.`

## 7. Evidence inventory

The RF01-RF20 sections define 80 unique identifiers, exactly four per decision:

| Classification | Count | Meaning |
|---|---:|---|
| `RETAINED_EXISTING` | 2 | Existing constructed-dark/proposal boundaries used as retained evidence |
| `FUTURE_EXECUTABLE` | 55 | A later selected-component implementation or controlled research harness must execute these mechanically |
| `DEFERRED_OPERATOR_EVIDENCE` | 23 | Owner/legal/supply-chain/measurement/authorization evidence; not a passing executable assertion |
| **Total** | **80** | Unique evidence identifiers |

Deferred operator evidence must never be reported as an executed passing assertion. Existing boundary/digest results validate only retained dark-mode contracts; they do not select a renderer/parser.

## 8. Complete remaining blockers

Renderer selection remains blocked by:

1. exact archive-owned package/source decision;
2. closed deterministic patches/configuration;
3. immutable template, font, asset, and configuration set;
4. exact producer key, implementation/template version, package manifest bytes/digest, and non-configurable attestation;
5. PHP 8.3.30/8.5.7 and Windows/production-OS byte identity;
6. independent literal vectors;
7. representative resource measurements and approved ceilings;
8. complete license/notice/font/asset/transitive-dependency disposition; and
9. owner acceptance of supply-chain maintenance and vulnerability policy.

Parser selection remains blocked by:

1. an exact independent candidate package/version/source/license;
2. full RF06 semantic coverage;
3. explicit input/object/recursion/decompression/memory/time/temp bounds;
4. positive page-count and future page-maximum enforcement;
5. adversarial and differential corpus results on both PHP runtimes and both OS targets;
6. immutable manifest/SBOM/attestation;
7. exact fail-closed API and PM17 mapping; and
8. legal/owner acceptance.

Packet implementation additionally remains blocked by the accepted PM gates: producer-bound literals, measured ceilings, aggregate/coordinator query surface, exact implementation allowlist, D16, certificate compatibility, verification/finalization, and separate implementation authorization.

## 9. Proposal verification requirements

This proposal-only pass must confirm:

- PHP 8.3.30 boundary and digest suites;
- PHP 8.5.7 boundary and digest suites;
- PHP 8.4 only if a real CLI exists and runs;
- RF01-RF20 exactly once as decision headings;
- one exact approval clause per RF decision;
- all 80 evidence identifiers unique and classifications totaling 80;
- every concrete candidate has an explicit disposition;
- no renderer/parser, package digest, producer literal, golden hash, new ceiling, implementation path, or implementation allowlist is approved;
- PM01-PM20, CA01-CA20, and CS01-CS20 remain intact;
- UTF-8 without BOM, zero trailing whitespace, and clean `git diff --check` including the untracked proposal;
- unchanged plugin entrypoint and zero production, test, schema, metadata, runtime, or tracked-file changes;
- zero staged files; and
- only `.claude/` and this proposal are untracked, with `.claude/` untouched.

No database matrix, Docker action, network request, current-site access, PDF rendering, parser execution, generator execution, harness, dependency installation, staging, commit, push, activation, or deployment belongs to this checkpoint.

## 10. Exact owner decision request

> **Approve Packet Renderer/Parser Feasibility Decisions RF01-RF20 as written for proposal-only architecture: select no renderer and no parser; reject installed LearnDash TCPDF 6.11.2, LearnDash Certificate Builder 1.1.5 with mPDF 8.2.7, the TCPDF parser, FPDI 2.6.4, plugin-bundled FPDI 2.6.0, and mPDF internal parsing as archive components in their present forms; retain archive-owned forks and a minimal renderer only as unselected research directions; preserve PM01-PM20, CA01-CA20, CS01-CS20, deterministic expected-byte recovery, distinct producer version/package-digest authority, semantic PDF policy, private-storage limits, event-free operational failures, fencing, D16, certificate-bearing packet, verification/finalization, and activation deferrals; require a separately approved controlled research phase and later evidence-complete component-selection amendment; and authorize no downloads, installation, harness, empirical execution, implementation allowlist, packet code, current-site access, runtime wiring, activation, or deployment.**

Approval of this sentence approves documentation of continued deferral and the future evidence boundary only. It does not authorize RF19 execution or any packet implementation.

**Approval record:** On 2026-08-14, the owner formally approved RF01-RF20 exactly as quoted above for documentation-only publication. No renderer or parser was selected. Controlled research execution, downloads, installation, harness creation, empirical PDF work, component selection, implementation allowlists, packet implementation, current-site access, runtime wiring, activation, and deployment remain separately blocked.
