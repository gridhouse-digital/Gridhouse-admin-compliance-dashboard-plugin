# Dual-Layer Archive Packet Renderer and Parser Candidate Discovery Authorization Proposal

**Status:** formally approved for documentation-only discovery design on 2026-08-25; discovery-run execution and all later gates remain unauthorized
**Date:** 2026-08-15
**Branch:** `feature/dual-layer-archive-slice-1b-packet-renderer-parser-candidate-discovery-proposal`
**Parent and current HEAD:** `0941d67e048c1d5d179a98a80b2d520ea35e46dd`
**Scope:** proposal-only design for a future read-only primary-source metadata discovery phase; no network execution, acquisition, research assets, component execution, selection, or packet implementation authority

## 1. Recommended outcome

Approve documentation of CD01-CD20 only. Do not conduct candidate discovery under this checkpoint.

A later, separately authorized discovery run may make bounded unauthenticated HTTPS `GET` requests only when one owner-approved immutable request manifest names every literal URL and authority. That run may record candidate metadata and blockers. It may not download package/archive bytes, clone repositories, resolve/install dependencies, execute code, create research assets, or select a renderer/parser.

Current local evidence remains unchanged:

- TCPDF `6.11.2` and mPDF `v8.2.7` archive-owned forks are unadmitted renderer research directions;
- the minimal writer is a source-owned direction without a package or implementation;
- installed LearnDash TCPDF and Certificate Builder/mPDF remain rejected as archive renderers;
- the TCPDF parser, FPDI `2.6.4`, plugin-bundled FPDI `2.6.0`, and mPDF internal parser remain rejected as semantic validators in their present forms; and
- no independent semantic-parser candidate can presently be named from local authoritative evidence.

Discovery claims are metadata evidence only. They do not prove runtime compatibility, determinism, semantic rejection, resource bounds, licensing acceptability, immutable package identity, or selection fitness.

## 2. Confirmed preflight and authority

The proposal-only preflight confirmed:

| Check | Result |
|---|---|
| Branch | `feature/dual-layer-archive-slice-1b-packet-renderer-parser-candidate-discovery-proposal` |
| HEAD | `0941d67e048c1d5d179a98a80b2d520ea35e46dd` |
| Approved CR record | committed at HEAD with formal owner approval |
| Staged files | zero |
| Tracked modifications | zero |
| Pre-existing untracked path | `.claude/settings.local.json` only; not opened or accessed |
| Entrypoint | exactly one accepted constructed-dark `require_once __DIR__ . '/includes/archive/bootstrap.php';` reference and no other archive reference |

The authority order is:

1. accepted event-sourcing technical design and development handoff;
2. approved PM01-PM20 certificate-free packet architecture;
3. approved CA01-CA20 certificate architecture and CS01-CS20 strategy deferral;
4. approved RF01-RF20 renderer/parser feasibility decisions;
5. approved CR01-CR20 controlled-research design;
6. accepted P3B1, P3B2a, P3B2b, P3B3, activation, task, fence, storage, event, snapshot, and digest contracts;
7. locally installed package source, metadata, and license files as read-only discovery seeds only; and
8. later owner-approved primary-source pages as discovery evidence only.

Search results, snippets, package reputation, AI-generated lists, mutable aliases, installed presence, and documentation claims without a primary source have no authority. Network discovery cannot revise RF, CR, PM, CA, CS, schema, event, task, digest, descriptor, snapshot, state-machine, or retained-data contracts.

## 3. Preserved six-gate state machine

CR01-CR20 froze six top-level gates. This proposal subdivides Gate 2 without merging discovery and acquisition:

```text
1. CR_DESIGN_APPROVED
    -> 2a. CANDIDATE_DISCOVERY_DESIGN_APPROVED
    -> 2b. CANDIDATE_DISCOVERY_RUN_APPROVED
    -> 2c. CANDIDATE_ACQUISITION_APPROVED
    -> 3. RESEARCH_ASSETS_APPROVED
    -> 4. CONTROLLED_EXECUTION_APPROVED
    -> 5. COMPONENT_SELECTION_APPROVED
    -> 6. PACKET_IMPLEMENTATION_AUTHORIZED
```

| Gate | Exact authority | Result requested here |
|---|---|---|
| 1. Research design | CR01-CR20 | already approved |
| 2a. Discovery design | CD01-CD20 rules, schemas, and stop conditions | approved on 2026-08-25 |
| 2b. Discovery run | literal request manifest, exact authorities, run ID, and network window | not requested; blocked |
| 2c. Acquisition | exact archive bytes, expected byte count/SHA-256, acquisition command, and quarantine/extraction boundary | not requested; blocked |
| 3. Research assets | candidate copies, manifests, SBOMs, harness, and fixtures | not requested; blocked |
| 4. Controlled execution | admitted candidates, exact environments, and bounded experiment | not requested; blocked |
| 5. Component selection | complete independently reviewed evidence package | not requested; blocked |
| 6. Packet implementation | separately approved exact production/test allowlist | not requested; blocked |

Approval of CD01-CD20 records only `CANDIDATE_DISCOVERY_DESIGN_APPROVED`. It does not permit a network request. A discovery run requires an additional exact owner record under CD04 and CD17. Acquisition remains a different authority even after discovery completes.

## 4. Future read-only network boundary

### 4.1 Required immutable request manifest

A future discovery run must begin from an owner-approved `candidate-discovery-request-manifest-v1` containing exactly:

- `schema_version`: strict integer `1`;
- `discovery_run_id`: `^[a-z][a-z0-9_]{0,63}$`;
- `approved_at_gmt`: exact RFC 3339 UTC string, report metadata only and never identity;
- `authorities`: one to 16 closed authority records;
- `requests`: one to 64 closed request records in execution order;
- `http_version`: exact string `1.1`;
- `accept_encoding`: exact string `identity`;
- `maximum_header_bytes`: strict integer `65536` per response;
- `maximum_header_fields`: strict integer `128` per response;
- `maximum_header_line_bytes`: strict integer `8192` per response;
- `maximum_response_bytes`: strict integer `2097152` per response;
- `maximum_total_response_bytes`: strict integer `33554432` for the run;
- `connect_timeout_ms`: strict integer `5000`;
- `request_timeout_ms`: strict integer `20000`;
- `run_timeout_ms`: strict integer `1200000`;
- `redirect_limit`: strict integer `0`;
- `network_mode`: exact string `read_only_https_get`.

The later owner approval binds the complete immutable UTF-8 manifest file by exact byte count and lowercase SHA-256 outside the manifest. No self-referential or field-omission hash rule is permitted.

An authority record contains only `authority_id`, ASCII DNS `host`, exact port `443`, one to 16 exact allowed path prefixes, zero to 16 exact allowed query strings or `null`, expected project/registry identity, allowed response media types, and the local or owner evidence that approved the authority. A host-wide wildcard is prohibited. A request record contains only `request_id`, `authority_id`, one literal normalized HTTPS URL, expected media type set, purpose enum, and the candidate IDs it may inform.

The request manifest is operational research evidence, not archive canonical JSON and not a new archive digest domain. No request may be constructed dynamically from a response, hyperlink, search snippet, package name, or user input. Newly discovered URLs are recorded as blocked follow-up evidence and require another owner-approved manifest revision before contact.

### 4.2 Transport rules

The later network client must:

