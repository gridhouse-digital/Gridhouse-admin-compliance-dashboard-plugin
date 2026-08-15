# Dual-Layer Archive Packet Renderer and Parser Controlled Research Decisions Proposal

**Status:** proposal ready for owner review, not approved
**Date:** 2026-08-15
**Branch:** `feature/dual-layer-archive-slice-1b-packet-renderer-parser-controlled-research-proposal`
**Parent and current HEAD:** `b86fbe56a631bfa8fbd83b4d15c5f197c3fecb49`
**Scope:** proposal-only design for a future controlled research phase; no acquisition, harness, fixture, execution, component selection, or packet implementation authority

## 1. Recommended outcome

Approve only the controlled-research design in CR01-CR20. Authorize none of the later actions.

Current local evidence can identify two fork directions and one source-owned direction for renderer research, but it cannot admit any renderer candidate for execution:

- the installed TCPDF tree identifies version `6.11.2` and an LGPL-3.0-or-later license text, but no authoritative source archive, upstream source revision, expected source-archive SHA-256, approved archive-owned patch set, or complete font/license manifest is present;
- the Certificate Builder trees identify mPDF `v8.2.7`, source revision `b59670a09498689c33ce639bac8f5ba26721dab3`, GPL-2.0-only component metadata, and a local GPL version 2 text, but no locally retained authoritative source archive or expected archive SHA-256 exists; and
- a minimal archive-owned writer has no source, immutable revision, license disposition, layout proof, or package manifest yet.

No acceptable independent semantic-parser candidate can be named from local authoritative evidence. TCPDF parsing, FPDI `2.6.4`, plugin-bundled FPDI `2.6.0`, and mPDF internal parsing remain rejected under RF07. A separately approved candidate-discovery and acquisition proposal is therefore mandatory before parser package download, installation, extraction, harness creation, or execution.

This record does not select a renderer or parser. It defines the evidence, isolation, commands, fixtures, measurements, outputs, cleanup, and stop conditions that a later exact authorization must adopt.

## 2. Confirmed preflight and frozen authority

The proposal-only preflight confirmed:

| Check | Result |
|---|---|
| Branch | exact expected controlled-research proposal branch |
| HEAD | `b86fbe56a631bfa8fbd83b4d15c5f197c3fecb49` |
| Approved RF record | present and formally approved at HEAD |
| Staged files | zero |
| Tracked modifications | zero |
| Pre-existing untracked path | `.claude/settings.local.json` only; not opened or accessed |
| Entrypoint | one accepted constructed-dark `require_once __DIR__ . '/includes/archive/bootstrap.php';` reference and no other archive reference |

The authority order is:

1. accepted event-sourcing technical design and development handoff;
2. approved PM01-PM20 certificate-free packet architecture;
3. approved CA01-CA20 certificate-acquisition architecture and CS01-CS20 strategy deferral;
4. formally approved RF01-RF20 feasibility decisions;
5. accepted P3B1, P3B2a, P3B2b, P3B3, and activation contracts and traceability;
6. accepted production task, fence, snapshot, artifact, private-storage, event, aggregate, and canonical/digest contracts; and
7. installed package source, metadata, and license files as discovery evidence only.

RF01-RF20, PM01-PM20, CA01-CA20, and CS01-CS20 are frozen. This proposal cannot revise their retained-data meanings or deferrals.

## 3. Separate checkpoint state machine

Approval is intentionally split into six gates. No gate implies the next:

```text
CR_DESIGN_APPROVED
    -> CANDIDATE_ACQUISITION_APPROVED
    -> RESEARCH_ASSETS_APPROVED
    -> CONTROLLED_EXECUTION_APPROVED
    -> COMPONENT_SELECTION_APPROVED
    -> PACKET_IMPLEMENTATION_AUTHORIZED
```

| Gate | Exact authority | Current result |
|---|---|---|
| 1. Research design | record CR01-CR20, paths, evidence requirements, and stop rules | requested by this proposal |
| 2. Candidate acquisition | name every exact archive, revision, URL/authority, expected archive SHA-256, license, and dependency before network or copy/extraction | not requested; blocked |
| 3. Research assets | create only the approved research harness, synthetic fixtures, adversarial corpus, candidate copies/patches, and manifests | not requested; blocked |
| 4. Controlled execution | run exact commands in exact runtime/OS environments with network denied | not requested; blocked |
| 5. Component selection | review a complete evidence package and select exact renderer/parser authorities | not requested; blocked |
| 6. Packet implementation | approve an exact production/test allowlist and retained integration | not requested; blocked |

Approval of CR01-CR20 moves only to `CR_DESIGN_APPROVED`.

At every gate, research remains outside the archive worker and lifecycle. A research failure creates no archive event. Research writes no task, receipt, snapshot, artifact descriptor, lifecycle row, private-store object, or other authoritative record. Research defines no new archive failure category, reason code, or context. PM17-PM19 remain the only future packet failure/fencing/recovery authority; D16 and every downstream packet surface remain deferred.

## 4. Local candidate evidence and dispositions

### 4.1 Renderer directions

| Research ID | Local identity evidence | Missing admission evidence | Disposition |
|---|---|---|---|
| `renderer_tcpdf_6_11_2_fork_research` | `../sfwd-lms/includes/lib/tcpdf/tcpdf.php` declares `6.11.2`; `LICENSE.TXT` states LGPL version 3 or later | authoritative source archive/revision, expected archive SHA-256, acquisition authority, complete dependency/font/license inventory, patch manifest, deterministic controls | `IDENTIFIED_NOT_ADMITTED` |
| `renderer_mpdf_8_2_7_fork_research` | both `vendor/` and `vendor-prefixed/` metadata identify mPDF `v8.2.7`, source revision `b59670a09498689c33ce639bac8f5ba26721dab3`, and GPL-2.0-only; local component license text exists | locally retained authoritative source archive, expected archive SHA-256, owner-approved acquisition URL/authority, exact one-tree source choice, transitive/font/notice closure, patch manifest, deterministic controls | `IDENTIFIED_NOT_ADMITTED` |
| `renderer_minimal_packet_writer_v1_research` | direction approved by RF05; no implementation exists | every source, revision, license, font/layout rule, manifest, parser-independent conformance proof, maintenance plan, and vector | `DIRECTION_ONLY_NOT_ADMITTED` |
| installed LearnDash TCPDF integration | live installed source only | violates RF04 as installed | `REJECTED_AS_INSTALLED` |
| installed Certificate Builder/mPDF integration | live installed source only | violates RF04 as installed | `REJECTED_AS_INSTALLED` |

