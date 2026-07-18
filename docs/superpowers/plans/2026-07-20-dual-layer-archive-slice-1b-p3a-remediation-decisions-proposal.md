# Slice 1B-P3A Remediation Decisions Proposal

**Status:** approved for P3A remediation implementation with binding clarifications
**Scope:** confirmed P3A findings 3 and 5 only
**Runtime effect:** none
**Approval evidence:** repository owner approved R1 and R2 with the amendments recorded below

**Security-review erratum:** R2's structural validation does not authenticate caller-supplied continuation state. P3A remains unaccepted and the cursor implementation must not be committed until the pending H1 decision in `2026-07-21-dual-layer-archive-slice-1b-p3a-cursor-authentication-decision-proposal.md` is approved and implemented.

**Resolution:** H1 was subsequently approved and implemented, and the authenticated cursor remediation passed independent review. Decision C2 completed the compatibility gate. P3A is now formally accepted.

## Why this checkpoint is required

The approved P3A record says handlers may return only machine fields and must not return free-form context, but it defines no allowed outcome fields or field-specific string grammar. The higher-authority PRD and Technical Design prohibit credentials, tokens, paths, PII, and evidence payloads; they do not define a grammar that distinguishes those values from safe machine strings.

The approved orphan result contains candidates, counts, `scanned`, and `truncated`, but has no continuation input or output. Stateless forward progress after a bounded truncated scan requires an approved cursor contract. An arbitrary unordered directory cannot simultaneously provide deterministic global order, a hard 1,000-new-entry inspection ceiling, and stateless continuation without carrying bounded traversal state between invocations.

The remediation instruction required an owner decision in both cases. The binding approval recorded below now authorizes the P3A remediation and named regressions.

## Binding approval amendments

1. The R1 9-byte ceiling applies to the dynamic value `committed`. The fixed key `result_code` is 11 bytes. The complete canonical document remains exactly 27 bytes.
2. The first R2 call freezes `older_than_epoch`. Every continuation reuses the cursor-bound cutoff and must not recalculate it from the clock.
3. Cursor contents are untrusted. Immediately before emitting a buffered candidate, revalidate its logical key, containment, regular-file/symlink status, mtime, and cutoff eligibility. A changed candidate fails with `orphan_cursor_stale`; malformed or contradictory cursor content fails with `orphan_cursor_invalid`.
4. An incomplete traversal returns no candidate page. Only a complete bounded multi-call traversal may emit up to 100 globally ordered `(mtime, logical_key)` candidates. A later page starts another bounded traversal filtered exclusively after the last emitted tuple.

These amendments are part of the approved R1/R2 contract and authorize implementation of all five confirmed P3A remediation findings without another owner checkpoint.

## Decision R1: closed P3A handler-outcome document

### Recommended contract

For P3A, accept exactly this handler result:

```php
array(
	'logical_outcome' => 'completed',
	'outcome' => array(
		'result_code' => 'committed',
	),
)
```

No other outcome key or value is accepted. In particular, P3A does not approve generic strings, IDs, digests, paths, URLs, emails, tokens, credentials, evidence values, nested arrays, or handler error/context text. Later production handlers must obtain approval for task-specific, field-by-field outcome schemas; this generic coordinator contract will not guess them.

The exact P3A ceilings are:

| Boundary | Approved value proposed |
|---|---:|
| Nested outcome depth | 1 top-level outcome map; nested arrays are prohibited |
| Outcome value count | exactly 1 |
| Fixed key | exact `result_code`, 11 bytes |
| Dynamic string value | exact literal `committed`, 9 UTF-8 bytes |
| Canonical outcome byte ceiling | 27 bytes: `{"result_code":"committed"}` |

The coordinator first calls the existing canonical JSON encoder. That shared boundary rejects recursion, unsupported values, invalid UTF-8, excessive depth, excessive values, and excessive strings. It then enforces the stricter exact P3A document and 27-byte ceiling. Any failure maps to the existing fixed `task_handler_failed` disposition; raw handler values or exception text are never persisted or returned.

### Reason

P3A has no production handlers. One exact success document covers the directly callable test coordinator without inventing future business output fields or a token grammar that could still carry secrets. It is the smallest contract that makes all requested bounds executable and prevents free-form leakage by construction.

### Affected code after approval

- `GHCA_ACD_Archive_Worker_Coordinator::assert_handler_outcome()`
- removal of the permissive recursive `machine_value()` helper
- P3A test handlers updated to return the exact approved document

### Stable failure behavior

- Existing operational code: `task_handler_failed`
- Existing fixed text: `The task handler failed.`
- No new persisted failure code or handler text

### Required named regressions

- `WORKER-OUTCOME-DEPTH-BOUND`
- `WORKER-OUTCOME-VALUE-COUNT-BOUND`
- `WORKER-OUTCOME-STRING-BOUND`
- `WORKER-OUTCOME-RECURSION-REJECTED`
- `WORKER-OUTCOME-FREE-FORM-REJECTED`
- `WORKER-OUTCOME-VALID-MACHINE-FIELDS`

### Later change impact

Operational only for P3A because no production handler is registered and no generic handler-outcome document is retained. A later production handler needs a separately approved exact outcome schema before activation.

## Decision R2: bounded stateless orphan continuation

### Recommended public contract

Approve an optional opaque cursor on the existing store and reconciler methods:

```php
GHCA_ACD_Archive_Artifact_Store::enumerate_candidates(
	int $older_than_epoch,
	int $limit = 1000,
	?array $cursor = null
): array;

GHCA_ACD_Archive_Orphan_Reconciler::reconcile( ?array $cursor = null ): array;
```

Both results add `next_cursor`, which is either a validated cursor document or `null`. Existing first-call behavior remains `cursor = null`. P3A does not persist the cursor; the direct caller passes the returned value to the next invocation.

### Cursor and enumeration invariants

- Cursor schema version: 1.
- Maximum canonical cursor size: 32,768 bytes.
- The first call freezes `older_than_epoch`; every continuation uses that cursor-bound cutoff and never recalculates it from the clock.
- Cursor is bound to the exact frozen safety cutoff and validated private root identity used for the scan.
- Every cursor field is untrusted and validated before it influences traversal, filtering, or output.
- Cursor contains only relative immutable-ID paths, non-negative directory positions, bounded candidate tuples, and version/paging fields. It contains no absolute path, PII, credential, or artifact bytes.
- Enumeration replaces `scandir()` with streaming `DirectoryIterator`/`FilesystemIterator` operations from the PHP standard library.
- One invocation inspects at most 1,000 new filesystem entries and retains at most 101 candidate tuples in memory.
- A bounded depth-first continuation stack records the next directory position. A resumed invocation seeks to that position instead of revisiting the prior prefix.
- Candidate tuples are maintained by `(mtime, logical_key)`. An incomplete traversal returns no candidates. Results are emitted only when a complete bounded traversal cycle has selected the next page, preserving deterministic global order.
- One result page contains at most 100 candidates. A retained 101st tuple proves another page exists and produces a fresh cursor whose exclusive `after` tuple is the last emitted `(mtime, logical_key)`.
- Every buffered candidate is reopened and revalidated immediately before emission: exact key grammar, containment, regular-file and non-symlink status, unchanged mtime, and eligibility before the frozen cutoff. A changed candidate fails with `orphan_cursor_stale`; malformed or contradictory cursor content fails with `orphan_cursor_invalid`.
- `truncated = true` while traversal or result paging remains; `next_cursor = null` only when the requested ordered page and the complete scan are exhausted.
- The deterministic guarantee applies while the scanned tree is unchanged during one cursor cycle. An observably changed active directory invalidates the cursor; the caller restarts from `null`.
- Reconciliation remains report-only. No file or database row is deleted, quarantined, moved, renamed, replaced, or otherwise mutated.
- Descriptor recheck remains immediate before every emitted candidate classification.

### Reason

A last-key-only cursor cannot resume an unordered directory without either rescanning an unbounded prefix or missing lexically earlier entries encountered later. Carrying a bounded traversal stack plus the next 101 ordered candidates is the smallest stateless contract that preserves the hard scan, result, memory, ordering, and progress requirements without schema or filesystem mutation.

### Affected code after approval

- `GHCA_ACD_Archive_Artifact_Store::enumerate_candidates()` signature
- `GHCA_ACD_Private_Archive_Artifact_Store` streaming enumeration and cursor validation
- `GHCA_ACD_Archive_Orphan_Reconciler::reconcile()` optional cursor and `next_cursor` result
- P3 storage/orphan tests only

### Stable failure codes proposed

- `orphan_cursor_invalid`: malformed, oversized, contradictory, or wrong-root/cutoff cursor
- `orphan_cursor_stale`: an observably changed active directory or buffered candidate invalidated continuation/emission
- Existing `orphan_scan_failed` remains for enumeration I/O failure

No cursor failure infers or appends a lifecycle event.

### Required named regressions

- `ORPHAN-TRUNCATED-SCAN-MAKES-PROGRESS`
- `ORPHAN-TRUNCATED-SCAN-NO-DUPLICATE-PREFIX`
- `ORPHAN-LARGE-DIRECTORY-BOUNDED`
- `ORPHAN-STABLE-ORDER-ACROSS-PAGES`
- `ORPHAN-REPORT-ONLY-NO-MUTATION`

### Later change impact

Operational contract only. No retained artifact descriptor, logical key, artifact byte, task row, event, snapshot, or ledger row changes. Cursor version 1 must remain readable while an in-progress cursor may still be presented; production persistence/scheduling of cursors remains outside P3A.

## Rejected shortcuts

- A generic machine-token regex: an alphanumeric token can still be a credential or evidence value.
- Silently rejecting only strings with spaces, `@`, slash, or backslash: this does not prevent secret leakage.
- Process-global or static scan position: not durable and not safe across invocations/workers.
- Repeated `scandir()`: unbounded directory materialization remains.
- A last-key-only cursor over native directory order: it can repeat or permanently skip entries.
- Database cursor state, schema changes, staging-key changes, directory sharding, deletion, or quarantine: outside P3A authorization.

## Owner approval recorded

The repository owner approved Decisions R1 and R2 with the binding amendments above. This authorizes implementation of all five confirmed remediation findings, their named regressions, the complete verification matrix, and the P3A traceability update. It does not authorize P3B, runtime wiring, staging, commit, push, deployment, activation, or current-site database access.

## Cursor-authentication supplement

Post-remediation review proved that the R2 structural cursor checks did not authenticate caller-supplied state. The owner subsequently approved Decision H1 as written in `2026-07-21-dual-layer-archive-slice-1b-p3a-cursor-authentication-decision-proposal.md`. H1 now supplies the missing full-canonical-cursor HMAC and first-use-only cutoff contract. The bounded traversal, ordering, revalidation, and report-only R2 rules above remain unchanged.