- issue sequential unauthenticated HTTPS `GET` requests only;
- resolve the exact authority hostname once immediately before connection through the later owner-approved resolver, normalize and sort the complete A/AAAA answer set, require at least one address, and require every answer to be public unicast;
- reject the whole request on any loopback, private, link-local, multicast, reserved, unspecified, documentation-only, mapped-private, or otherwise non-public answer, including a mixed public/non-public set;
- choose one address deterministically from that validated set, open the socket directly to that address without a second resolver lookup, preserve the original authority hostname in both TLS SNI and the HTTP `Host` header, and prohibit hostname substitution;
- obtain the connected socket's peer address before sending request bytes and again after TLS establishment, normalize it to binary address form, and require exact equality with the pinned address on both checks;
- reject resolver re-entry, address fallback that was not selected from the original validated set, proxy or tunnel substitution, SNI/Host change, and any connected-peer mismatch;
- validate the peer certificate and original hostname against the owner-approved trust configuration, require TLS 1.2 or newer, and never disable verification;
- send no request body, credentials, tokens, cookies, nonce, referrer, repository data, current-site data, filesystem path, machine name, or user identity;
- use only a fixed sanitized user-agent, fixed `Accept` values for `text/html`, `application/json`, `application/ld+json`, or `text/plain`, and exact `Accept-Encoding: identity`;
- prohibit every proxy, tunnel, ambient proxy environment variable, proxy auto-configuration source, and alternate transport endpoint;
- follow zero redirects and reject any `3xx` response;
- parse HTTP/1.1 headers without content sniffing, enforce at most 65,536 header bytes, 128 fields, and 8,192 bytes per header line, and reject obsolete folding, invalid names, controls, malformed delimiters, or duplicate `Content-Length`, `Transfer-Encoding`, `Content-Encoding`, `Content-Type`, `Content-Disposition`, or `Location` fields;
- require `Content-Encoding` to be absent or one exact case-insensitive `identity` token and reject multiple values, compression, or any other transfer content coding;
- accept exactly one unambiguous body framing: one `Content-Length` matching `^(0|[1-9][0-9]*)$` with no `Transfer-Encoding`, or one exact case-insensitive `Transfer-Encoding: chunked` with no `Content-Length`; reject signed, padded, overflowing, duplicate, or conflicting lengths, combined length/transfer encoding, unsupported transfer coding, trailers, close-delimited bodies, and every framing ambiguity;
- stop after headers on `Content-Disposition`, archive/package media types, `application/octet-stream`, executable content, or an unapproved content type;
- validate the declared media type without sniffing; require `application/json` and `application/ld+json` to be strict UTF-8 with an absent or exact UTF-8 charset, require `text/html` and `text/plain` to declare exact UTF-8 or US-ASCII, and reject invalid byte sequences, contradictory BOMs, unknown parameters, or every other charset;
- stream and hash both received entity bytes and strictly decoded UTF-8 bytes, stop before retaining byte `2097153`, apply the same bound after dechunking and charset validation, and count only successfully framed identity bytes toward the 33,554,432-byte run ceiling;
- enforce the exact per-response, cumulative, connect, request, and run bounds in Section 4.1; and
- close all handles and discard raw response bodies only after the complete field-level provenance set in Section 8.1 has been built and validated.

The sanitized evidence record may retain hashes and booleans proving the normalized DNS answer set, chosen-address membership, and peer equality, but never raw DNS answers or peer addresses. A connection failure does not permit re-resolution, another address, a proxy, or a second request under the same request ID.

No release archive, source archive, package file, Composer metadata endpoint that triggers resolution, Git clone/fetch endpoint, raw executable, PDF, font, fixture, or binary asset may be dereferenced. A page may disclose an archive URL, checksum, or signature URL; discovery records that literal without contacting it.

## 5. Primary-source authority policy

Permitted authority classes are closed:

| Authority class | Permitted discovery claim | Claims it cannot establish alone |
|---|---|---|
| Official project documentation | declared API, configuration, compatibility, feature, and resource-control behavior | installed bytes, runtime proof, deterministic output, semantic corpus result |
| Official source repository pages | exact tag/revision/release metadata, source tree identity, maintenance activity | archive byte hash unless officially published, legal approval, execution fitness |
| Official signed/checksummed release page | published archive URL, byte hash/signature, release identity | acquired-byte equality or extraction safety |
| Official package registry page | exact package/version metadata and publisher-declared links | semantic/security truth, immutable archive authority, selection |
| Official license/notices page | declared license/notices for the exact release | combined-distribution legal approval, font/asset rights closure |
| Official project or recognized vendor security-advisory page | published advisory/maintenance status | absence of vulnerabilities or code safety |
| Official PHP compatibility page | declared supported runtime ranges | actual PHP 8.3.30/8.5.7 behavior or byte identity |

Every contacted host and path must be literal in the later owner-approved manifest. Generic web search, forums, mirrors, paste sites, social media, issue comments from unauthenticated users, AI-generated package lists, and search-engine snippets are prohibited. An official registry may nominate a package name; every substantive claim must still be traced to an owner-approved project/repository/release/license/security authority.

Local metadata supplies discovery seeds, not network authority:

| Seed | Local evidence | Current discovery disposition |
|---|---|---|
| TCPDF `6.11.2` fork direction | `../sfwd-lms/includes/lib/tcpdf/tcpdf.php` and `LICENSE.TXT`; a source comment references `github.com/tecnickcom/TCPDF` | `INSUFFICIENT_PRIMARY_EVIDENCE`; exact official repository/revision/release/checksum authority still unapproved |
| mPDF `v8.2.7` fork direction | both installed Composer manifests name source revision `b59670a09498689c33ce639bac8f5ba26721dab3`, `https://github.com/mpdf/mpdf.git`, and the corresponding GitHub API dist URL; GPL-2.0-only metadata/license is local | `ACQUISITION_EVIDENCE_REQUIRED`; pages may later be discovered, but the dist URL must not be fetched and no official archive SHA-256 is known |
| Minimal packet writer direction | RF05/CR04 direction only; no source/package exists | `INSUFFICIENT_PRIMARY_EVIDENCE`; network discovery cannot create it |
| TCPDF parser | local TCPDF trust root and semantic gaps | `REJECTED_SEMANTIC_COVERAGE`; its shared TCPDF trust root remains an additional blocker if TCPDF is the renderer |
| FPDI `2.6.4` | local Composer source revision `4b53852fde2734ec6a07e458a085db627c60eada`, MIT metadata/license, and rejected semantic coverage | `REJECTED_SEMANTIC_COVERAGE` |
| Plugin FPDI `2.6.0` | local source headers; colocated license gap and rejected semantic coverage | `REJECTED_SEMANTIC_COVERAGE` |
| mPDF internal parser | renderer-internal trust root and no closed semantic policy | `REJECTED_NOT_INDEPENDENT` |
| Independent semantic parser | no exact local candidate | `INSUFFICIENT_PRIMARY_EVIDENCE`; future official-registry nomination and candidate-specific primary-source approval required |

No row admits or selects a component. Materially new primary evidence may justify a new discovery disposition, but it cannot reverse RF rejection or satisfy CR admission without later acquisition and execution evidence.

## 6. Closed candidate record grammar

Each future candidate is one `candidate-record-v1` JSON object with exactly these lexicographically ordered fields:

| Field | Exact grammar |
|---|---|
| `candidate_id` | string matching `^[a-z][a-z0-9_]{0,63}$` |
| `component` | exact `renderer` or `parser` |
| `dependency_manifest_url` | normalized HTTPS URL or `null` |
| `discovery_status` | one Section 7 disposition |
| `license_identifier` | one to 128 ASCII SPDX-expression characters or `null`; a claim, not legal approval |
| `license_url` | normalized HTTPS URL or `null` |
| `official_project_url` | normalized HTTPS URL or `null` |
| `official_repository_url` | normalized HTTPS URL or `null` |
| `package_name` | one to 128 ASCII package characters matching `^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)?$` or `null` |
| `package_version` | one to 64 printable ASCII characters, exact immutable release only, or `null`; `latest`, wildcards, ranges, branches, and constraints are rejected |
| `php_support_evidence_url` | normalized HTTPS URL or `null` |
| `published_archive_sha256` | exactly 64 lowercase hexadecimal characters or `null`; never learned from downloaded bytes |
| `release_page_url` | normalized HTTPS URL or `null` |
| `resource_bound_evidence_urls` | zero to 32 unique normalized HTTPS URLs sorted by UTF-8 bytes |
| `security_advisory_url` | normalized HTTPS URL or `null` |
| `semantic_coverage_evidence_urls` | zero to 32 unique normalized HTTPS URLs sorted by UTF-8 bytes |
| `signature_url` | normalized HTTPS URL or `null` |
| `source_archive_url` | normalized HTTPS URL or `null`; record-only and never dereferenced during discovery |
| `source_revision` | exact 40-lowercase-hex Git commit, or another one to 128 ASCII authority-defined immutable identifier; mutable branch/tag aliases are rejected; otherwise `null` |
| `unresolved_acquisition_evidence` | zero to 32 unique Section 6.2 blocker enums sorted by UTF-8 bytes |

The enclosing report binds `candidate_record_version = 1`; it is not repeated inside each closed candidate object. Unknown, missing, extra, duplicated, invalidly ordered, overlength, control-containing, or wrong-type fields invalidate the report before it can support a later proposal.

Every non-null scalar field and every array field or array element also requires the exact Section 8.1 provenance mapping. `null` remains an explicit absence plus a closed acquisition blocker where applicable; it is never replaced by an unsupported claim. A syntactically valid candidate object with incomplete, conflicting, or malformed provenance is invalid.

### 6.1 URL normalization

Every URL is one to 2,048 ASCII bytes and must:

- use lowercase `https` scheme, an owner-approved lowercase ASCII DNS host, and implicit or explicit port `443` only;
- contain no userinfo, password, IP literal, fragment, control byte, backslash, encoded slash/backslash, encoded control, dot segment, duplicate separator ambiguity, or Unicode hostname;
- use an absolute path beginning `/`, with RFC 3986 percent escapes normalized to uppercase hexadecimal;
- contain no query unless it byte-matches one exact allowed query string in the authority record; and
- canonicalize byte-identically to the literal request-manifest URL.

Redirect targets are never normalized into authority; redirects are rejected.

### 6.2 Acquisition blocker enums

`unresolved_acquisition_evidence` may contain only:

- `ARCHIVE_BYTE_COUNT_UNKNOWN`
- `ARCHIVE_SHA256_UNPUBLISHED`
- `DEPENDENCY_CLOSURE_UNVERIFIED`
- `FONT_ASSET_RIGHTS_UNVERIFIED`
- `LICENSE_TEXT_NOT_ACQUIRED`
- `MAINTENANCE_STATUS_UNRESOLVED`
- `OS_COMPATIBILITY_UNPROVED`
- `PHP_RUNTIME_UNPROVED`
- `RESOURCE_BOUNDS_UNPROVED`
- `SECURITY_STATUS_UNRESOLVED`
- `SEMANTIC_COVERAGE_UNPROVED`
- `SIGNATURE_UNAVAILABLE`
- `SOURCE_ARCHIVE_BYTES_NOT_ACQUIRED`
- `SOURCE_REVISION_UNVERIFIED`

Free-text blockers are prohibited. The Markdown summary may explain an enum using sanitized prose and evidence-page IDs, but it cannot replace the enum.

## 7. Closed discovery dispositions

Every record has exactly one disposition:

| Disposition | Exact meaning |
|---|---|
| `DISCOVERY_CANDIDATE` | primary metadata is sufficient to nominate the exact package/revision for a later acquisition proposal; not admitted or selected |
| `INSUFFICIENT_PRIMARY_EVIDENCE` | required primary metadata is missing, contradictory, or not on an approved authority |
| `REJECTED_LICENSE` | exact primary license evidence is incompatible with the owner-approved research/distribution policy; legal review still controls |
| `REJECTED_COMPATIBILITY` | primary declarations exclude PHP 8.3/8.5 or required Windows/production-OS feasibility |
| `REJECTED_NOT_INDEPENDENT` | parser shares the renderer trust root or delegates semantic authority to it |
| `REJECTED_SEMANTIC_COVERAGE` | primary evidence cannot support the complete RF06/RF13 rejection policy |
| `REJECTED_UNBOUNDED_PROCESSING` | primary evidence shows absent/unconfigurable required bounds or an inherently unbounded model |
| `ACQUISITION_EVIDENCE_REQUIRED` | metadata is promising but a source archive/license/dependency fact requires separately authorized byte acquisition to establish |

Disposition priority is deterministic: an already frozen RF rejection remains; otherwise a positive exact rejection reason is used in table order from `REJECTED_LICENSE` through `REJECTED_UNBOUNDED_PROCESSING`; otherwise `INSUFFICIENT_PRIMARY_EVIDENCE` precedes `ACQUISITION_EVIDENCE_REQUIRED`; only a record satisfying every discovery field needed for acquisition nomination becomes `DISCOVERY_CANDIDATE`. No score, confidence percentage, or `approved` status exists.

## 8. Sanitized discovery evidence

A later approved run may propose, but not create under this checkpoint, only:

```text
docs/superpowers/evidence/packet-renderer-parser/discovery/<approved_discovery_run_id>/
    candidate-discovery-request-manifest.json
    evidence-pages.json
    candidate-field-provenance.json
    candidate-records.json
    discovery-summary.md
    files.sha256
```

This is a research-evidence boundary, not a production/test implementation allowlist. No raw HTML, package/archive, repository clone, source file, executable, PDF, font, cookie, credential, current-site data, or response header containing sensitive infrastructure data is retained.

Each `evidence-page-record-v1` is a closed union with `source_kind = network_primary` or `approved_local_record`.

A `network_primary` page record contains only: `authority_id`; raw and strict-UTF-8 body byte counts and SHA-256 values; `candidate_ids`; validated `charset`; exact `content_encoding = identity`; normalized `content_type`; `dns_answer_set_sha256`; `evidence_page_id`; `header_byte_count`; `header_field_count`; exact `http_status = 200`; exact booleans `pinned_address_in_validated_set = true` and `peer_address_matches_pin = true`; `request_id`; `requested_url`; identical `response_url`; `result_code`; `source_kind`; streamed entity byte count; and exact body framing `content_length` or `chunked`. It retains no raw address, peer, header, or body.

An `approved_local_record` page record binds an approved decision or request-manifest file through `evidence_page_id`, repository/evidence-root-relative path, source commit or owner-approved file SHA-256, media type, byte count, body SHA-256, candidate IDs, result code, and `source_kind`. It contains no absolute path or mutable file identity. This local form supplies auditable provenance only for policy-derived fields such as candidate ID, component, disposition, and blocker enums; it cannot support upstream package claims.

### 8.1 Exact field-level provenance

Every non-null candidate field is covered by one or more `candidate-field-provenance-v1` records. A scalar requires at least one record. A non-empty array requires one record for each exact JSON Pointer element. An empty array requires one field-level record whose normalized value is exact `[]`. The closed record contains exactly:

- `candidate_id`;
- `claim_code`;
- `evidence_page_id` referencing one retained Section 8 page record;
- `excerpt_kind`, exact `exact_json_scalar`, `sanitized_text_excerpt`, or `policy_derivation`;
- `excerpt_value`, bounded as defined below;
- `field_path`, one RFC 6901 JSON Pointer into the candidate record;
- `normalized_value`, the exact canonical JSON scalar, array element, or `[]` stored at that path;
- `provenance_id`, matching `^[a-z][a-z0-9_]{0,95}$`;
- `source_locator`; and
- `source_segment_sha256`.

`claim_code` is one of: `ACQUISITION_BLOCKER_DERIVATION`, `CANDIDATE_ID_DERIVATION`, `COMPONENT_CLASSIFICATION`, `DEPENDENCY_MANIFEST_URL`, `DISCOVERY_STATUS_DERIVATION`, `LICENSE_IDENTIFIER`, `LICENSE_URL`, `OFFICIAL_PROJECT_URL`, `OFFICIAL_REPOSITORY_URL`, `PACKAGE_NAME`, `PACKAGE_VERSION`, `PHP_SUPPORT_EVIDENCE_URL`, `PUBLISHED_ARCHIVE_SHA256`, `RELEASE_PAGE_URL`, `RESOURCE_BOUND_EVIDENCE_URL`, `SECURITY_ADVISORY_URL`, `SEMANTIC_COVERAGE_EVIDENCE_URL`, `SIGNATURE_URL`, `SOURCE_ARCHIVE_URL`, or `SOURCE_REVISION`.

For JSON, `source_locator` is exact `json-pointer:<RFC6901 pointer>` and `excerpt_value` is the exact JSON scalar encoded canonically, at most 1,024 UTF-8 bytes. For HTML or text, `source_locator` is exact `utf8-byte-range:<zero-based start>:<positive length>` against the strict decoded-body SHA-256; `source_segment_sha256` binds that source range; and `excerpt_value` is `ghca-discovery-excerpt-v1`, at most 512 Unicode scalar values and 2,048 UTF-8 bytes. That deterministic sanitation strips markup, comments, script/style content, and controls; decodes entities; converts line endings to LF; collapses HTML whitespace; applies NFC; and never paraphrases. For policy derivation, the locator is exact `markdown-line-range:<positive start>:<end>` or `json-pointer:<pointer>` inside an `approved_local_record`; the normalized value must be mechanically derived by the named claim code.

Before discarding a response body, the discovery process must validate every locator against the hashed body, reconstruct every excerpt/scalar, and prove every non-null candidate value has complete provenance. Two primary records for one field path may coexist only when their normalized values are byte-identical. For an optional scalar that permits `null`, differing normalized values or contradictory primary evidence set the field to `null`, add the applicable blocker, force `INSUFFICIENT_PRIMARY_EVIDENCE`, record the conflict without choosing a winner, and prevent nomination. A conflict affecting a required non-null scalar, required array, derived disposition, malformed locator, or source-segment mismatch invalidates the candidate record and full report. Any unmapped non-null field or array element also invalidates the full report.

This bounded evidence is sufficient to audit the specific retained claim without retaining a raw page. A later reviewer can inspect the exact excerpt/scalar immediately and may re-fetch only under a new authorization; any re-fetch is comparable only when its strict decoded-body SHA-256 matches the recorded hash.

The discovery summary states facts, contradictions, null fields, blocker enums, and dispositions. It must say explicitly that documentation evidence is not component admission, execution proof, legal approval, or selection.

## 9. CD01-CD20 decisions

### CD01 - Scope and non-goals

**Status.** Proposed; documentation-only discovery design.

**Exact decision.** CD01-CD20 define how a future owner-authorized process may discover candidate metadata through bounded read-only HTTPS GETs. This checkpoint performs no network request and authorizes no discovery run, acquisition, package byte, research asset, execution, selection, or packet implementation.

**Evidence and rationale.** CR20 requires a separate exact candidate-discovery/acquisition record and keeps the later gates separate.

**Security and correctness implications.** Prevents a metadata proposal from becoming supply-chain or runtime authority.

**Explicit exclusions.** Network execution, download, clone, copy, Composer, extraction, installation, harness, fixture, PDF work, database, Docker, current site, runtime wiring, activation, deployment.

**Remaining owner/operator evidence.** Literal request manifest and Gates 2b-6.

**Evidence identifiers.** `[RETAINED_EXISTING] CD01-SIX-GATE-BOUNDARY`; `[FUTURE_EXECUTABLE] CD01-DISCOVERY-ONLY`; `[FUTURE_EXECUTABLE] CD01-NO-ARCHIVE-SIDE-EFFECT`; `[DEFERRED_OPERATOR_EVIDENCE] CD01-DISCOVERY-RUN-SEPARATE`.

**Exact approval clause.** `Approve CD01's proposal-only discovery design and authorize no network request, acquisition, execution, selection, or implementation.`

### CD02 - Authority and evidence hierarchy

**Status.** Proposed.

**Exact decision.** Preserve Section 2's authority order. A primary page can support only the claim class in Section 5. Multiple mutable pages cannot substitute for an immutable source archive/hash, execution result, legal approval, or frozen architecture contract.

**Evidence and rationale.** RF02 and CR02 reject reputation, installed presence, and incomplete evidence as selection authority.

**Security and correctness implications.** Prevents circular citations, authority inversion, and documentation-only package approval.

**Explicit exclusions.** Search snippets, mirrors, forums, AI lists, issue opinions, marketing claims, and unsourced summaries.

**Remaining owner/operator evidence.** Approved authority records and independent evidence review.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CD02-AUTHORITY-ORDER-CLOSED`; `[FUTURE_EXECUTABLE] CD02-CLAIM-SCOPE-ENFORCED`; `[FUTURE_EXECUTABLE] CD02-REPUTATION-NOT-EVIDENCE`; `[DEFERRED_OPERATOR_EVIDENCE] CD02-AUTHORITY-MANIFEST-APPROVED`.

**Exact approval clause.** `Approve CD02's evidence hierarchy and prohibit discovery metadata from satisfying acquisition, execution, legal, or selection gates.`

### CD03 - Discovery versus acquisition boundary

**Status.** Proposed; acquisition blocked.

**Exact decision.** Discovery may read bounded documentation pages and record an archive URL, published checksum, or signature URL. It may never dereference those artifact URLs, copy installed package trees, clone/fetch repositories, invoke a resolver, or learn an expected hash from acquired bytes. Acquisition requires a later CR06-complete owner record.

**Evidence and rationale.** CR Gate 2 requires exact acquisition authority and expected bytes/hash before any copy or extraction.

**Security and correctness implications.** Eliminates trust-on-first-use and prevents disguised downloads.

**Explicit exclusions.** Release assets, `dist` URLs, raw archives, Composer install/update, Git operations, and offline copy.

**Remaining owner/operator evidence.** Exact candidate archives, byte counts, hashes, signatures, and acquisition commands.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CD03-ARTIFACT-URL-RECORD-ONLY`; `[FUTURE_EXECUTABLE] CD03-NO-TOFU-HASH`; `[FUTURE_EXECUTABLE] CD03-NO-PACKAGE-BYTES`; `[DEFERRED_OPERATOR_EVIDENCE] CD03-ACQUISITION-GATE-SEPARATE`.

**Exact approval clause.** `Approve CD03's discovery/acquisition separation and authorize no archive dereference, package copy, clone, extraction, or installation.`

### CD04 - Permitted official-source research

**Status.** Design proposed; future run separately blocked.