The local mPDF Composer metadata records source URL `https://github.com/mpdf/mpdf.git`, dist URL `https://api.github.com/repos/mpdf/mpdf/zipball/b59670a09498689c33ce639bac8f5ba26721dab3`, and source/dist revision `b59670a09498689c33ce639bac8f5ba26721dab3`. Those strings are discovery evidence, not owner-approved acquisition authority, and do not provide an expected archive SHA-256. They may be cited in a later acquisition proposal, never contacted or trusted by this record.

### 4.2 Parser directions

| Research ID | Local identity evidence | Disposition |
|---|---|---|
| TCPDF parser with TCPDF `6.11.2` | local structural parser source | `REJECTED_AS_SEMANTIC_VALIDATOR` |
| FPDI `2.6.4` | local prefixed and unprefixed Certificate Builder copies; MIT metadata/license evidence | `REJECTED_AS_SEMANTIC_VALIDATOR` |
| plugin FPDI `2.6.0` | local source version; no colocated license file | `REJECTED_AS_SEMANTIC_VALIDATOR` |
| mPDF `v8.2.7` internal import/parser paths | local renderer-internal source | `REJECTED_AS_INDEPENDENT_VALIDATOR` |
| exact external or archive-owned semantic parser | none can be named from local authoritative evidence | `CANDIDATE_DISCOVERY_GATE_REQUIRED` |

No parser ID, version, source archive, URL, hash, license, dependency, or runtime may be filled with `latest`, a wildcard, a reputation-based guess, or a placeholder that is executable.

## 5. Proposed future research-only file boundary

The following is a research boundary, not a packet implementation allowlist and not authority to create any path now:

```text
tests/archive/feasibility/packet-renderer-parser/
    run-controlled-research.php
    candidates/<closed_candidate_id>/
        candidate.json
        source-archive/
        source/
        patches/
        files.sha256
        licenses/
        sbom.spdx.json
tests/archive/fixtures/packet-renderer-parser/
    manifest.json
    canonical/
    adversarial/
docs/superpowers/evidence/packet-renderer-parser/<approved_run_id>/
    run-manifest.json
    candidate-manifest.json
    files.sha256
    sbom.spdx.json
    license-review.md
    renderer-results.json
    parser-results.json
    reproducibility-results.json
    resource-results.json
    sanitization-results.json
    evidence.sha256
    summary.md
    pdf/
<resolved OS temporary root>/<unique invocation id>/
```

No absolute path is stored in retained research evidence. Evidence uses normalized repository-relative labels. The invocation temporary directory is resolved before use, must be newly created for exactly one run, and is never a caller-selected arbitrary path.

## 6. Proposed exact command shape

The future harness entrypoint accepts only closed IDs present in approved manifests. Its exact argument order is:

```powershell
& '<approved exact PHP executable>' `
  'tests\archive\feasibility\packet-renderer-parser\run-controlled-research.php' `
  '--renderer=<closed_renderer_candidate_id>' `
  '--parser=<closed_parser_candidate_id>' `
  '--fixture-manifest=tests/archive/fixtures/packet-renderer-parser/manifest.json' `
  '--environment=<closed_environment_id>' `
  '--run-id=<approved_run_id>' `
  '--evidence-root=docs/superpowers/evidence/packet-renderer-parser/<approved_run_id>' `
  '--temp-root=<resolved_invocation_owned_temp_root>' `
  '--cold-runs=5' `
  '--warm-runs=5' `
  '--network=deny'
```

`renderer`, `parser`, `environment`, and `run-id` are validated closed manifest keys, not paths. The harness refuses missing, extra, duplicate, malformed, or unknown arguments. It does not discover packages, fonts, executables, fixture files, or output roots dynamically.

The exact Windows PHP executables currently available for later consideration are:

- `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe` for exact PHP `8.3.30`; and
- `C:\laragon\bin\php\php-8.5.7-nts-Win32-vs17-x64\php.exe` for exact PHP `8.5.7`.

Their future environment descriptors must additionally bind executable SHA-256, architecture, thread-safety mode, loaded extensions and versions, `php.ini` digest, zlib version, OS build, locale, timezone, and network-denial control. The exact production OS/image and PHP executable are unknown and block execution authorization. PHP 8.4 remains supplemental only if an actual CLI is later present and attested.

## 7. CR01-CR20 decisions

### CR01 — Scope and non-goals

**Status.** Proposed; design only.

**Exact decision.** CR01-CR20 define a future controlled research phase that may gather renderer/parser feasibility evidence from synthetic inputs. This approval creates no files except this proposal and authorizes no candidate acquisition, copy, extraction, patch, harness, fixture, package execution, PDF generation/parsing, component selection, or packet implementation.

**Evidence and rationale.** RF01 and RF20 require separate research and later selection/implementation checkpoints.

**Security and correctness implications.** Prevents architecture approval from becoming implicit code or supply-chain authority.

**Explicit exclusions.** Production/test code, Composer, schema, runtime wiring, database, Docker, network, current site, staging, commit, push, activation, deployment.

**Unresolved owner/operator evidence.** Gates 2-6 in Section 3.

**Evidence identifiers.** `[RETAINED_EXISTING] CR01-CONSTRUCTED-DARK-BOUNDARY`; `[FUTURE_EXECUTABLE] CR01-RESEARCH-ONLY-PATHS`; `[FUTURE_EXECUTABLE] CR01-NO-ARCHIVE-SIDE-EFFECT`; `[DEFERRED_OPERATOR_EVIDENCE] CR01-SEPARATE-GATE-AUTHORITY`.

**Exact approval clause.** `Approve CR01's design-only scope and authorize none of acquisition, asset creation, execution, selection, or implementation.`

