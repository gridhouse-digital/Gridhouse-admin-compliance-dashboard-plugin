# Slice 1B-P1 persistence contract decisions

**Status:** accepted for the dark-mode persistence slice on 2026-07-16. These decisions do not enable runtime wiring, workers, evidence capture, artifact materialization, or reset execution.

## 1. Idempotency-scope digest

The v1 idempotency-scope digest is:

`SHA-256("ghca-idempotency-scope-v1\n" + canonical_json(scope_document))`

The scope document contains exactly `actor_or_integration_namespace`, `case_key_digest_or_global_scope`, `command_type`, `site_id`, and `tenant_id`. The Unit of Work recomputes this digest and exact-compares it with the accepted command envelope.

The actor namespace is derived, never caller-selected:

- `wp_user:{actor_user_id}` for a WordPress user actor;
- `{actor_kind}:{authority_code}` for every non-user actor.

## 2. Stream identity storage

- `cycle_key_digest` is the lowercase hexadecimal SHA-256 digest of the frozen canonical `cycle_key` bytes.
- `cycle_policy_key` is `{policy_key}|{positive_decimal_policy_version}`.
- A loaded stream must reproduce its `case_key_digest` from all five frozen case-key constituents and must reproduce every cycle field from its canonical cycle key.

These encodings are storage bindings only. They do not change the frozen case-key, command, event, or canonical-JSON digest documents.

## 3. Durable task policy

- Task schema version: `1`.
- Maximum attempts: `5`.
- Initial state: `pending`; initial attempt count: `0`.
- Dedupe digest: `SHA-256("ghca-archive-task-dedupe-v1\n" + canonical_json({payload, task_type, trigger_event_id}))`.
- Task payloads carry canonical format version, task schema version, task type, trigger event ID, and only the identifiers needed to revalidate authoritative state when work begins.
- Claims, heartbeats, completions, retries, and dead-letter transitions are compare-and-set operations. Every leased-row mutation requires the exact `task_id`, `task_state = leased`, `lease_owner`, and `lease_token`, and requires `lease_until_gmt > now`. A heartbeat may only extend a live lease. A lease may be reclaimed when `now >= lease_until_gmt`; a live lease may not be stolen.
- Every loaded task row must use task schema version 1 and must reproduce its canonical payload bindings and task-dedupe digest. Unknown schema versions and malformed or contradictory retained rows fail closed before work can begin.
- No task lease expiry infers a lifecycle event or state transition.

The Unit of Work inserts required tasks in the same transaction as their triggering event, projections, stream head, and command receipt.

## 4. Later-slice atomic side records

`RecordEvidenceSnapshot`, `RecordMaterializedArtifact`, and `VerifyAndFinalize` are rejected by the production Unit of Work with `atomic_side_records_unavailable` until the separately authorized snapshot/artifact repositories and verifier are present. This is the required fail-closed boundary from the development handoff: a lifecycle event may not claim that a snapshot or artifact exists without the corresponding immutable database records in the same transaction.

Projector/event-store coverage for these event types may use an explicitly test-only component transaction. Such coverage is not Unit-of-Work acceptance and creates no command receipt or durable task.