**Exact decision.** A later run may contact only literal requests in an owner-approved Section 4 manifest and only the official authority classes in Section 5. Every request uses one validated public-only DNS answer set, one deterministically pinned address, original-host SNI and Host, and exact post-connect peer equality. Every new host, path, query, linked page, re-resolution, address fallback, proxy, or peer mismatch requires rejection rather than implicit authority.

**Evidence and rationale.** Parser candidates are presently unknown, so a generic host or hyperlink-following authority would be unbounded.

**Security and correctness implications.** Constrains SSRF, supply-chain impersonation, and accidental browsing.

**Explicit exclusions.** Generic search engines, arbitrary GitHub accounts, unapproved registries, mirrors, link crawling, and dynamically generated URLs.

**Remaining owner/operator evidence.** Exact discovery run ID, authority records, request list, network window, and reviewer.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CD04-LITERAL-REQUEST-MANIFEST`; `[FUTURE_EXECUTABLE] CD04-PRIMARY-AUTHORITY-ONLY`; `[FUTURE_EXECUTABLE] CD04-NEW-LINK-REAPPROVAL`; `[DEFERRED_OPERATOR_EVIDENCE] CD04-DISCOVERY-RUN-MANIFEST-APPROVED`; `[FUTURE_EXECUTABLE] CD-DNS-PINNED-PEER`; `[FUTURE_EXECUTABLE] CD-DNS-REBIND-REJECTED`; `[FUTURE_EXECUTABLE] CD-DNS-MIXED-ADDRESS-REJECTED`.

**Exact approval clause.** `Approve CD04's primary-source and literal-request design while authorizing no network discovery run.`

### CD05 - Prohibited network and package operations

**Status.** Closed prohibition proposed.

**Exact decision.** Enforce Section 4.2 mechanically: HTTP/1.1 GET only, no authentication/body/cookies, zero redirects, `Accept-Encoding: identity`, identity-only content encoding, bounded headers and streamed body, one unambiguous body framing, strict permitted media/charset validation without sniffing, no archive/binary media, no package resolver, and no writes outside later approved sanitized evidence files.

**Evidence and rationale.** Read-only intent is insufficient without transport, response, and filesystem enforcement.

**Security and correctness implications.** Limits credential disclosure, response smuggling into acquisition, and uncontrolled supply-chain mutation.

**Explicit exclusions.** POST/PUT/PATCH/DELETE/HEAD, WebSocket, FTP, SSH, Git, Composer, browser automation, login, proxy auth, disabled TLS, and package execution.

**Remaining owner/operator evidence.** Later client implementation and transport-security review.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CD05-GET-ONLY`; `[FUTURE_EXECUTABLE] CD05-ZERO-REDIRECT`; `[FUTURE_EXECUTABLE] CD05-ARCHIVE-RESPONSE-BLOCKED`; `[FUTURE_EXECUTABLE] CD05-NO-AUTH-OR-SITE-DATA`; `[FUTURE_EXECUTABLE] CD-CONTENT-ENCODING-REJECTED`; `[FUTURE_EXECUTABLE] CD-RESPONSE-AMPLIFICATION-BLOCKED`; `[FUTURE_EXECUTABLE] CD-AMBIGUOUS-LENGTH-REJECTED`.

**Exact approval clause.** `Approve CD05's closed network prohibitions and permit no authenticated, mutating, redirecting, binary, resolver, or package operation.`

### CD06 - Renderer discovery criteria

**Status.** Criteria proposed; no renderer nominated or admitted.

**Exact decision.** Renderer discovery must seek the exact package/version/revision and official authority; release/archive/checksum/signature metadata; license/dependencies/fonts/templates/assets; PHP/OS declarations; ambient clock/random/metadata/compression/filesystem/network/cache/locale/font behavior; deterministic configuration or patch feasibility; maintenance/advisory status; and archive-owned fork feasibility. Every absent fact is null plus a blocker.

**Evidence and rationale.** RF03/RF05 and CR04 define a conjunctive renderer gate that documentation can only preliminarily inform.

**Security and correctness implications.** Prevents mutable or under-specified generators from advancing by brand recognition.

**Explicit exclusions.** Installed integration approval, deterministic claims without byte vectors, inferred patchability, and a producer literal.

**Remaining owner/operator evidence.** Exact candidates, acquisition, patches, manifests, licensing, runtime vectors, and measurements.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CD06-RENDERER-METADATA-COMPLETE`; `[FUTURE_EXECUTABLE] CD06-AMBIENT-BEHAVIOR-DISCOVERED`; `[FUTURE_EXECUTABLE] CD06-MISSING-FACT-NULL-BLOCKER`; `[DEFERRED_OPERATOR_EVIDENCE] CD06-RENDERER-CANDIDATE-REVIEW`.

**Exact approval clause.** `Approve CD06's renderer discovery criteria while nominating, admitting, and selecting no renderer.`

### CD07 - Semantic-parser discovery criteria

**Status.** Criteria proposed; no parser candidate named.

**Exact decision.** Parser discovery must additionally seek independent trust-root evidence; exact handling of encryption, JavaScript, actions, launch, files/attachments, rich media, XFA, AcroForm, external references, malformed objects/xrefs/xref streams, unresolved references, trailing payload, recursive graphs, decompression bombs, unsupported constructs, and positive page count; plus configurable object/recursion/expansion/memory/time/temp bounds and fail-closed API behavior.

**Evidence and rationale.** RF06/RF07/RF13 reject the locally installed parser families for incomplete semantics, bounds, or independence.

**Security and correctness implications.** Prevents an importer, renderer-internal parser, or lexical scanner from becoming the security oracle.

**Explicit exclusions.** Reconsidering TCPDF/FPDI/mPDF without materially new evidence, warning-only behavior, and documentation as execution proof.

**Remaining owner/operator evidence.** An exact independent candidate, primary documentation, acquisition, adversarial results, and measured bounds.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CD07-PARSER-INDEPENDENCE-DISCOVERED`; `[FUTURE_EXECUTABLE] CD07-SEMANTIC-COVERAGE-MAPPED`; `[FUTURE_EXECUTABLE] CD07-RESOURCE-BOUNDS-MAPPED`; `[DEFERRED_OPERATOR_EVIDENCE] CD07-PARSER-CANDIDATE-REVIEW`.

**Exact approval clause.** `Approve CD07's semantic-parser discovery criteria while naming, admitting, and selecting no parser.`

### CD08 - Exact candidate-record grammar

**Status.** Closed schema proposed.

**Exact decision.** Every future candidate record must conform byte-for-byte to Section 6, including strict types, field order, null semantics, URL normalization, blocker enums, and one closed disposition. Every non-null scalar, array, and array element must have exact Section 8.1 provenance. Unknown, malformed, unmapped, or conflicting content invalidates or blocks the report exactly as Section 8.1 specifies.

**Evidence and rationale.** Open notes cannot support a mechanical later acquisition gate.

**Security and correctness implications.** Makes missing evidence and authority boundaries machine-reviewable.

**Explicit exclusions.** Extra fields, free-text status, inferred values, confidence scores, and executable configuration.

**Remaining owner/operator evidence.** Future schema implementation and independent parser/validator review.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CD08-CLOSED-RECORD-ACCEPTED`; `[FUTURE_EXECUTABLE] CD08-MISSING-EXTRA-WRONG-TYPE-REJECTED`; `[FUTURE_EXECUTABLE] CD08-NULLS-NOT-GUESSES`; `[FUTURE_EXECUTABLE] CD08-DISPOSITION-ONE-OF-CLOSED`; `[FUTURE_EXECUTABLE] CD-CANDIDATE-FIELD-PROVENANCE-EXACT`; `[FUTURE_EXECUTABLE] CD-UNMAPPED-FIELD-REJECTED`; `[FUTURE_EXECUTABLE] CD-CONFLICTING-FIELD-EVIDENCE-BLOCKS`.

**Exact approval clause.** `Approve CD08's exact candidate-record grammar and reject every unknown, guessed, malformed, or ambiguously disposed record.`