### CR02 — Authority and evidence hierarchy

**Status.** Proposed.

**Exact decision.** Frozen RF/PM/CA/CS and accepted runtime contracts outrank research results. Approved source archives and their expected SHA-256 outrank extracted trees; extracted file manifests outrank package version strings; independently reproduced byte/semantic results outrank package claims; owner/legal evidence controls redistribution. Failed or incomplete evidence cannot be compensated by reputation or weighted scoring.

**Evidence and rationale.** Installed source identifies risks but is not an immutable acquisition authority.

**Security and correctness implications.** Prevents source substitution and authority inversion.

**Explicit exclusions.** Search snippets, mutable upstream pages, `latest`, local installation presence, and generated output as source provenance.

**Unresolved owner/operator evidence.** Exact acquisition records and independent evidence review.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CR02-AUTHORITY-ORDER-CLOSED`; `[FUTURE_EXECUTABLE] CR02-HASH-BEFORE-EXTRACTION`; `[FUTURE_EXECUTABLE] CR02-REPUTATION-NOT-EVIDENCE`; `[DEFERRED_OPERATOR_EVIDENCE] CR02-INDEPENDENT-REVIEW-ACCEPTED`.

**Exact approval clause.** `Approve CR02's evidence hierarchy and prohibit assumption, reputation, or installed presence from satisfying a gate.`

### CR03 — Research phase boundaries

**Status.** Proposed; execution blocked.

**Exact decision.** Future research is one synthetic, isolated, no-network process family operating only in Section 5 paths. It may read approved candidate/fixture bytes, invoke an admitted renderer process, pass the resulting research PDF to a separately admitted parser process, measure both, and write only sanitized evidence. It may not load WordPress/LearnDash, production archive code beyond an explicitly reviewed pure canonical fixture validator, or any live configuration/data.

**Evidence and rationale.** Renderer/parser independence and strict path/network boundaries are necessary for meaningful security evidence.

**Security and correctness implications.** Limits privilege, mutable inputs, and cross-trust contamination.

**Explicit exclusions.** `wp-load.php`, `wp-config.php`, `$wpdb`, options, hooks, URLs, cookies, nonces, current-site paths, production artifact roots, and ambient package discovery.

**Unresolved owner/operator evidence.** OS-specific network-denial and process-sandbox mechanisms.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CR03-NO-WORDPRESS-BOOTSTRAP`; `[FUTURE_EXECUTABLE] CR03-RENDERER-PARSER-PROCESS-SEPARATION`; `[FUTURE_EXECUTABLE] CR03-NETWORK-DENY-ENFORCED`; `[DEFERRED_OPERATOR_EVIDENCE] CR03-HOST-SANDBOX-DESCRIPTOR`.

**Exact approval clause.** `Approve CR03's isolated research boundary while keeping its harness and execution unauthorized.`

### CR04 — Renderer candidate admission

**Status.** Directions identified; no candidate admitted.

**Exact decision.** A renderer candidate becomes admitted only through a later owner record containing its closed research ID, exact package/revision, authoritative acquisition source, exact URL where applicable, expected source-archive SHA-256, exact extraction path, dependency/font/template/asset list, license set, patch manifest, and reason it can satisfy RF03. The three Section 4.1 directions remain `IDENTIFIED_NOT_ADMITTED` or `DIRECTION_ONLY_NOT_ADMITTED`.

**Evidence and rationale.** Local installed trees lack at least authoritative archive/hash and approved patch/provenance evidence.

**Security and correctness implications.** Blocks research against substituted or ill-defined renderer code.

**Explicit exclusions.** Running installed LearnDash/Certificate Builder code, copying an installed tree as authoritative source, or admitting all forks under one ID.

**Unresolved owner/operator evidence.** Candidate-specific acquisition proposals and owner/legal decisions.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CR04-RENDERER-ADMISSION-EXACT`; `[FUTURE_EXECUTABLE] CR04-INSTALLED-INTEGRATIONS-STAY-REJECTED`; `[FUTURE_EXECUTABLE] CR04-FORKS-SEPARATE-CANDIDATES`; `[DEFERRED_OPERATOR_EVIDENCE] CR04-RENDERER-ACQUISITION-APPROVED`.

**Exact approval clause.** `Approve CR04's renderer admission grammar while admitting no renderer candidate.`

### CR05 — Parser candidate admission

**Status.** No acceptable exact candidate; acquisition checkpoint required.

**Exact decision.** No semantic parser is admitted. A later candidate-discovery proposal must name each exact package/version/revision, authoritative archive, expected SHA-256, URL/authority, license, dependency/runtime model, independent trust-root analysis, supported PDF features, and preliminary RF06 bounding mechanism. It must explicitly retain the four Section 4.2 rejections unless materially new, locally reviewable evidence is supplied.

**Evidence and rationale.** Local parsers lack required semantic coverage and resource bounds.

**Security and correctness implications.** Prevents an importer or renderer-internal parser from becoming a security oracle.

**Explicit exclusions.** Unnamed package, wildcard/latest version, search-driven automatic installation, and a parser selected only because it accepts renderer output.

**Unresolved owner/operator evidence.** Separate network-research permission may be needed to identify a candidate; it is not granted here.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CR05-PARSER-ADMISSION-EXACT`; `[FUTURE_EXECUTABLE] CR05-RF07-REJECTIONS-PRESERVED`; `[FUTURE_EXECUTABLE] CR05-UNNAMED-PACKAGE-REJECTED`; `[DEFERRED_OPERATOR_EVIDENCE] CR05-PARSER-DISCOVERY-AUTHORIZED-SEPARATELY`.

**Exact approval clause.** `Approve CR05's parser acquisition gate while admitting and selecting no parser.`

### CR06 — Exact package acquisition and SHA-256 verification

**Status.** Contract proposed; acquisition unauthorized.

**Exact decision.** Every future acquisition record must freeze one literal HTTPS URL or owner-provided offline path, authority, exact expected archive byte count and lowercase SHA-256, exact archive filename, package revision/version, signature/checksum evidence where available, and research extraction destination. The acquisition process writes to a new quarantine file, streams SHA-256, compares with `hash_equals`, verifies file type without execution, rejects links/path traversal on extraction, then produces a closed extracted-file manifest. No extraction or source read occurs before the archive hash passes.

**Evidence and rationale.** Current local metadata does not supply an owner-approved archive hash; therefore no executable acquisition command can be written honestly.

**Security and correctness implications.** Defends against registry, transport, cache, and archive-substitution attacks.

**Explicit exclusions.** Composer resolution, redirects not frozen in the later record, branch/tag downloads, mutable URLs, TOFU hashes, and learning expected hashes from downloaded bytes.

**Unresolved owner/operator evidence.** Exact archive records, network/offline authority, redirect policy, and acquisition command for each candidate.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CR06-ARCHIVE-HASH-BEFORE-USE`; `[FUTURE_EXECUTABLE] CR06-ARCHIVE-TRAVERSAL-REJECTED`; `[FUTURE_EXECUTABLE] CR06-TOFU-HASH-REJECTED`; `[DEFERRED_OPERATOR_EVIDENCE] CR06-CANDIDATE-ARCHIVE-LITERALS-APPROVED`.

**Exact approval clause.** `Approve CR06's future acquisition verification contract and authorize no download, copy, extraction, or package installation.`

### CR07 — Immutable source manifest and SBOM

**Status.** Evidence format proposed; bytes blocked.

**Exact decision.** Each candidate requires a lexicographically ordered relative-path manifest containing regular-file path, byte count, and lowercase SHA-256; symlinks, devices, absolute paths, control bytes, duplicate/case-colliding paths, and unmanifested files are rejected. It also requires an SPDX 2.3 JSON SBOM naming every component, version/revision, source, license assertion, dependency edge, patch, font, template, and asset. Separate source-archive, pristine-extracted, and patched-candidate manifests must make every change explicit.

**Evidence and rationale.** Composer metadata is inventory evidence, not a byte-complete deployed manifest.

**Security and correctness implications.** Exposes dependency/patch/font substitution and undeclared code.

**Explicit exclusions.** Runtime dependency resolution, absolute paths, timestamps as identity, version-header-only attestation, and silently generated files.

**Unresolved owner/operator evidence.** Exact manifest/SBOM bytes and independent review.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CR07-FILE-MANIFEST-CLOSED`; `[FUTURE_EXECUTABLE] CR07-SBOM-DEPENDENCIES-COMPLETE`; `[FUTURE_EXECUTABLE] CR07-PRISTINE-PATCHED-DIFF-EXACT`; `[DEFERRED_OPERATOR_EVIDENCE] CR07-SBOM-REVIEW-ACCEPTED`.

**Exact approval clause.** `Approve CR07's manifest and SBOM formats while approving no manifest, digest, or candidate bytes.`

### CR08 — License, notices, fonts, and redistribution review

**Status.** Gate proposed; no legal conclusion.

**Exact decision.** Before execution, owner-controlled review must inventory and disposition every package, patch, transitive dependency, font, template, asset, license text, notice, source/modification obligation, redistribution right, and customer-deployment implication. TCPDF's local LGPL-3.0-or-later text, mPDF's GPL-2.0-only metadata/GPL text, FPDI's MIT evidence, heterogeneous mPDF font notices, absent TCPDF font-directory notices, and missing plugin-FPDI colocated license are facts, not legal approval.

**Evidence and rationale.** Installed component texts do not settle a future archive-owned combined distribution.

**Security and correctness implications.** Prevents unreviewed code/assets and incomplete distribution obligations.

**Explicit exclusions.** Legal guarantees, license inference from installation, and reuse of customer/client assets.

**Unresolved owner/operator evidence.** Written legal/owner disposition, exact notice bundle, font subset rights, source-offer/patch policy, and vulnerability review.

**Evidence identifiers.** `[DEFERRED_OPERATOR_EVIDENCE] CR08-COMPONENT-LICENSE-DISPOSITION`; `[DEFERRED_OPERATOR_EVIDENCE] CR08-FONT-ASSET-RIGHTS-DISPOSITION`; `[DEFERRED_OPERATOR_EVIDENCE] CR08-NOTICE-SOURCE-OBLIGATIONS`; `[FUTURE_EXECUTABLE] CR08-LICENSE-FILE-SUBSTITUTION-REJECTED`.

**Exact approval clause.** `Approve CR08's licensing evidence gate while making no redistribution or legal determination.`

### CR09 — Isolated harness architecture

**Status.** Design proposed; harness creation unauthorized.

**Exact decision.** The future harness is one CLI orchestrator at the exact Section 5 path. It validates the closed command grammar, manifests, environment, roots, and network-denial attestation before spawning separate renderer and parser child processes. Candidate processes receive only approved relative inputs and inherited bounded configuration, never arbitrary paths or environment secrets. The orchestrator streams bytes in chunks no larger than `1,048,576`, captures measurements, validates one-line stdout envelopes, and owns all handles/temp cleanup in `finally`.

**Evidence and rationale.** Process separation limits shared trust and makes failure/resource attribution mechanical.

**Security and correctness implications.** Reduces ambient state, path injection, parser/render coupling, and sensitive-output risk.

**Explicit exclusions.** Web endpoints, WordPress bootstrap, service containers, arbitrary class names/callables, shell-built commands, dynamic autoloading outside manifests, and production artifact stores.

**Unresolved owner/operator evidence.** Exact candidate adapters, OS sandbox, process limit mechanism, and harness code review.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CR09-CLOSED-CLI-GRAMMAR`; `[FUTURE_EXECUTABLE] CR09-CHILD-PROCESS-ISOLATION`; `[FUTURE_EXECUTABLE] CR09-ARBITRARY-PATH-REJECTED`; `[DEFERRED_OPERATOR_EVIDENCE] CR09-HARNESS-CODE-APPROVED`.

**Exact approval clause.** `Approve CR09's research-harness architecture while authorizing no harness or adapter creation.`