### CD09 - Version and immutable-revision evidence

**Status.** Discovery rule proposed.

**Exact decision.** Record one exact release version and immutable source revision only when an approved primary source binds them. Mutable aliases, version ranges, branches, `latest`, wildcard constraints, and tag text without immutable revision evidence remain null/blocking.

**Evidence and rationale.** CR04-CR06 require exact immutable identity before acquisition.

**Security and correctness implications.** Prevents later source substitution behind a familiar version label.

**Explicit exclusions.** Composer constraint resolution, tag-only admission, inferred Git commits, and local header as upstream authority.

**Remaining owner/operator evidence.** Primary release-to-revision bindings for each candidate.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CD09-EXACT-VERSION-ONLY`; `[FUTURE_EXECUTABLE] CD09-IMMUTABLE-REVISION-BOUND`; `[FUTURE_EXECUTABLE] CD09-LATEST-WILDCARD-REJECTED`; `[DEFERRED_OPERATOR_EVIDENCE] CD09-RELEASE-REVISION-REVIEWED`.

**Exact approval clause.** `Approve CD09's exact version/revision rules and prohibit mutable aliases, ranges, branches, and inferred identities.`

### CD10 - Source archive and checksum evidence

**Status.** Metadata discovery proposed; archive access blocked.

**Exact decision.** Discovery may record a literal official archive URL, published lowercase SHA-256, and signature URL only from an approved primary page. It never requests those URLs and never computes the expected archive hash from acquired bytes. Missing official hash remains null and `ARCHIVE_SHA256_UNPUBLISHED`.

**Evidence and rationale.** The known mPDF dist metadata provides a URL/revision but no owner-approved archive digest.

**Security and correctness implications.** Preserves pre-acquisition trust and rejects TOFU.

**Explicit exclusions.** Download, HEAD probing, checksum from mirrors, API dist request, redirect resolution, and package-manager cache use.

**Remaining owner/operator evidence.** Exact archive byte count/hash/signature and acquisition authority.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CD10-ARCHIVE-URL-NEVER-FETCHED`; `[FUTURE_EXECUTABLE] CD10-PUBLISHED-HASH-PRIMARY-ONLY`; `[FUTURE_EXECUTABLE] CD10-MISSING-HASH-BLOCKS`; `[DEFERRED_OPERATOR_EVIDENCE] CD10-ACQUISITION-EVIDENCE-LATER`.

**Exact approval clause.** `Approve CD10's record-only archive/checksum discovery and authorize no archive request or trust-on-first-use hash.`

### CD11 - License and redistribution evidence

**Status.** Evidence discovery proposed; no legal conclusion.

**Exact decision.** Record exact primary license identifier/page and any published notice, source, modification, redistribution, font, template, asset, or combined-work obligations. Conflicts remain blockers. Only owner-controlled legal review can assign `REJECTED_LICENSE` or approve later acquisition/distribution.

**Evidence and rationale.** RF17/CR08 preserve unresolved TCPDF, mPDF, FPDI, dependency, and font/asset obligations.

**Security and correctness implications.** Prevents registry labels or bundled texts from being mistaken for combined-distribution approval.

**Explicit exclusions.** Legal advice, SPDX-only approval, inferred font rights, and treating installation as redistribution authority.

**Remaining owner/operator evidence.** Exact license texts acquired later, notice/source obligations, font/asset rights, and written legal/owner disposition.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CD11-LICENSE-PRIMARY-EVIDENCE`; `[FUTURE_EXECUTABLE] CD11-CONFLICT-BLOCKS`; `[DEFERRED_OPERATOR_EVIDENCE] CD11-LEGAL-DISPOSITION-SEPARATE`; `[DEFERRED_OPERATOR_EVIDENCE] CD11-FONT-ASSET-RIGHTS-SEPARATE`.

**Exact approval clause.** `Approve CD11's license-evidence discovery rules while making no legal, redistribution, font, or asset-rights determination.`

### CD12 - Dependency and SBOM discovery

**Status.** Metadata discovery proposed; SBOM bytes blocked.

**Exact decision.** Record official dependency-manifest URLs and declared runtime/optional dependencies, font/template/asset models, native extensions, external executables, and update channels. Discovery cannot declare dependency closure or create an SPDX SBOM without acquired exact bytes/manifests.

**Evidence and rationale.** CR07 requires pristine/patched manifests and an SPDX 2.3 SBOM after acquisition.

**Security and correctness implications.** Exposes transitive/runtime supply-chain surfaces without fabricating closure.

**Explicit exclusions.** Composer resolution, lockfile generation, transitive download, dynamic plugin discovery, and SBOM inferred from one registry page.

**Remaining owner/operator evidence.** Exact acquired manifest set, dependency edges, files, patches, fonts/assets, and independent SBOM review.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CD12-DECLARED-DEPENDENCIES-RECORDED`; `[FUTURE_EXECUTABLE] CD12-DYNAMIC-DEPENDENCY-BLOCKER`; `[FUTURE_EXECUTABLE] CD12-NO-SBOM-WITHOUT-BYTES`; `[DEFERRED_OPERATOR_EVIDENCE] CD12-SBOM-GATE-LATER`.

**Exact approval clause.** `Approve CD12's dependency discovery boundary while authorizing no resolution, download, lockfile, or SBOM creation.`

### CD13 - Security advisory and maintenance evidence

**Status.** Discovery criteria proposed.

**Exact decision.** Record exact official project/vendor advisory URL, supported-version policy, release cadence evidence, security contact/process, end-of-life statement, and known candidate-version advisories. An empty advisory page never proves absence of vulnerabilities; conflicting or stale maintenance evidence blocks nomination.

**Evidence and rationale.** RF10/RF17 and CR19 require a vulnerability and maintenance model for exact bytes.

**Security and correctness implications.** Prevents abandoned or unsupported code from advancing without visibility.

**Explicit exclusions.** Generic CVE search snippets, issue-count scoring, popularity, download counts, and claims of vulnerability-free code.

**Remaining owner/operator evidence.** Exact acquired-code scan, advisory reconciliation, update policy, and owner security acceptance.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CD13-ADVISORY-AUTHORITY-RECORDED`; `[FUTURE_EXECUTABLE] CD13-EMPTY-NOT-VULNERABILITY-FREE`; `[FUTURE_EXECUTABLE] CD13-MAINTENANCE-CONFLICT-BLOCKS`; `[DEFERRED_OPERATOR_EVIDENCE] CD13-SECURITY-REVIEW-LATER`.

**Exact approval clause.** `Approve CD13's advisory and maintenance discovery rules without asserting that any candidate is secure, supported, or vulnerability-free.`

### CD14 - Semantic coverage evidence

**Status.** Documentation mapping proposed; proof blocked.

**Exact decision.** Map every RF06/RF13 semantic requirement to one or more approved primary documentation URLs or mark it null/blocking. General statements such as `secure`, `validates PDF`, or `supports PDF` satisfy no row. Unsupported, warning-only, or undocumented behavior prevents `DISCOVERY_CANDIDATE` parser status.

**Evidence and rationale.** Semantic security is conjunctive and cannot be inferred from parse success.

**Security and correctness implications.** Prevents broad marketing claims from masking active-content or malformed-input gaps.