### CR10 — Synthetic canonical packet fixtures

**Status.** Fixture contract proposed; fixture bytes blocked.

**Exact decision.** The future canonical corpus has exactly these fixture IDs: `packet_smallest_v1`, `packet_typical_v1`, `packet_largest_valid_v1`, `packet_longest_strings_v1`, `packet_long_program_course_names_v1`, `packet_unicode_nfc_fonts_v1`, `packet_null_empty_optional_v1`, `packet_multipage_tables_v1`, `packet_course_order_v1`, and `packet_empty_evidence_assets_v1`. Each is synthetic canonical packet-input-document-v1, within the retained 1 MiB/10,000-value limits, binds `case.program_key`, has exact stable course order, has `source.evidence_assets = []`, and contains no current-site/customer data.

`packet_largest_valid_v1` must be mechanically shown to be the largest supported fixture under the frozen canonical grammar chosen for this corpus; it cannot invent a course-count ceiling. Every fixture receives independently reviewed literal bytes, byte count, SHA-256, normalization notes, expected rendered facts, and redistribution-safe provenance before execution.

**Evidence and rationale.** RF15 defines these categories but no approved bytes exist.

**Security and correctness implications.** Gives deterministic, non-sensitive coverage of layout and normalization boundaries.

**Explicit exclusions.** PII, current-site exports, live titles/logos/certificates, URLs, current clock, mutable options, and copyrighted client assets.

**Unresolved owner/operator evidence.** Fixture bytes, hashes, expected facts, font corpus, and independent review.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CR10-CANONICAL-FIXTURE-MANIFEST`; `[FUTURE_EXECUTABLE] CR10-LARGEST-VALID-PROVED`; `[FUTURE_EXECUTABLE] CR10-NFC-AND-COURSE-ORDER-STABLE`; `[DEFERRED_OPERATOR_EVIDENCE] CR10-SYNTHETIC-FIXTURES-APPROVED`.

**Exact approval clause.** `Approve CR10's synthetic fixture taxonomy while approving no fixture bytes, hashes, or course ceiling.`

### CR11 — Adversarial PDF corpus

**Status.** Corpus contract proposed; files blocked.

**Exact decision.** Independently constructed literal PDFs must cover exact IDs: `pdf_valid_one_page`, `pdf_valid_multipage`, `pdf_encrypted`, `pdf_javascript`, `pdf_action_dictionary`, `pdf_open_action`, `pdf_additional_actions`, `pdf_launch`, `pdf_embedded_file`, `pdf_attachment`, `pdf_filespec`, `pdf_rich_media`, `pdf_xfa`, `pdf_acroform`, `pdf_external_reference`, `pdf_malformed_object`, `pdf_malformed_xref_table`, `pdf_malformed_xref_stream`, `pdf_unresolved_reference`, `pdf_trailing_payload`, `pdf_recursive_object_graph`, `pdf_decompression_bomb`, `pdf_unsupported_construct`, and `pdf_zero_page`. Each file has an independent construction note, byte count, SHA-256, one expected semantic outcome, and no dependency on the renderer/parser under test.

**Evidence and rationale.** RF06/RF13 require fail-closed semantic and abuse coverage unavailable locally.

**Security and correctness implications.** Tests active content, malformed structures, parser differentials, and resource abuse.

**Explicit exclusions.** Malware from customer sites, live PDFs, corpus generation by the parser under test, and warning-only expected outcomes.

**Unresolved owner/operator evidence.** Safe construction, license/provenance review, exact bytes/hashes, and restricted handling rules.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CR11-ADVERSARIAL-MANIFEST-COMPLETE`; `[FUTURE_EXECUTABLE] CR11-ACTIVE-CONTENT-CASES-COMPLETE`; `[FUTURE_EXECUTABLE] CR11-STRUCTURAL-ABUSE-CASES-COMPLETE`; `[DEFERRED_OPERATOR_EVIDENCE] CR11-CORPUS-PROVENANCE-APPROVED`.

**Exact approval clause.** `Approve CR11's adversarial corpus taxonomy while authorizing no PDF fixture creation or parser execution.`

### CR12 — Deterministic renderer controls

**Status.** Controls proposed; candidate configuration blocked.

**Exact decision.** Every admitted renderer must mechanically fix PDF version, page geometry, unit/rounding, text normalization, line breaking, table pagination, font files/subsetting/embedding/glyph order, template/assets, metadata, creation/modification fields, document IDs, object/stream order, compression algorithm/level, locale, timezone, warnings, and error behavior. It must prohibit ambient clock, randomness, system-font discovery, URL/network, hooks, WordPress state, mutable caches, host defaults, and unmanifested temporary inputs.

Five cold and five warm repetitions of each canonical fixture must produce identical bytes, byte count, SHA-256, page count, and parser result within each environment and across the required matrix.

**Evidence and rationale.** RF12 identifies installed time/random/compression/font/temp risks.

**Security and correctness implications.** Supports expected-byte derivation before immutable orphan reuse.

**Explicit exclusions.** Post-render normalization, ignored metadata, per-run IDs, per-platform accepted hashes, and digest learned from first output.

**Unresolved owner/operator evidence.** Candidate-specific closed settings, patches, fonts/assets, and repeated byte results.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CR12-AMBIENT-STATE-ABSENT`; `[FUTURE_EXECUTABLE] CR12-METADATA-OBJECT-COMPRESSION-STABLE`; `[FUTURE_EXECUTABLE] CR12-FONT-LAYOUT-GLYPH-STABLE`; `[FUTURE_EXECUTABLE] CR12-COLD-WARM-BYTES-IDENTICAL`.

**Exact approval clause.** `Approve CR12's renderer-control evidence requirements while approving no renderer configuration or bytes.`

### CR13 — Independent semantic-parser controls

**Status.** Controls proposed; no parser admitted.

**Exact decision.** The parser process must have a separately attested package trust root, accept only one bounded local stream, and return a closed result without renderer callbacks. It must reject every CR11 prohibited construct and unsupported construct, resolve all required indirect references, detect trailing bytes, prove `page_count >= 1`, and later enforce an owner-approved maximum. It must expose measurement counters for object count, cumulative decompressed bytes, and maximum graph/reference depth and obey externally enforced memory/time/temp limits.

**Evidence and rationale.** No local candidate meets RF06/RF13.

**Security and correctness implications.** Prevents renderer success, lexical scanning, or importer behavior from becoming semantic authority.

**Explicit exclusions.** TCPDF/FPDI/mPDF reconsideration without new evidence, complete-file unbounded reads, warning acceptance, external references, and exception-message classification.

**Unresolved owner/operator evidence.** Exact candidate, API, supported constructs, counters, limits, attestation, and corpus results.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CR13-PARSER-TRUST-ROOT-INDEPENDENT`; `[FUTURE_EXECUTABLE] CR13-SEMANTIC-REJECTIONS-CLOSED`; `[FUTURE_EXECUTABLE] CR13-PAGE-COUNT-POSITIVE`; `[DEFERRED_OPERATOR_EVIDENCE] CR13-PARSER-BOUNDS-IMPLEMENTABLE`.

**Exact approval clause.** `Approve CR13's parser-control requirements while admitting and selecting no semantic parser.`

### CR14 — PHP runtime matrix

**Status.** Required matrix proposed; execution blocked.

**Exact decision.** Controlled evidence requires exact PHP `8.3.30` and `8.5.7`; neither substitutes for the other. Each environment descriptor binds executable hash, build/architecture/thread-safety, extensions and versions, ini bytes/digest, zlib, locale, timezone, and OS identity. Every admitted renderer/parser and every canonical/adversarial fixture runs five cold and five warm repetitions. PHP 8.4 is supplemental only when an actual exact CLI is present and attested.

**Evidence and rationale.** Package PHP constraints do not prove execution or byte identity.

**Security and correctness implications.** Detects runtime-sensitive serialization, compression, layout, and parser behavior.

**Explicit exclusions.** `PHP_VERSION_ID` ranges, PHP 8.3 as evidence for 8.5, web SAPIs, and unrecorded extensions/ini.

**Unresolved owner/operator evidence.** Future executable hashes/environment manifests and controlled run authorization.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CR14-PHP-830-EXACT`; `[FUTURE_EXECUTABLE] CR14-PHP-857-EXACT`; `[FUTURE_EXECUTABLE] CR14-RUNTIME-MANIFEST-CLOSED`; `[DEFERRED_OPERATOR_EVIDENCE] CR14-PHP84-SUPPLEMENTAL-ONLY`.

**Exact approval clause.** `Approve CR14's exact required PHP matrix while authorizing no component execution.`

### CR15 — Windows and production-OS reproducibility

**Status.** Windows identity partly known; production OS unknown and blocking.

**Exact decision.** The same approved candidate/fixture/manifests must produce identical PDF bytes, byte count, SHA-256, page count, and parser result on Windows development and the exact production OS/image. The production descriptor must bind OS distribution/version, architecture, kernel, libc/runtime libraries, image or filesystem manifest digest, PHP environment, fonts, locale/timezone, and sandbox/network controls. If any PDF bytes differ across OS targets, component selection stops pending a separate platform-constrained producer/version and retained-compatibility proposal.

**Evidence and rationale.** The future production OS/image is not authoritative in the repository.

**Security and correctness implications.** Prevents host-dependent immutable artifacts and false local reproducibility claims.

**Explicit exclusions.** Generic `Linux`, mutable `latest` images, per-OS accepted hashes, and production-only output without Windows comparison.

**Unresolved owner/operator evidence.** Exact production environment descriptor and owner-controlled runner.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CR15-WINDOWS-PRODUCTION-BYTES-IDENTICAL`; `[FUTURE_EXECUTABLE] CR15-PRODUCTION-IMAGE-ATTESTED`; `[FUTURE_EXECUTABLE] CR15-PER-OS-HASH-WAIVER-REJECTED`; `[DEFERRED_OPERATOR_EVIDENCE] CR15-PRODUCTION-OS-SUPPLIED`.

**Exact approval clause.** `Approve CR15's cross-OS reproducibility gate while approving no production image or platform exception.`

### CR16 — Resource measurement methodology

**Status.** Method proposed; no new ceiling approved.

**Exact decision.** Preserve only PDF bytes `1..67,108,864` and stream chunks at most `1,048,576`. For every canonical and adversarial fixture, record rendered bytes, page count, peak PHP memory above a separately measured empty-process baseline, renderer duration, parser duration, total duration through handle closure and cleanup, temporary-storage peak bytes/files, parsed object count, cumulative decompressed-stream bytes, and maximum graph/reference recursion depth. Record each repetition, minimum, maximum, median, and deterministic equality; never report averages alone.

The harness must impose an owner-approved external emergency containment limit to prevent host harm, but that emergency value is not an archive ceiling and cannot become selection evidence. Exact inclusive page, memory, duration, expansion, recursion, object-count, and temp ceilings require a later owner decision based on complete measurements; only then may equality and one-over regressions be created.

**Evidence and rationale.** Output bytes do not bound layout/parser amplification, and no empirical results exist.

**Security and correctness implications.** Produces evidence for fail-closed denial-of-service limits without fabricating retained policy.

**Explicit exclusions.** Host defaults, `memory_limit` as measurement, unreported killed runs, averages without raw maxima, and ceilings copied from another candidate.

**Unresolved owner/operator evidence.** Emergency containment values, measurement instrumentation validation, results, operational margin, and exact later ceilings.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CR16-ALL-METRICS-PER-RUN`; `[FUTURE_EXECUTABLE] CR16-BASELINE-SUBTRACTED-MEMORY`; `[FUTURE_EXECUTABLE] CR16-CLEANUP-IN-TOTAL-DURATION`; `[DEFERRED_OPERATOR_EVIDENCE] CR16-MEASURED-CEILINGS-APPROVED-LATER`.

**Exact approval clause.** `Approve CR16's measurement method and retained byte/chunk limits; approve no other resource ceiling.`