**Explicit exclusions.** Regex/token scanning, renderer success, FPDI import success, and undocumented exception behavior.

**Remaining owner/operator evidence.** Acquired API inspection and independent adversarial execution for every row.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CD14-SEMANTIC-MATRIX-COMPLETE`; `[FUTURE_EXECUTABLE] CD14-GENERIC-CLAIM-REJECTED`; `[FUTURE_EXECUTABLE] CD14-UNDOCUMENTED-ROW-BLOCKS`; `[DEFERRED_OPERATOR_EVIDENCE] CD14-ADVERSARIAL-PROOF-LATER`.

**Exact approval clause.** `Approve CD14's exact semantic-documentation mapping while accepting no general validation claim as parser proof.`

### CD15 - Resource-bounding feasibility evidence

**Status.** Documentation discovery proposed; no ceiling approved.

**Exact decision.** Record candidate-declared APIs or mechanisms for input bytes, objects, recursion, decompressed bytes, memory, elapsed time, temporary storage, page count, and cancellation. Missing or host-default-only controls are blockers. Discovery freezes no packet page, memory, duration, expansion, recursion, object, or temp ceiling.

**Evidence and rationale.** RF16/CR16 require empirical per-run measurements and later exact owner-approved ceilings.

**Security and correctness implications.** Separates theoretical controllability from measured safety.

**Explicit exclusions.** Invented limits, PHP `memory_limit` as a component bound, averages, and claims based on another version.

**Remaining owner/operator evidence.** Acquired-code inspection, emergency containment, measurements, margins, and boundary/one-over tests.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CD15-BOUNDING-API-MAPPED`; `[FUTURE_EXECUTABLE] CD15-HOST-DEFAULT-BLOCKS`; `[FUTURE_EXECUTABLE] CD15-NO-CEILING-FROZEN`; `[DEFERRED_OPERATOR_EVIDENCE] CD15-MEASURED-CEILINGS-LATER`.

**Exact approval clause.** `Approve CD15's resource-feasibility discovery criteria and approve no new resource ceiling or bounded-execution claim.`

### CD16 - PHP and OS compatibility evidence

**Status.** Declaration discovery proposed; execution proof blocked.

**Exact decision.** Record exact official declarations for PHP `8.3` and `8.5`, required extensions, Windows support, and the future production OS family if published. A version range is declaration evidence only. Missing either required PHP declaration blocks nomination unless acquisition review can establish compatibility without changing code.

**Evidence and rationale.** RF14/CR14-CR15 require exact PHP 8.3.30/8.5.7 and Windows/production-image execution with byte identity.

**Security and correctness implications.** Prevents a broad package constraint from being reported as tested compatibility.

**Explicit exclusions.** PHP 8.3 substituting for 8.5, generic Linux, `latest` images, and documentation as byte-vector evidence.

**Remaining owner/operator evidence.** Exact environment manifests, acquired code, controlled execution, and cross-runtime/OS byte results.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CD16-PHP83-PHP85-DECLARATIONS`; `[FUTURE_EXECUTABLE] CD16-DECLARATION-NOT-EXECUTION`; `[FUTURE_EXECUTABLE] CD16-OS-CLAIMS-EXACT`; `[DEFERRED_OPERATOR_EVIDENCE] CD16-CROSS-RUNTIME-OS-PROOF-LATER`.

**Exact approval clause.** `Approve CD16's compatibility-evidence rules while treating every declaration as unproved until exact runtime and OS execution.`

### CD17 - Sanitized discovery report

**Status.** Output design proposed; report creation blocked.

**Exact decision.** A later run may create only Section 8's bounded manifest, evidence-page records, field-level provenance records, candidate records, summary, and file hashes. It retains the minimum exact JSON scalar or deterministic sanitized excerpt needed to audit each claim, but no raw page/package bytes, secrets, infrastructure, local paths, current-site data, exception text, or full response body.

**Evidence and rationale.** Discovery evidence must be reviewable without becoming a package cache, data leak, or archive fact.

**Security and correctness implications.** Preserves privacy and makes every claim traceable to a bounded primary request.

**Explicit exclusions.** Raw HTML/headers, cookies, IPs, TLS details, package archives, source copies, PDFs, ordinary application logs, and archive storage.

**Remaining owner/operator evidence.** Exact future claim-code schema, retention duration/access, request manifest, and report review.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CD17-SANITIZED-OUTPUT-ONLY`; `[FUTURE_EXECUTABLE] CD17-RAW-RESPONSE-NOT-RETAINED`; `[FUTURE_EXECUTABLE] CD17-CLAIM-TO-REQUEST-TRACEABLE`; `[DEFERRED_OPERATOR_EVIDENCE] CD17-RETENTION-AND-RUN-APPROVED`; `[FUTURE_EXECUTABLE] CD-DISCARDED-BODY-CLAIMS-AUDITABLE`.

**Exact approval clause.** `Approve CD17's sanitized report design while authorizing no network run, report file, or raw response retention.`

### CD18 - Stop and escalation conditions

**Status.** Closed stop grammar proposed.

**Exact decision.** Stop the whole run before further requests on authentication/token demand; non-HTTPS or unapproved host/path/query; redirect; mixed/non-public/re-resolved DNS answer, unpinned connection, proxy substitution, Host/SNI change, or peer mismatch; compressed or ambiguously framed response; header/body/count/time bound; invalid media type/charset/sniffing need; archive/binary/attachment response; attempted download; incomplete or conflicting field provenance; conflicting version/revision/hash/license; missing primary source; sensitive/current-site access; code execution request; research-asset need; cleanup/handle failure; or any required architecture/retained-contract revision. Partial output nominates no candidate.

**Evidence and rationale.** Continuing after a trust-boundary failure can turn discovery into acquisition or hide contradictory evidence.

**Security and correctness implications.** Fails closed on supply-chain, SSRF, privacy, and scope expansion risks.

**Explicit exclusions.** Best-effort continuation, silent redirect, retry through another mirror, credential prompt, partial candidate approval, and message-text classification.

**Remaining owner/operator evidence.** Later client failure injection and incident procedure.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CD18-STOP-CONDITIONS-CLOSED`; `[FUTURE_EXECUTABLE] CD18-PARTIAL-RUN-NOMINATES-NOTHING`; `[FUTURE_EXECUTABLE] CD18-REDIRECT-BINARY-AUTH-BLOCKED`; `[FUTURE_EXECUTABLE] CD18-CONTRACT-CHANGE-ESCALATES`.

**Exact approval clause.** `Approve CD18's closed stop conditions and prohibit partial nomination or continuation after any trust, scope, privacy, or containment failure.`

### CD19 - Later acquisition proposal requirements

**Status.** Gate defined; acquisition unauthorized.

**Exact decision.** A later acquisition proposal must select exact `DISCOVERY_CANDIDATE` records and freeze for each: owner-approved authority; one literal archive/offline source; exact filename, byte count, and expected lowercase SHA-256 from independent primary evidence; signature verification if used; acquisition time/window and command; zero/unexpected redirect policy; quarantine path; file-type check; extraction path; traversal/symlink/device/collision defenses; pristine file manifest; dependency/license/font/asset inventory; cleanup; and stop rules. It must separately request package copy/download/extraction authority.

**Evidence and rationale.** CD evidence identifies what might be acquired; CR06-CR08 govern how exact bytes become research inputs.

**Security and correctness implications.** Prevents discovery approval from automatically importing code.

**Explicit exclusions.** Automatic acquisition of every discovery candidate, Composer, latest resolution, hash learned after download, and harness/fixture/execution authority.