### CR17 — Sanitized evidence outputs and retention

**Status.** Output contract proposed; outputs not authorized.

**Exact decision.** A run may write only the Section 5 evidence files. JSON uses closed schemas, UTF-8, relative labels, integer measurements, lowercase SHA-256, stable IDs, and no ambient timestamps as identity; Markdown summarizes those JSON records. Synthetic research PDFs may be retained under that run's `pdf/` directory solely as research evidence and are never copied into private archive storage or represented by archive descriptors/events.

Each process stdout contains exactly one validated single-line nine-field research envelope: `candidate_id`, `component`, `environment_id`, `fixture_id`, `result_code`, `run_id`, `schema_version`, `status`, and `summary_digest`. Success requires empty stderr. Raw stdout/stderr is captured only inside the invocation boundary, never copied to ordinary logs/evidence, and removed during cleanup. On malformed/multiple stdout or any stderr, evidence records only the stable blocked code, byte counts, and leakage-scan result, never raw text. Names, emails, PII, paths, URLs, credentials, source excerpts, exception text, and stack traces are prohibited.

**Evidence and rationale.** Research outputs are operational evidence, not retained archive facts.

**Security and correctness implications.** Provides reproducible review evidence without leaking host/package paths or sensitive content.

**Explicit exclusions.** Current-site data, raw stderr retention, absolute paths, network identifiers, archive receipt/task/event rows, and public URLs.

**Unresolved owner/operator evidence.** Exact JSON schemas, retention duration/location, restricted reviewer access, and evidence-package acceptance.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CR17-EVIDENCE-SCHEMAS-CLOSED`; `[FUTURE_EXECUTABLE] CR17-STDOUT-ONE-LINE-ENVELOPE`; `[FUTURE_EXECUTABLE] CR17-STDERR-AND-SENSITIVE-TEXT-BLOCKED`; `[DEFERRED_OPERATOR_EVIDENCE] CR17-EVIDENCE-RETENTION-APPROVED`.

**Exact approval clause.** `Approve CR17's sanitized evidence design while authorizing no output creation or research-PDF retention.`

### CR18 — Cleanup, containment, and stop conditions

**Status.** Proposed; execution blocked.

**Exact decision.** Every process closes streams/child handles in `finally`; the orchestrator then revalidates the resolved invocation root and removes only files it created beneath that root. Candidate source, approved fixtures, and completed evidence outputs are read-only or retained according to their later record; cleanup never follows symlinks or touches repository/current-site/private-artifact paths.

Execution stops immediately on source-archive/file-manifest mismatch; missing/contradictory license; unexpected dependency/file; attempted network; path escape/symlink/case collision; current-site/sensitive-data access; nondeterministic repeated bytes; cross-runtime/cross-OS mismatch; unsupported parser construct; unbounded recursion/expansion/memory/duration/temp/object growth; unexpected stdout/stderr disclosure; cleanup failure; or need for schema, event, digest, task, descriptor, snapshot, state-machine, or retained-data change. A stopped run selects nothing and writes no archive fact.

**Evidence and rationale.** These are the exact RF19 stop classes and the user's controlled-research boundary.

**Security and correctness implications.** Contains filesystem/process failures and prevents partial evidence from becoming approval.

**Explicit exclusions.** Best-effort continuation, partial pass, cleanup outside the invocation root, retries that hide nondeterminism, and deletion of unrelated files.

**Unresolved owner/operator evidence.** OS-specific safe cleanup implementation, failure injection, and operator incident procedure.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CR18-STOP-CONDITIONS-CLOSED`; `[FUTURE_EXECUTABLE] CR18-SYMLINK-PATH-CLEANUP-CONTAINED`; `[FUTURE_EXECUTABLE] CR18-PARTIAL-EVIDENCE-NOT-ACCEPTED`; `[DEFERRED_OPERATOR_EVIDENCE] CR18-INCIDENT-PROCEDURE-APPROVED`.

**Exact approval clause.** `Approve CR18's containment and stop rules while authorizing no cleanup code or experiment.`

### CR19 — Component-selection evidence package

**Status.** Acceptance checklist proposed; selection blocked.

**Exact decision.** A later selection proposal may consider a component only when one immutable evidence package contains: owner-approved acquisition records; source archives and verified hashes; pristine/patched manifests; patch review; SPDX SBOM; license/notices/fonts/assets/legal disposition; environment descriptors; harness/fixture/corpus manifests; all raw sanitized per-run measurements; PDF bytes/hashes; semantic outcomes; stdout/stderr/privacy results; cleanup evidence; cross-runtime and cross-OS equality; vulnerability/maintenance model; and independent reviewer sign-off. It must enumerate every RF03/RF06/RF10-RF18 requirement as pass or fail and may not select on incomplete evidence.

Renderer and parser decisions are independent, but packet implementation remains blocked until both are selected and their composition is proven. Selection must freeze exact producer/parser identities, manifests, digests, configuration, literals, resource ceilings, and compatibility consequences in a new owner decision.

**Evidence and rationale.** Research execution is evidence generation, not component authority.

**Security and correctness implications.** Prevents partial or favorable-only results from authorizing supply-chain components.

**Explicit exclusions.** Automatic selection by harness score, waived failures, hidden raw evidence, and production implementation in the selection proposal.

**Unresolved owner/operator evidence.** Complete future evidence package and independent owner/security/legal review.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CR19-EVIDENCE-PACKAGE-COMPLETE`; `[FUTURE_EXECUTABLE] CR19-ALL-RF-GATES-MAPPED`; `[FUTURE_EXECUTABLE] CR19-NO-AUTOMATIC-SELECTION`; `[DEFERRED_OPERATOR_EVIDENCE] CR19-INDEPENDENT-SELECTION-REVIEW`.

**Exact approval clause.** `Approve CR19's future evidence-package checklist while selecting no renderer or parser.`