**Remaining owner/operator evidence.** Complete candidate records and one owner-approved acquisition proposal.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CD19-ACQUISITION-RECORD-COMPLETE`; `[FUTURE_EXECUTABLE] CD19-EXPECTED-HASH-INDEPENDENT`; `[FUTURE_EXECUTABLE] CD19-ACQUISITION-AUTHORITY-EXPLICIT`; `[DEFERRED_OPERATOR_EVIDENCE] CD19-ACQUISITION-APPROVAL-LATER`.

**Exact approval clause.** `Approve CD19's later acquisition-proposal requirements and authorize no package copy, download, extraction, installation, or execution.`

### CD20 - Exact owner authorization and remaining gates

**Status.** Documentation-only approval recommended.

**Exact decision.** Approve only CD01-CD20's discovery design. A separate owner record must approve one literal discovery-run manifest before network access. Another must approve exact candidate acquisition; later records remain required for research assets, controlled execution, component selection, and packet implementation. Admit and select no renderer/parser and preserve every RF/CR/PM/CA/CS, PM17-PM19, D16, constructed-dark, retained-data, verification/finalization, scheduling, publication, activation, and deployment boundary.

**Evidence and rationale.** No parser candidate, approved authority/request manifest, archive/hash set, acquired bytes, harness, fixtures, execution results, selection evidence, or implementation allowlist exists.

**Security and correctness implications.** Makes the smallest reversible authority explicit.

**Explicit exclusions.** Network execution under this approval and Gates 2b-6 in full.

**Remaining owner/operator evidence.** Sections 10-11 and every later gate.

**Evidence identifiers.** `[FUTURE_EXECUTABLE] CD20-NO-COMPONENT-ADMITTED`; `[FUTURE_EXECUTABLE] CD20-NO-NETWORK-WITHOUT-RUN-MANIFEST`; `[FUTURE_EXECUTABLE] CD20-FROZEN-DEFERRALS-UNCHANGED`; `[DEFERRED_OPERATOR_EVIDENCE] CD20-LATER-GATES-SEPARATE`.

**Exact approval clause.** `Approve CD20's documentation-only discovery design and keep network execution, acquisition, assets, experiments, selection, and implementation separately blocked.`

## 10. Evidence identifier inventory

CD01-CD20 retain the original 80 identifiers and add ten focused remediation identifiers for pinned DNS, response amplification/framing, and auditable field provenance. Deferred operator evidence is never an executed assertion.

| Classification | Count | Meaning |
|---|---:|---|
| `RETAINED_EXISTING` | 1 | Existing six-gate constructed-dark boundary |
| `FUTURE_EXECUTABLE` | 71 | Later request-manifest, DNS pinning, response framing, provenance, schema, source, stop, and acquisition-gate checks |
| `DEFERRED_OPERATOR_EVIDENCE` | 18 | Owner, legal, authority, run, retention, environment, measurement, acquisition, and review evidence |
| **Total** | **90** | Unique identifiers |

## 11. Remaining blockers

Before any discovery network request:

1. owner-approved `candidate-discovery-request-manifest-v1` bytes and SHA-256;
2. exact authority host/path/query records and every literal request URL;
3. exact discovery run ID, network window, client, and sanitized user-agent;
4. reviewed one-resolution DNS validation, address pinning, original-host SNI/Host, and post-connect peer-equality enforcement;
5. reviewed identity-only encoding, bounded header/body, unambiguous framing, strict media/charset, and no-sniff enforcement;
6. closed evidence-page, claim-code, field-provenance, excerpt/scalar, conflict, and report validators;
7. output retention, access, cleanup, and incident procedure; and
8. explicit owner authorization of that run.

Before acquisition:

1. one or more complete `DISCOVERY_CANDIDATE` records;
2. exact archive/offline source and independently published expected byte count/SHA-256;
3. candidate-specific license, dependencies, fonts/assets, and security review;
4. exact quarantine/extraction paths and safe extraction contract;
5. exact acquisition command and network/offline authority; and
6. separate owner approval expressly permitting package bytes.

Research assets, controlled execution, component selection, and packet implementation retain every CR19/CR20 blocker. Discovery creates no producer key/version/package digest, parser authority, golden vector, resource ceiling, implementation path, failure tuple, or archive fact.

## 12. Proposal-only verification contract

This checkpoint must run only:

- PHP 8.3.30 boundaries and digests;
- PHP 8.5.7 boundaries and digests;
- CD01-CD20 heading and approval-clause counts;
- evidence-ID count, classification, uniqueness, and exact presence of all ten remediation identifiers;
- candidate disposition and candidate-record field checks;
- DNS pinning/peer, response encoding/framing/charset, and field-provenance completeness/contradiction checks;
- scans for accidental network execution/acquisition, package admission/selection, unmeasured packet ceilings, implementation allowlists, and weakened RF/CR/PM/CA/CS deferrals;
- UTF-8 without BOM and zero trailing whitespace;
- an explicit proposal-only `git -c core.autocrlf=false diff --no-index --check -- NUL <proposal>` whitespace check, without reading or diffing authorized bystanders;
- entrypoint reference verification; and
- final branch/HEAD, zero staged proposal state, and absence of any additional unexpected path beyond the separately authorized untouched bystanders.

No database, Docker, network, package, Git remote, PDF, parser, current-site, staging, commit, push, activation, or deployment action belongs to this checkpoint.

## 13. Exact owner decision request

> **Approve Candidate Discovery Decisions CD01-CD20 as written for documentation-only discovery design: approve the preserved six-gate architecture, discovery/acquisition separation, future literal-manifest read-only HTTPS GET grammar, explicit primary-source authority records, one-resolution public-only DNS validation with deterministic connection pinning, preserved Host/SNI and post-connect peer equality, unauthenticated zero-redirect no-archive network boundary, identity-only content encoding, bounded headers and streamed body, unambiguous HTTP framing, strict media/charset validation without sniffing, closed candidate/evidence/field-provenance schemas, exact bounded excerpts or JSON scalars for every non-null field, renderer and independent semantic-parser discovery criteria, primary-evidence/null/blocker rules, closed discovery dispositions, sanitized report, stop conditions, and later exact acquisition-proposal requirements; preserve CR01-CR20, RF01-RF20, PM01-PM20, CA01-CA20, CS01-CS20, PM17-PM19, D16, constructed-dark, retained-data, verification/finalization, scheduling, publication, activation, and deployment boundaries; keep TCPDF 6.11.2 and mPDF 8.2.7 archive-owned forks and a minimal writer as unadmitted directions, keep installed TCPDF/mPDF/FPDI parser paths rejected, admit and select no renderer or parser, require a separate owner-approved discovery-run manifest naming every literal URL and authority before any network request, and require still-separate acquisition, research-asset, controlled-execution, component-selection, and packet-implementation approvals; and authorize no network research, archive or package request, download, clone, Composer use, copy, acquisition, installation, extraction, execution, harness, fixture, PDF generation/parsing, current-site access, implementation allowlist, runtime wiring, activation, or deployment.**

Approval of this sentence records only `CANDIDATE_DISCOVERY_DESIGN_APPROVED`. It does not authorize `CANDIDATE_DISCOVERY_RUN_APPROVED` or any later gate.

**Approval record:** On 2026-08-25, the owner formally approved CD01-CD20 exactly as quoted above for documentation-only discovery design. This records only `CANDIDATE_DISCOVERY_DESIGN_APPROVED`. No discovery-run network request, acquisition, research asset, controlled execution, component selection, packet implementation, current-site access, runtime wiring, activation, or deployment is authorized.