### CR20 — Remaining gates and exact owner authorization

**Status.** Design-only approval recommended.

**Exact decision.** Approve documentation of CR01-CR20 only. A new owner record must separately approve exact candidate discovery/acquisition; another must approve research asset creation; another must approve a bounded execution window; a later evidence-complete record may select components; and packet implementation remains at a still-later exact-allowlist gate. RF/PM/CA/CS deferrals, PM17-PM19 event-free/fencing/recovery rules, D16, certificate-bearing packets, verification/finalization, publication, scheduling, current-site testing, activation, and deployment remain unchanged.

**Evidence and rationale.** No candidate archive/hash set, acceptable parser, harness, fixture bytes, production OS, measurements, or legal approval exists.

**Security and correctness implications.** Makes the smallest safe authorization explicit and reversible.

**Explicit exclusions.** Steps 2-6 of Section 3, any production/test implementation allowlist, and any new archive failure tuple or retained contract.

**Unresolved owner/operator evidence.** Every future gate and blocker in Sections 8-9.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CR20-NO-COMPONENT-SELECTED`; `[FUTURE_EXECUTABLE] CR20-NO-IMPLEMENTATION-ALLOWLIST`; `[FUTURE_EXECUTABLE] CR20-RF-PM-CA-CS-UNCHANGED`; `[DEFERRED_OPERATOR_EVIDENCE] CR20-FUTURE-GATES-SEPARATE`.

**Exact approval clause.** `Approve CR20's design-only result and preserve all later acquisition, asset, execution, selection, and implementation gates.`

## 8. Evidence identifier inventory

CR01-CR20 define exactly four unique evidence identifiers per decision. Deferred operator evidence is never reported as an executed pass.

| Classification | Count | Meaning |
|---|---:|---|
| `RETAINED_EXISTING` | 1 | Existing constructed-dark boundary retained as evidence |
| `FUTURE_EXECUTABLE` | 58 | Later acquisition/harness/fixture/execution/selection checks |
| `DEFERRED_OPERATOR_EVIDENCE` | 21 | Owner, legal, environment, acquisition, measurement, retention, or review evidence |
| **Total** | **80** | Unique identifiers |

## 9. Exact unresolved blockers

Candidate acquisition is blocked by:

1. authoritative TCPDF source revision/archive and expected SHA-256;
2. authoritative mPDF archive and expected SHA-256 despite the known source revision;
3. exact archive-owned fork choice and patch scope;
4. any source/revision for the minimal writer direction;
5. an exact semantic parser candidate, version/revision, archive, URL/authority, hash, license, and dependency model;
6. network/offline acquisition authority and redirect rules; and
7. candidate-specific legal/owner approval.

Research asset creation is blocked by:

1. admitted exact candidates;
2. reviewed harness architecture/code allowlist;
3. synthetic canonical fixture bytes/hashes;
4. adversarial PDF bytes/hashes/provenance;
5. exact environment manifests and process/network sandbox mechanisms;
6. source/file manifests, SPDX SBOMs, license/notice/font/asset dispositions; and
7. owner authorization to create the research-only paths.

Execution is blocked by:

1. exact production OS/image and PHP descriptor;
2. owner-approved emergency containment limits;
3. exact command literals/run IDs and network denial;
4. sanitized evidence schemas and retention/access rules; and
5. a bounded execution-window approval.

Selection and packet implementation remain blocked by complete results, independent review, exact measured ceilings, producer/parser literals and attestation, independent golden vectors, PM implementation gates, D16, and separate explicit authorizations.

## 10. Proposal-only verification contract

This checkpoint must run only:

- PHP 8.3.30 boundaries and digests;
- PHP 8.5.7 boundaries and digests;
- RF/PM/CA/CS contradiction and stale-authorization searches;
- CR heading, approval-clause, evidence-ID count/uniqueness, candidate-disposition, unnamed/latest-package, ceiling, and implementation-allowlist checks;
- UTF-8 without BOM and zero trailing whitespace;
- `git diff --check` plus an explicit untracked-proposal whitespace check;
- entrypoint hash/reference verification; and
- final branch/HEAD/staged/tracked/untracked containment.

PHP 8.4 is reported only if an actual CLI exists and runs. No database, Docker, network, package, harness, fixture, PDF, parser, current-site, staging, commit, push, activation, or deployment action belongs to this proposal.

## 11. Exact owner decision request

> **Approve Controlled Research Decisions CR01-CR20 as written for documentation-only design: approve the six-gate research architecture, exact candidate admission grammar, future research-only path and command boundaries, synthetic and adversarial corpus requirements, deterministic renderer and independent semantic-parser controls, exact PHP and cross-OS evidence matrix, resource-measurement method, sanitized evidence package, cleanup and stop conditions, and later evidence-complete selection checkpoint; identify TCPDF 6.11.2 and mPDF 8.2.7 archive-owned forks and a minimal writer only as not-admitted renderer research directions; admit and select no renderer or parser; preserve all RF01-RF20, PM01-PM20, CA01-CA20, CS01-CS20, PM17-PM19, D16, constructed-dark, retained-data, verification/finalization, scheduling, publication, activation, and deployment boundaries; require a separate exact candidate-discovery/acquisition approval before any package copy, download, extraction, or installation; require still-separate approvals for harness/fixture creation, controlled execution, component selection, and packet implementation; and authorize no network research, package acquisition, file creation beyond this proposal, harness, fixture, PDF generation/parsing, component execution, implementation allowlist, current-site access, runtime wiring, activation, or deployment.**

Approval of this sentence records only `CR_DESIGN_APPROVED`. It does not authorize any transition to the other five gates.

**Approval record:** On 2026-08-15, the owner formally approved CR01-CR20 exactly as quoted above for documentation-only publication. No renderer or parser was admitted or selected. Candidate discovery/acquisition, network research, package copy/download/extraction/installation, research path or asset creation, harness/fixture creation, controlled execution, PDF generation/parsing, component selection, packet implementation, current-site access, runtime wiring, activation, and deployment remain separately blocked.
