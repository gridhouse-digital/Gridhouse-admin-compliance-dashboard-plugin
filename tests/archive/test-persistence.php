<?php
/**
 * Destructive disposable-database tests for Slice 1B-P1: transactional event
 * persistence and synchronous projections.
 */

require __DIR__ . '/persistence-bootstrap.php';
require __DIR__ . '/persistence-fixtures.php';

global $wpdb;
$database = $wpdb->get_row( 'SELECT VERSION() AS version, @@version_comment AS version_comment' );

ghca_persist_fresh_schema( $wpdb );
$stack = ghca_persist_stack( $wpdb, '2026-07-16T12:00:00Z', 'main' );

function persist_event_rows( $db, ?string $stream_id = null ) {
	$table = ghca_persist_quote_identifier( $db->prefix . 'ghca_acd_archive_events' );
	$sql   = "SELECT * FROM {$table}";
	if ( null !== $stream_id ) {
		$sql = $db->prepare( "SELECT * FROM {$table} WHERE stream_id = %s ORDER BY stream_sequence ASC", $stream_id );
	}
	$rows = $db->get_results( $sql, ARRAY_A );
	if ( $db->last_error ) {
		throw new RuntimeException( 'event row read failed: ' . $db->last_error );
	}
	return (array) $rows;
}

function persist_row( $db, string $table_suffix, string $where_column, string $value ) {
	$table = ghca_persist_quote_identifier( $db->prefix . 'ghca_acd_archive_' . $table_suffix );
	$row   = $db->get_row( $db->prepare( "SELECT * FROM {$table} WHERE {$where_column} = %s", $value ), ARRAY_A );
	if ( $db->last_error ) {
		throw new RuntimeException( 'projection row read failed: ' . $db->last_error );
	}
	return $row;
}

function persist_count( $db, string $table_suffix ): int {
	$table = ghca_persist_quote_identifier( $db->prefix . 'ghca_acd_archive_' . $table_suffix );
	$count = $db->get_var( "SELECT COUNT(*) FROM {$table}" );
	if ( $db->last_error ) {
		throw new RuntimeException( 'count read failed: ' . $db->last_error );
	}
	return (int) $count;
}

/** @param array<string,mixed> $caller @param array<string,mixed> $server @param array<string,mixed> $opts */
function persist_make_uow_request( GHCA_Persist_Scenario $s, string $command_type, array $caller, array $server, string $slot, array $opts = array() ): array {
	$scope = array_key_exists( 'scope_document', $opts ) ? $opts['scope_document'] : ghca_persist_scope_document( $s, $command_type );
	$scope_digest = array_key_exists( 'scope_digest', $opts ) ? $opts['scope_digest'] : GHCA_ACD_Archive_Digester::idempotency_scope( $scope );
	$command = call_user_func(
		array( 'GHCA_ACD_Archive_Command', ghca_persist_snake( $command_type ) ),
		$s->id( 'test-command-' . $slot ),
		$scope_digest,
		hash( 'sha256', 'test-key-' . $slot ),
		array_key_exists( 'expected_sequence', $opts ) ? $opts['expected_sequence'] : $s->head_sequence,
		array_key_exists( 'actor', $opts ) ? $opts['actor'] : $s->actor,
		$caller,
		$server
	);
	return array(
		'command'              => $command,
		'case_key'             => array_key_exists( 'case_key', $opts ) ? $opts['case_key'] : $s->case_key,
		'idempotency_scope'    => $scope,
		'expected_head_digest' => array_key_exists( 'expected_head_digest', $opts ) ? $opts['expected_head_digest'] : $s->head_digest,
		'correlation_id'       => $s->id( 'test-correlation-' . $slot ),
	);
}

/** Stream head, case cursor, and all three projector heads agree exactly. */
function persist_assert_stream_consistent( $db, GHCA_Persist_Scenario $s, string $label ): void {
	$stream = persist_row( $db, 'streams', 'stream_id', (string) $s->stream_id );
	archive_check( null !== $stream && (string) $stream['head_sequence'] === $s->head_sequence && (string) $stream['head_event_digest'] === $s->head_digest, $label . ' stream head matches the last committed response' );
	$events = persist_event_rows( $db, (string) $s->stream_id );
	archive_check( count( $events ) === (int) $s->head_sequence, $label . ' event count equals the stream head' );
	$case = persist_row( $db, 'case_state', 'stream_id', (string) $s->stream_id );
	archive_check( null !== $case && (string) $case['projected_sequence'] === $s->head_sequence && (string) $case['projected_event_digest'] === $s->head_digest, $label . ' case cursor matches the stream head' );
	$heads_table = ghca_persist_quote_identifier( $db->prefix . 'ghca_acd_archive_projection_heads' );
	$heads       = $db->get_results( $db->prepare( "SELECT * FROM {$heads_table} WHERE stream_id = %s ORDER BY projector_key", (string) $s->stream_id ), ARRAY_A );
	$aligned     = 3 === count( (array) $heads );
	foreach ( (array) $heads as $head ) {
		$aligned = $aligned && (string) $head['projected_sequence'] === $s->head_sequence && (string) $head['projected_event_digest'] === $s->head_digest;
	}
	archive_check( $aligned, $label . ' every projector head advanced to the stream head' );
}

// ---------------------------------------------------------------------------
// PERSIST-FIRST-STREAM-CREATE
// ---------------------------------------------------------------------------
$sA = new GHCA_Persist_Scenario( 'annual' );
$first_response = persist_request_archive( $stack, $sA );
archive_check( 'committed' === $first_response['result_code'] && '1' === $first_response['first_stream_sequence'] && '1' === $first_response['last_stream_sequence'], 'PERSIST-FIRST-STREAM-CREATE commits the first decision at sequence one' );
$streamA = persist_row( $wpdb, 'streams', 'case_key_digest', $sA->case_key->digest() );
archive_check( null !== $streamA && '1' === (string) $streamA['head_sequence'] && $streamA['head_event_digest'] === $first_response['head_event_digest'], 'PERSIST-FIRST-STREAM-CREATE creates the stream with an advanced technical head' );
archive_check( null !== $streamA && $streamA['tenant_id'] === '0123456789abcdef0123456789abcdef' && '1' === (string) $streamA['site_id'] && '42' === (string) $streamA['employee_user_id'] && 'annual' === $streamA['program_key'], 'PERSIST-FIRST-STREAM-CREATE stores every case-key constituent' );
$caseA = persist_row( $wpdb, 'case_state', 'stream_id', (string) $streamA['stream_id'] );
archive_check( null !== $caseA && 'REQUESTED' === $caseA['build_state'] && 'NOT_APPLICABLE' === $caseA['validity_state'] && 'NONE' === $caseA['reset_state'] && '1' === (string) $caseA['edit_locked'] && 'build_in_progress' === $caseA['edit_lock_reason'] && '0' === (string) $caseA['reset_eligible'], 'PERSIST-FIRST-STREAM-CREATE projects the requested case state synchronously' );
$revisionA = persist_row( $wpdb, 'revision_state', 'archive_id', $sA->id( 'archive-1' ) );
archive_check( null !== $revisionA && 'REQUESTED' === $revisionA['build_state'] && 'NOT_APPLICABLE' === $revisionA['validity_state'] && '1' === (string) $revisionA['last_changed_sequence'], 'PERSIST-FIRST-STREAM-CREATE projects the revision row' );
$receiptA = persist_row( $wpdb, 'commands', 'command_id', $sA->id( 'cmd-op-annual-1' ) );
archive_check( null !== $receiptA && 'accepted' === $receiptA['decision'] && 'committed' === $receiptA['result_code'] && GHCA_ACD_Archive_Canonical_JSON::encode( $first_response ) === $receiptA['response_json'], 'PERSIST-FIRST-STREAM-CREATE stores the insert-once receipt with the stable response' );
$capture_task = $wpdb->get_row( $wpdb->prepare(
	'SELECT * FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_tasks' ) . ' WHERE stream_id = %s AND task_type = %s',
	(string) $sA->stream_id,
	'capture_evidence'
), ARRAY_A );
$capture_payload = null === $capture_task ? null : GHCA_ACD_Archive_Canonical_JSON::decode_canonical( (string) $capture_task['payload_json'] );
$capture_dedupe = null === $capture_task ? '' : GHCA_ACD_Archive_Digester::task_dedupe( array(
	'payload'          => $capture_payload,
	'task_type'        => 'capture_evidence',
	'trigger_event_id' => $capture_task['trigger_event_id'],
) );
archive_check( null !== $capture_task && 'pending' === $capture_task['task_state'] && '0' === (string) $capture_task['attempt_count'] && '5' === (string) $capture_task['max_attempts'], 'TASK-INSERT-ATOMIC RequestArchive inserts one pending durable capture task' );
archive_check( is_array( $capture_payload ) && $capture_payload['archive_id'] === $sA->id( 'archive-1' ) && $capture_payload['stream_id'] === $sA->stream_id && hash_equals( $capture_task['dedupe_digest'], $capture_dedupe ), 'TASK-INSERT-ATOMIC task payload and dedupe digest bind the exact triggering event identities' );
persist_assert_stream_consistent( $wpdb, $sA, 'PERSIST-FIRST-STREAM-CREATE' );

// ---------------------------------------------------------------------------
// COMPONENT-MULTI-EVENT-ATOMIC (later-slice verification/finalization through
// the explicitly test-only event-store/projector transaction)
// ---------------------------------------------------------------------------
persist_start_build( $stack, $sA );
persist_record_snapshot( $stack, $sA );
persist_materialize( $stack, $sA, 'ledger' );
persist_materialize( $stack, $sA, 'packet' );
$finalize_response = persist_verify_finalize( $stack, $sA );
archive_check( '6' === $finalize_response['first_stream_sequence'] && '7' === $finalize_response['last_stream_sequence'], 'COMPONENT-MULTI-EVENT-ATOMIC commits both decision events in one transaction' );
$eventsA = persist_event_rows( $wpdb, (string) $streamA['stream_id'] );
archive_check( 'ArchiveVerified' === $eventsA[5]['event_type'] && 'ArchiveFinalized' === $eventsA[6]['event_type'], 'COMPONENT-MULTI-EVENT-ATOMIC preserves the ordered decision events' );
$revisionA = persist_row( $wpdb, 'revision_state', 'archive_id', $sA->id( 'archive-1' ) );
archive_check( null !== $revisionA && 'FINALIZED' === $revisionA['build_state'] && 'ACTIVE' === $revisionA['validity_state'] && '7' === (string) $revisionA['last_changed_sequence'], 'COMPONENT-MULTI-EVENT-ATOMIC projects finalization and activation together' );
$caseA = persist_row( $wpdb, 'case_state', 'stream_id', (string) $streamA['stream_id'] );
archive_check( null !== $caseA && $sA->id( 'archive-1' ) === $caseA['active_archive_id'] && '1' === (string) $caseA['reset_eligible'] && null === $caseA['reset_block_reason'], 'COMPONENT-MULTI-EVENT-ATOMIC yields an active archive with derived reset eligibility' );
persist_assert_stream_consistent( $wpdb, $sA, 'COMPONENT-MULTI-EVENT-ATOMIC' );

// ---------------------------------------------------------------------------
// PERSIST-IDEMPOTENCY-REPLAY (same dedupe + same client intent)
// ---------------------------------------------------------------------------
$fingerprint_before_replay = ghca_persist_db_fingerprint( $wpdb );
$replay_response = persist_request_archive( $stack, $sA, array(
	'idempotency_key'      => 'op-annual-1',
	'expected_sequence'    => '0',
	'expected_head_digest' => null,
	'no_track'             => true,
) );
archive_check( $replay_response === $first_response, 'PERSIST-IDEMPOTENCY-REPLAY returns the exact original committed response' );
archive_check( $fingerprint_before_replay === ghca_persist_db_fingerprint( $wpdb ), 'PERSIST-IDEMPOTENCY-REPLAY appends nothing and mutates nothing' );

// ---------------------------------------------------------------------------
// Remediation boundary regressions: command-bound dispatch, exact case/scope
// identity, and fail-closed later-slice side records.
// ---------------------------------------------------------------------------
$sBound = new GHCA_Persist_Scenario( 'prog-command-bound' );
$bound_payload = $sBound->payload( 'ArchiveRequested', array( 'archive_id' => $sBound->id( 'archive-1' ) ) );
list( $bound_caller, $bound_server ) = ghca_persist_split( 'RequestArchive', $bound_payload );
$callback_request = persist_make_uow_request( $sBound, 'RequestArchive', $bound_caller, $bound_server, 'callback' );
$callback_request['decision'] = static function ( GHCA_ACD_Archive_Case $case ) use ( $bound_payload ) { return $case->request_archive( $bound_payload ); };
persist_expect_failure( $wpdb, static function () use ( $stack, $callback_request ) {
	return $stack['uow']->execute( $callback_request );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'invalid_request', 'PERSIST-COMMAND-BOUND-DISPATCH' );

$other_case = new GHCA_Persist_Scenario( 'prog-other-case' );
$wrong_case_caller = $bound_caller;
$wrong_case_caller['case_key'] = $other_case->case_canonical;
$case_request = persist_make_uow_request( $sBound, 'RequestArchive', $wrong_case_caller, $bound_server, 'wrong-case' );
persist_expect_failure( $wpdb, static function () use ( $stack, $case_request ) {
	return $stack['uow']->execute( $case_request );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'command_case_mismatch', 'PERSIST-COMMAND-CASE-BINDING' );

$digest_request = persist_make_uow_request( $sBound, 'RequestArchive', $bound_caller, $bound_server, 'wrong-scope-digest', array(
	'scope_digest' => str_repeat( 'f', 64 ),
) );
persist_expect_failure( $wpdb, static function () use ( $stack, $digest_request ) {
	return $stack['uow']->execute( $digest_request );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'idempotency_scope_digest_mismatch', 'PERSIST-IDEMPOTENCY-SCOPE-DIGEST-BINDING' );

$wrong_namespace_scope = ghca_persist_scope_document( $sBound, 'RequestArchive' );
$wrong_namespace_scope['actor_or_integration_namespace'] = 'integration:foreign';
$namespace_request = persist_make_uow_request( $sBound, 'RequestArchive', $bound_caller, $bound_server, 'wrong-namespace', array(
	'scope_document' => $wrong_namespace_scope,
) );
persist_expect_failure( $wpdb, static function () use ( $stack, $namespace_request ) {
	return $stack['uow']->execute( $namespace_request );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'invalid_idempotency_scope', 'PERSIST-IDEMPOTENCY-ACTOR-NAMESPACE' );

$sGate = new GHCA_Persist_Scenario( 'prog-side-gate' );
persist_request_archive( $stack, $sGate );
persist_start_build( $stack, $sGate );
$gate_payload = $sGate->payload( 'EvidenceSnapshotCaptured', array(
	'archive_id'            => $sGate->id( 'archive-1' ),
	'snapshot_id'           => $sGate->id( 'snapshot-1' ),
	'certificate_asset_ids' => array( $sGate->id( 'cert-1' ), $sGate->id( 'cert-2' ) ),
) );
list( $gate_caller, $gate_server ) = ghca_persist_split( 'RecordEvidenceSnapshot', $gate_payload );
$gate_request = persist_make_uow_request( $sGate, 'RecordEvidenceSnapshot', $gate_caller, $gate_server, 'snapshot-gate' );
persist_expect_failure( $wpdb, static function () use ( $stack, $gate_request ) {
	return $stack['uow']->execute( $gate_request );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'side_records_required', 'PERSIST-ATOMIC-SIDE-RECORD-REQUIRED' );

// ---------------------------------------------------------------------------
// Reset lifecycle on the finalized case (request/authorize/claim/complete).
// ---------------------------------------------------------------------------
persist_request_reset( $stack, $sA );
$resetA = persist_row( $wpdb, 'reset_state', 'reset_operation_id', $sA->id( 'reset-1' ) );
archive_check( null !== $resetA && 'REQUESTED' === $resetA['reset_state'] && $sA->scope_digest === $resetA['scope_digest'] && GHCA_ACD_Archive_Canonical_JSON::encode( $sA->scope ) === $resetA['scope_json'], 'RESET-PROJECTION reset request row stores the exact canonical scope' );
persist_authorize_reset( $stack, $sA );
$authA = persist_row( $wpdb, 'reset_authorizations', 'authorization_id', $sA->id( 'auth-1' ) );
archive_check( null !== $authA && 'issued' === $authA['auth_state'] && $sA->id( 'reset-1' ) === $authA['reset_operation_id'] && null === $authA['terminal_event_id'], 'RESET-PROJECTION authorization enforcement row is issued once' );
persist_claim_reset( $stack, $sA );
$authA = persist_row( $wpdb, 'reset_authorizations', 'authorization_id', $sA->id( 'auth-1' ) );
archive_check( null !== $authA && 'consumed' === $authA['auth_state'] && null !== $authA['consumed_at_gmt'] && null !== $authA['terminal_event_id'], 'RESET-PROJECTION claim atomically consumes the single-use authorization' );
persist_single( $stack, $sA, 'CompleteReset', 'complete_reset', $sA->payload( 'ResetCompleted', array( 'reset_operation_id' => $sA->id( 'reset-1' ) ) ) );
$resetA = persist_row( $wpdb, 'reset_state', 'reset_operation_id', $sA->id( 'reset-1' ) );
$caseA  = persist_row( $wpdb, 'case_state', 'stream_id', (string) $streamA['stream_id'] );
archive_check( null !== $resetA && 'COMPLETED' === $resetA['reset_state'] && 'completed' === $resetA['outcome_code'], 'RESET-PROJECTION completed reset records its stable outcome' );
archive_check( null !== $caseA && 'NONE' === $caseA['reset_state'] && '0' === (string) $caseA['reset_eligible'] && 'destructive_reset_recorded' === $caseA['reset_block_reason'], 'RESET-PROJECTION a completed destructive reset blocks any later reset' );
persist_assert_stream_consistent( $wpdb, $sA, 'RESET-PROJECTION' );

// ---------------------------------------------------------------------------
// PERSIST-RESPONSE-LOSS-STALE-VERSION (retry after the head moved to 11)
// ---------------------------------------------------------------------------
$fingerprint_before_stale = ghca_persist_db_fingerprint( $wpdb );
$stale_replay = persist_request_archive( $stack, $sA, array(
	'idempotency_key'      => 'op-annual-1',
	'expected_sequence'    => '0',
	'expected_head_digest' => null,
	'no_track'             => true,
) );
archive_check( $stale_replay === $first_response, 'PERSIST-RESPONSE-LOSS-STALE-VERSION returns the original response despite a stale expected version' );
archive_check( $fingerprint_before_stale === ghca_persist_db_fingerprint( $wpdb ), 'PERSIST-RESPONSE-LOSS-STALE-VERSION performs no writes' );

// ---------------------------------------------------------------------------
// PERSIST-LOAD-ORDERED
// ---------------------------------------------------------------------------
$loadedA = $stack['event_store']->load_events( (string) $streamA['stream_id'] );
$expected_types = array(
	'ArchiveRequested', 'ArchiveBuildStarted', 'EvidenceSnapshotCaptured', 'LedgerMaterialized',
	'PacketMaterialized', 'ArchiveVerified', 'ArchiveFinalized', 'ResetRequested',
	'ResetAuthorized', 'ResetExecutionClaimed', 'ResetCompleted',
);
$ordered = count( $loadedA ) === 11;
foreach ( $loadedA as $index => $event ) {
	$ordered = $ordered && $event->stream_sequence() === (string) ( $index + 1 ) && $event->type() === $expected_types[ $index ];
}
archive_check( $ordered, 'PERSIST-LOAD-ORDERED loads the complete verified stream in exact sequence order' );

// ---------------------------------------------------------------------------
// Scenario B: failure, retry, cancellation; no-op cursor advancement.
// ---------------------------------------------------------------------------
$sB = new GHCA_Persist_Scenario( 'prog-b' );
persist_request_archive( $stack, $sB );
persist_start_build( $stack, $sB );
persist_single( $stack, $sB, 'FailArchive', 'fail_archive', $sB->payload( 'ArchiveFailed', array(
	'archive_id'         => $sB->id( 'archive-1' ),
	'build_attempt_id'   => $sB->id( 'attempt-1' ),
	'phase'              => 'capturing',
	'sealed_snapshot_id' => null,
) ) );
$caseB_before_retry     = persist_row( $wpdb, 'case_state', 'stream_id', (string) $sB->stream_id );
$revisionB_before_retry = persist_row( $wpdb, 'revision_state', 'archive_id', $sB->id( 'archive-1' ) );
persist_single( $stack, $sB, 'RetryArchive', 'request_retry', $sB->payload( 'ArchiveRetryRequested', array(
	'archive_id'             => $sB->id( 'archive-1' ),
	'prior_build_attempt_id' => $sB->id( 'attempt-1' ),
	'new_build_attempt_id'   => $sB->id( 'attempt-2' ),
	'resume_phase'           => 'capturing',
	'sealed_snapshot_id'     => null,
) ) );

// PROJECTOR-ADVANCES-NOOP: the retry is a semantic no-op for every entity row.
$caseB     = persist_row( $wpdb, 'case_state', 'stream_id', (string) $sB->stream_id );
$revisionB = persist_row( $wpdb, 'revision_state', 'archive_id', $sB->id( 'archive-1' ) );
archive_check( '4' === (string) $caseB['projected_sequence'] && $caseB['state_changed_at_gmt'] === $caseB_before_retry['state_changed_at_gmt'] && 'FAILED' === $caseB['build_state'], 'PROJECTOR-ADVANCES-NOOP the case cursor advances while business state is unchanged' );
archive_check( (string) $revisionB['last_changed_sequence'] === (string) $revisionB_before_retry['last_changed_sequence'] && 'FAILED' === $revisionB['build_state'], 'PROJECTOR-ADVANCES-NOOP the revision row is untouched by the no-op event' );
persist_assert_stream_consistent( $wpdb, $sB, 'PROJECTOR-ADVANCES-NOOP' );

persist_start_build( $stack, $sB, array( 'build_attempt_id' => $sB->id( 'attempt-2' ), 'retry_ordinal' => 1 ) );
$cancel_payload = $sB->payload( 'ArchiveCancelled', array(
	'archive_id'       => $sB->id( 'archive-1' ),
	'build_attempt_id' => $sB->id( 'attempt-2' ),
) );
persist_single( $stack, $sB, 'CancelArchive', 'cancel_archive', $cancel_payload, array( 'idempotency_key' => 'op-cancel-b' ) );
$revisionB = persist_row( $wpdb, 'revision_state', 'archive_id', $sB->id( 'archive-1' ) );
$caseB     = persist_row( $wpdb, 'case_state', 'stream_id', (string) $sB->stream_id );
archive_check( 'CANCELLED' === $revisionB['build_state'] && '0' === (string) $caseB['edit_locked'] && null === $caseB['edit_lock_reason'], 'CANCELLATION releases the derived edit lock with no independent block' );
persist_assert_stream_consistent( $wpdb, $sB, 'CANCELLATION' );

// ---------------------------------------------------------------------------
// PERSIST-IDEMPOTENCY-CONFLICT (same dedupe digest, different client intent)
// ---------------------------------------------------------------------------
$conflict_payload = $cancel_payload;
$conflict_payload['cancellation_reason'] = 'different-reason';
persist_expect_failure( $wpdb, static function () use ( $stack, $sB, $conflict_payload ) {
	return persist_single( $stack, $sB, 'CancelArchive', 'cancel_archive', $conflict_payload, array( 'idempotency_key' => 'op-cancel-b', 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'idempotency_conflict', 'PERSIST-IDEMPOTENCY-CONFLICT' );

// ---------------------------------------------------------------------------
// PERSIST-EXPECTED-SEQUENCE-CONFLICT and PERSIST-HEAD-DIGEST-CONFLICT
// (loser on a second independent real connection)
// ---------------------------------------------------------------------------
$conn2  = ghca_persist_new_connection();
$stack2 = ghca_persist_stack( $conn2, '2026-07-16T12:05:00Z', 'conn2' );

// ---------------------------------------------------------------------------
// TASK-LEASE-FENCING: two real connections race for one task. Every mutation
// requires the exact live owner/token pair; expiry revokes the old lease even
// before another worker reclaims it.
// ---------------------------------------------------------------------------
$lease_owner_a = $sA->id( 'worker-a' );
$lease_owner_b = $sA->id( 'worker-b' );
$lease_token_a = $sA->id( 'lease-a' );
$lease_token_b = $sA->id( 'lease-b' );
$claimed_a = $stack['task_store']->claim( $capture_task['task_id'], $lease_owner_a, $lease_token_a, '2026-07-16T12:01:00Z', '2026-07-16T12:11:00Z' );
$claimed_b = $stack2['task_store']->claim( $capture_task['task_id'], $lease_owner_b, $lease_token_b, '2026-07-16T12:02:00Z', '2026-07-16T12:12:00Z' );
archive_check( $claimed_a && ! $claimed_b, 'TASK-LEASE-FENCING exactly one real connection claims a live task lease' );
persist_expect_failure( $wpdb, static function () use ( $stack2, $capture_task, $lease_owner_b, $lease_token_a ) {
	$stack2['task_store']->complete( $capture_task['task_id'], $lease_owner_b, $lease_token_a, '2026-07-16T12:03:00Z' );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_completion_fence_failed', 'TASK-LEASE-WRONG-OWNER-COMPLETION' );
persist_expect_failure( $wpdb, static function () use ( $stack2, $capture_task, $lease_owner_a, $lease_token_b ) {
	$stack2['task_store']->complete( $capture_task['task_id'], $lease_owner_a, $lease_token_b, '2026-07-16T12:03:00Z' );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_completion_fence_failed', 'TASK-LEASE-STALE-COMPLETION' );
persist_expect_failure( $wpdb, static function () use ( $stack2, $capture_task, $lease_owner_b, $lease_token_a ) {
	$stack2['task_store']->heartbeat( $capture_task['task_id'], $lease_owner_b, $lease_token_a, '2026-07-16T12:03:00Z', '2026-07-16T12:13:00Z' );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_heartbeat_fence_failed', 'TASK-LEASE-WRONG-OWNER-HEARTBEAT' );
persist_expect_failure( $wpdb, static function () use ( $stack, $capture_task, $lease_owner_a, $lease_token_a ) {
	$stack['task_store']->heartbeat( $capture_task['task_id'], $lease_owner_a, $lease_token_a, '2026-07-16T12:03:00Z', '2026-07-16T12:10:00Z' );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_heartbeat_fence_failed', 'TASK-LEASE-NON-EXTENDING-HEARTBEAT' );
$stack['task_store']->heartbeat( $capture_task['task_id'], $lease_owner_a, $lease_token_a, '2026-07-16T12:04:00Z', '2026-07-16T12:14:00Z' );
$heartbeat_task = $stack['task_store']->find( $capture_task['task_id'] );
archive_check( '2026-07-16 12:14:00' === $heartbeat_task['lease_until_gmt'], 'TASK-LEASE-HEARTBEAT the exact live owner/token pair extends the lease' );
$stack['task_store']->complete( $capture_task['task_id'], $lease_owner_a, $lease_token_a, '2026-07-16T12:05:00Z' );
$completed_capture = $stack['task_store']->find( $capture_task['task_id'] );
archive_check( 'completed' === $completed_capture['task_state'] && '1' === (string) $completed_capture['attempt_count'] && null === $completed_capture['lease_token'], 'TASK-LEASE-FENCING the exact lease token completes and clears the task lease' );

$sLease = new GHCA_Persist_Scenario( 'prog-task-reclaim' );
persist_request_archive( $stack, $sLease );
$reclaim_task = $wpdb->get_row( $wpdb->prepare(
	'SELECT * FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_tasks' ) . ' WHERE stream_id = %s',
	(string) $sLease->stream_id
), ARRAY_A );
$reclaim_token_a = $sLease->id( 'lease-old' );
$reclaim_token_b = $sLease->id( 'lease-new' );
$stack['task_store']->claim( $reclaim_task['task_id'], $lease_owner_a, $reclaim_token_a, '2026-07-16T12:01:00Z', '2026-07-16T12:02:00Z' );
$reclaim_before_expiry_checks = $stack['task_store']->find( $reclaim_task['task_id'] );
persist_expect_failure( $wpdb, static function () use ( $stack, $reclaim_task, $lease_owner_a, $reclaim_token_a ) {
	$stack['task_store']->heartbeat( $reclaim_task['task_id'], $lease_owner_a, $reclaim_token_a, '2026-07-16T12:03:00Z', '2026-07-16T12:13:00Z' );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_heartbeat_fence_failed', 'TASK-LEASE-EXPIRED-HEARTBEAT' );
persist_expect_failure( $wpdb, static function () use ( $stack, $reclaim_task, $lease_owner_a, $reclaim_token_a ) {
	$stack['task_store']->complete( $reclaim_task['task_id'], $lease_owner_a, $reclaim_token_a, '2026-07-16T12:03:00Z' );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_completion_fence_failed', 'TASK-LEASE-EXPIRED-COMPLETION' );
persist_expect_failure( $wpdb, static function () use ( $stack, $reclaim_task, $lease_owner_a, $reclaim_token_a ) {
	$stack['task_store']->fail_and_reschedule( $reclaim_task['task_id'], $lease_owner_a, $reclaim_token_a, '2026-07-16T12:04:00Z', 'expired_worker', 'expired lease', '2026-07-16T12:03:00Z' );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_failure_fence_failed', 'TASK-LEASE-EXPIRED-RETRY' );
archive_check( $reclaim_before_expiry_checks === $stack['task_store']->find( $reclaim_task['task_id'] ), 'TASK-LEASE-EXPIRED-MUTATIONS leave the expired leased row byte-identical' );
$reclaimed = $stack2['task_store']->claim( $reclaim_task['task_id'], $lease_owner_b, $reclaim_token_b, '2026-07-16T12:03:00Z', '2026-07-16T12:13:00Z' );
archive_check( $reclaimed, 'TASK-LEASE-RECLAIM an expired lease can be reclaimed by one compare-and-set claim' );
persist_expect_failure( $wpdb, static function () use ( $stack, $reclaim_task, $lease_owner_a, $reclaim_token_a ) {
	$stack['task_store']->complete( $reclaim_task['task_id'], $lease_owner_a, $reclaim_token_a, '2026-07-16T12:04:00Z' );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_completion_fence_failed', 'TASK-LEASE-RECLAIM-STALE-TOKEN' );
$stack2['task_store']->complete( $reclaim_task['task_id'], $lease_owner_b, $reclaim_token_b, '2026-07-16T12:05:00Z' );

$sExactExpiry = new GHCA_Persist_Scenario( 'prog-task-exact-expiry' );
persist_request_archive( $stack, $sExactExpiry );
$exact_expiry_task = $wpdb->get_row( $wpdb->prepare(
	'SELECT * FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_tasks' ) . ' WHERE stream_id = %s',
	(string) $sExactExpiry->stream_id
), ARRAY_A );
$exact_token_a = $sExactExpiry->id( 'lease-exact-old' );
$exact_token_b = $sExactExpiry->id( 'lease-exact-new' );
$stack['task_store']->claim( $exact_expiry_task['task_id'], $lease_owner_a, $exact_token_a, '2026-07-16T12:20:00Z', '2026-07-16T12:21:00Z' );
archive_check( $stack2['task_store']->claim( $exact_expiry_task['task_id'], $lease_owner_b, $exact_token_b, '2026-07-16T12:21:00Z', '2026-07-16T12:31:00Z' ), 'TASK-LEASE-EXACT-EXPIRY may be reclaimed when now equals lease expiry' );
$stack2['task_store']->complete( $exact_expiry_task['task_id'], $lease_owner_b, $exact_token_b, '2026-07-16T12:22:00Z' );

$sTaskRetry = new GHCA_Persist_Scenario( 'prog-task-retry' );
persist_request_archive( $stack, $sTaskRetry );
$retry_task = $wpdb->get_row( $wpdb->prepare(
	'SELECT * FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_tasks' ) . ' WHERE stream_id = %s',
	(string) $sTaskRetry->stream_id
), ARRAY_A );
$retry_token = $sTaskRetry->id( 'retry-token-1' );
$stack['task_store']->claim( $retry_task['task_id'], $lease_owner_a, $retry_token, '2026-07-16T13:01:00Z', '2026-07-16T13:11:00Z' );
persist_expect_failure( $wpdb, static function () use ( $stack2, $retry_task, $lease_owner_b, $retry_token ) {
	$stack2['task_store']->fail_and_reschedule( $retry_task['task_id'], $lease_owner_b, $retry_token, '2026-07-16T13:03:00Z', 'retryable', 'try again', '2026-07-16T13:02:00Z' );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_failure_fence_failed', 'TASK-LEASE-WRONG-OWNER-RETRY' );
$stack['task_store']->fail_and_reschedule( $retry_task['task_id'], $lease_owner_a, $retry_token, '2026-07-16T13:03:00Z', 'retryable', 'try again', '2026-07-16T13:02:00Z' );
$retried_task = $stack['task_store']->find( $retry_task['task_id'] );
archive_check( 'pending' === $retried_task['task_state'] && '1' === (string) $retried_task['attempt_count'] && 'retryable' === $retried_task['last_error_code'] && null === $retried_task['lease_token'], 'TASK-LEASE-RETRY the exact live owner/token pair reschedules and clears the lease' );

$retry_rounds = array(
	array( '2026-07-16T13:03:00Z', '2026-07-16T13:13:00Z', '2026-07-16T13:04:00Z', '2026-07-16T13:05:00Z' ),
	array( '2026-07-16T13:05:00Z', '2026-07-16T13:15:00Z', '2026-07-16T13:06:00Z', '2026-07-16T13:07:00Z' ),
	array( '2026-07-16T13:07:00Z', '2026-07-16T13:17:00Z', '2026-07-16T13:08:00Z', '2026-07-16T13:09:00Z' ),
	array( '2026-07-16T13:09:00Z', '2026-07-16T13:19:00Z', '2026-07-16T13:10:00Z', '2026-07-16T13:11:00Z' ),
);
foreach ( $retry_rounds as $index => $round ) {
	$round_token = $sTaskRetry->id( 'retry-token-' . ( $index + 2 ) );
	archive_check( $stack['task_store']->claim( $retry_task['task_id'], $lease_owner_a, $round_token, $round[0], $round[1] ), 'TASK-LEASE-DEAD-LETTER attempt ' . ( $index + 2 ) . ' claims the pending task' );
	$stack['task_store']->fail_and_reschedule( $retry_task['task_id'], $lease_owner_a, $round_token, $round[3], 'retryable', 'try again', $round[2] );
}
$dead_task = $stack['task_store']->find( $retry_task['task_id'] );
archive_check( 'dead' === $dead_task['task_state'] && '5' === (string) $dead_task['attempt_count'] && null === $dead_task['lease_token'], 'TASK-LEASE-DEAD-LETTER the fifth failed attempt becomes dead and clears the lease' );

// ---------------------------------------------------------------------------
// TASK-READ-VALIDATION: retained task rows fail closed at the store boundary.
// ---------------------------------------------------------------------------
$sTaskRead = new GHCA_Persist_Scenario( 'prog-task-read' );
persist_request_archive( $stack, $sTaskRead );
$task_table = $wpdb->prefix . 'ghca_acd_archive_tasks';
$read_task = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$task_table} WHERE stream_id = %s", (string) $sTaskRead->stream_id ), ARRAY_A );

ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$task_table} SET task_schema_version = 99 WHERE task_id = %s", $read_task['task_id'] ), 'tamper task schema version' );
persist_expect_failure( $wpdb, static function () use ( $stack, $read_task ) {
	$stack['task_store']->find( $read_task['task_id'] );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'unsupported_task_schema_version', 'TASK-UNKNOWN-SCHEMA-FAIL-CLOSED' );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$task_table} SET task_schema_version = %d WHERE task_id = %s", GHCA_ACD_WPDB_Archive_Task_Store::TASK_SCHEMA_VERSION, $read_task['task_id'] ), 'restore task schema version' );

ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$task_table} SET payload_json = %s WHERE task_id = %s", '{} ', $read_task['task_id'] ), 'tamper task payload JSON' );
persist_expect_failure( $wpdb, static function () use ( $stack, $read_task ) {
	$stack['task_store']->find( $read_task['task_id'] );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'invalid_stored_task_payload', 'TASK-MALFORMED-PAYLOAD-FAIL-CLOSED' );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$task_table} SET payload_json = %s WHERE task_id = %s", $read_task['payload_json'], $read_task['task_id'] ), 'restore task payload JSON' );

ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$task_table} SET task_type = %s WHERE task_id = %s", 'future_task', $read_task['task_id'] ), 'tamper task type' );
persist_expect_failure( $wpdb, static function () use ( $stack, $read_task ) {
	$stack['task_store']->find( $read_task['task_id'] );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'invalid_stored_task_row', 'TASK-UNKNOWN-TYPE-FAIL-CLOSED' );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$task_table} SET task_type = %s WHERE task_id = %s", $read_task['task_type'], $read_task['task_id'] ), 'restore task type' );

ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$task_table} SET dedupe_digest = %s WHERE task_id = %s", str_repeat( 'f', 64 ), $read_task['task_id'] ), 'tamper task dedupe digest' );
persist_expect_failure( $wpdb, static function () use ( $stack, $read_task ) {
	$stack['task_store']->find( $read_task['task_id'] );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'stored_task_dedupe_mismatch', 'TASK-DEDUPE-FAIL-CLOSED' );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$task_table} SET dedupe_digest = %s WHERE task_id = %s", $read_task['dedupe_digest'], $read_task['task_id'] ), 'restore task dedupe digest' );
archive_check( $stack['task_store']->find( $read_task['task_id'] ) === $read_task, 'TASK-READ-VALIDATION the restored legitimate task row remains readable byte-for-byte' );

$sX     = new GHCA_Persist_Scenario( 'prog-x' );
persist_request_archive( $stack, $sX );
persist_expect_failure( $wpdb, static function () use ( $stack2, $sX ) {
	return persist_start_build( $stack2, $sX, array(), array( 'expected_sequence' => '0', 'expected_head_digest' => null, 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'expected_sequence_conflict', 'PERSIST-EXPECTED-SEQUENCE-CONFLICT' );
persist_expect_failure( $wpdb, static function () use ( $stack2, $sX ) {
	return persist_start_build( $stack2, $sX, array(), array( 'expected_head_digest' => str_repeat( 'f', 64 ), 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'expected_head_digest_conflict', 'PERSIST-HEAD-DIGEST-CONFLICT' );

// ---------------------------------------------------------------------------
// PERSIST-FIRST-STREAM-RACE: two different first commands race on two real
// connections; the loser hits the unique case digest, retries its
// receipt/stream-lock decision cleanly, and resolves deterministically.
// ---------------------------------------------------------------------------
$proxy_db    = new GHCA_Persist_DB_Proxy( $wpdb );
$proxy_stack = ghca_persist_stack( $proxy_db, '2026-07-16T12:10:00Z', 'proxyrace' );
$sRace       = new GHCA_Persist_Scenario( 'prog-race' );
$sRaceWinner = new GHCA_Persist_Scenario( 'prog-race' );
// READ COMMITTED makes this in-process interleaving deterministically exercise
// the duplicate-key branch. The following two-process test independently
// exercises the production REPEATABLE READ deadlock/timeout retry branch.
ghca_persist_query( $wpdb, 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED', 'race isolation' );
$proxy_db->add_hook( 'insert', 'ghca_acd_archive_streams', static function () use ( $stack2, $sRaceWinner ) {
	persist_request_archive( $stack2, $sRaceWinner, array( 'idempotency_key' => 'op-race-winner', 'no_track' => false ) );
} );
$race_caught = null;
try {
	persist_request_archive( $proxy_stack, $sRace, array( 'idempotency_key' => 'op-race-loser', 'no_track' => true ) );
} catch ( Throwable $error ) {
	$race_caught = $error;
}
archive_check( $race_caught instanceof GHCA_ACD_Archive_Persistence_Exception && 'expected_sequence_conflict' === $race_caught->reason_code(), 'PERSIST-FIRST-STREAM-RACE the losing different command is deterministically rejected after the clean retry' );
$race_streams = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_streams' ) . ' WHERE case_key_digest = %s', $sRace->case_key->digest() ) );
archive_check( '1' === (string) $race_streams, 'PERSIST-FIRST-STREAM-RACE exactly one stream exists for the contested case key' );
$sRace->record_response( persist_request_archive( $proxy_stack, $sRace, array( 'idempotency_key' => 'op-race-winner', 'expected_sequence' => '0', 'expected_head_digest' => null, 'no_track' => true ) ) );
archive_check( $sRace->head_sequence === $sRaceWinner->head_sequence && $sRace->head_digest === $sRaceWinner->head_digest, 'PERSIST-FIRST-STREAM-RACE the winner\'s identity replays as the committed outcome' );
$proxy_db->clear_hooks();
ghca_persist_query( $wpdb, 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ', 'restore isolation' );
persist_assert_stream_consistent( $wpdb, $sRaceWinner, 'PERSIST-FIRST-STREAM-RACE' );

// ---------------------------------------------------------------------------
// PERSIST-FIRST-STREAM-RR-RACE: the production default isolation is exercised
// with two actual PHP processes. A deadlock/lock-timeout victim is retried and
// both deliveries of the same command converge on one receipt and response.
// ---------------------------------------------------------------------------
$rr_program = 'prog-rr-race';
$rr_key = 'op-rr-shared';
$rr_signal = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ghca-rr-race-' . getmypid() . '-' . substr( hash( 'sha256', PHP_VERSION ), 0, 8 ) . '.signal';
if ( file_exists( $rr_signal ) ) {
	unlink( $rr_signal );
}
$rr_command = array( PHP_BINARY );
if ( PHP_VERSION_ID < 80000 ) {
	$rr_command[] = '-d';
	$rr_command[] = 'extension_dir=ext';
	$rr_command[] = '-d';
	$rr_command[] = 'extension=mysqli';
}
$rr_command[] = __DIR__ . '/persistence-race-worker.php';
$rr_command[] = $rr_signal;
$rr_command[] = $rr_program;
$rr_command[] = $rr_key;
$rr_descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
$rr_process = proc_open( $rr_command, $rr_descriptors, $rr_pipes, __DIR__ );
if ( ! is_resource( $rr_process ) ) {
	throw new RuntimeException( 'could not start repeatable-read race worker' );
}
fclose( $rr_pipes[0] );
$rr_proxy = new GHCA_Persist_DB_Proxy( $wpdb );
$rr_stack = ghca_persist_stack( $rr_proxy, '2026-07-16T12:15:00Z', 'rr-main' );
$rr_scenario = new GHCA_Persist_Scenario( $rr_program );
$rr_proxy->add_hook( 'insert', 'ghca_acd_archive_streams', static function () use ( $rr_signal ) {
	file_put_contents( $rr_signal, 'go' );
	usleep( 250000 );
} );
$rr_main_response = persist_request_archive( $rr_stack, $rr_scenario, array( 'idempotency_key' => $rr_key, 'no_track' => true ) );
$rr_stdout = stream_get_contents( $rr_pipes[1] );
$rr_stderr = stream_get_contents( $rr_pipes[2] );
fclose( $rr_pipes[1] );
fclose( $rr_pipes[2] );
$rr_exit = proc_close( $rr_process );
$rr_proxy->clear_hooks();
if ( file_exists( $rr_signal ) ) {
	unlink( $rr_signal );
}
$rr_worker_response = null;
if ( preg_match( '/RACE_RESPONSE=([^\r\n]+)/', $rr_stdout, $rr_match ) ) {
	$rr_worker_response = GHCA_ACD_Archive_Canonical_JSON::decode_canonical( base64_decode( $rr_match[1], true ) );
}
$rr_diagnostic = ' [exit=' . $rr_exit . ', stderr=' . trim( preg_replace( '/\s+/', ' ', $rr_stderr ) ) . ', response=' . ( $rr_main_response === $rr_worker_response ? 'match' : 'mismatch' ) . ']';
archive_check( '' === trim( $rr_stderr ) && $rr_main_response === $rr_worker_response, 'PERSIST-FIRST-STREAM-RR-RACE both real processes converge on the byte-identical stored response' . $rr_diagnostic );
$rr_stream = persist_row( $wpdb, 'streams', 'case_key_digest', $rr_scenario->case_key->digest() );
$rr_counts = array(
	'events'   => $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_events' ) . ' WHERE stream_id = %s', $rr_stream['stream_id'] ) ),
	'receipts' => $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_commands' ) . ' WHERE stream_id = %s', $rr_stream['stream_id'] ) ),
	'tasks'    => $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_tasks' ) . ' WHERE stream_id = %s', $rr_stream['stream_id'] ) ),
);
archive_check( '1' === (string) $rr_counts['events'] && '1' === (string) $rr_counts['receipts'] && '1' === (string) $rr_counts['tasks'], 'PERSIST-FIRST-STREAM-RR-RACE commits exactly one event, receipt, and durable task' );

// ---------------------------------------------------------------------------
// PERSIST-RECEIPT-FIRST-RACE: the same command is delivered twice
// concurrently; the loser's pre-check misses, the winner commits on the
// second connection, and the in-transaction recheck returns the stored
// response instead of a false conflict.
// ---------------------------------------------------------------------------
$sRec       = new GHCA_Persist_Scenario( 'prog-recrace' );
$sRecWinner = new GHCA_Persist_Scenario( 'prog-recrace' );
$winner_response = null;
$proxy_db->add_hook( 'query', 'START TRANSACTION', static function () use ( $stack2, $sRecWinner, &$winner_response ) {
	$winner_response = persist_request_archive( $stack2, $sRecWinner, array( 'idempotency_key' => 'op-shared-first', 'no_track' => false ) );
} );
$loser_response = persist_request_archive( $proxy_stack, $sRec, array( 'idempotency_key' => 'op-shared-first', 'no_track' => true ) );
$proxy_db->clear_hooks();
archive_check( is_array( $winner_response ) && $loser_response === $winner_response, 'PERSIST-RECEIPT-FIRST-RACE the in-transaction recheck returns the winner\'s stored response' );
$recrace_events = persist_event_rows( $wpdb, (string) $sRecWinner->stream_id );
archive_check( 1 === count( $recrace_events ), 'PERSIST-RECEIPT-FIRST-RACE only the winner\'s single delivery committed events' );
$recrace_receipts = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_commands' ) . ' WHERE stream_id = %s', (string) $sRecWinner->stream_id ) );
archive_check( '1' === (string) $recrace_receipts, 'PERSIST-RECEIPT-FIRST-RACE exactly one insert-once receipt exists' );

// ---------------------------------------------------------------------------
// PERSIST-PROJECTION-RACE: a second real connection holds the projector-head
// lock; the command times out, rolls back residue-free, then succeeds.
// ---------------------------------------------------------------------------
$sLock = new GHCA_Persist_Scenario( 'prog-lock' );
persist_request_archive( $stack, $sLock );
ghca_persist_query( $wpdb, 'SET SESSION innodb_lock_wait_timeout = 1', 'shorten main lock wait' );
ghca_persist_query( $conn2, 'START TRANSACTION', 'open blocking transaction' );
$conn2->get_results( $conn2->prepare( 'SELECT * FROM ' . ghca_persist_quote_identifier( $conn2->prefix . 'ghca_acd_archive_projection_heads' ) . ' WHERE stream_id = %s FOR UPDATE', (string) $sLock->stream_id ), ARRAY_A );
persist_expect_failure( $wpdb, static function () use ( $stack, $sLock ) {
	return persist_start_build( $stack, $sLock, array(), array( 'idempotency_key' => 'op-lock-blocked', 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'transaction_retryable_conflict', 'PERSIST-PROJECTION-RACE' );
ghca_persist_query( $conn2, 'ROLLBACK', 'release blocking transaction' );
ghca_persist_query( $wpdb, 'SET SESSION innodb_lock_wait_timeout = 50', 'restore main lock wait' );
persist_start_build( $stack, $sLock, array(), array( 'idempotency_key' => 'op-lock-blocked' ) );
persist_assert_stream_consistent( $wpdb, $sLock, 'PERSIST-PROJECTION-RACE retry' );

// ---------------------------------------------------------------------------
// PERSIST-CASE-DIGEST-CONSTITUENT-MISMATCH
// ---------------------------------------------------------------------------
$sColl = new GHCA_Persist_Scenario( 'prog-coll' );
persist_request_archive( $stack, $sColl );
ghca_persist_query( $wpdb, $wpdb->prepare( 'UPDATE ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_streams' ) . ' SET program_key = %s WHERE stream_id = %s', 'prog-collx', (string) $sColl->stream_id ), 'simulate digest collision' );
persist_expect_failure( $wpdb, static function () use ( $stack, $sColl ) {
	return persist_start_build( $stack, $sColl, array(), array( 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'stream_row_invalid', 'PERSIST-CASE-DIGEST-CONSTITUENT-MISMATCH' );
ghca_persist_query( $wpdb, $wpdb->prepare( 'UPDATE ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_streams' ) . ' SET program_key = %s WHERE stream_id = %s', 'prog-coll', (string) $sColl->stream_id ), 'restore constituents' );
$stream_table = ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_streams' );
$stream_identity_tampers = array(
	'cycle-key-digest' => array( 'cycle_key_digest', str_repeat( 'f', 64 ), hash( 'sha256', $sColl->case_canonical['cycle_key'] ) ),
	'cycle-start'      => array( 'cycle_start_gmt', '2026-01-02 05:00:00', '2026-01-01 05:00:00' ),
	'cycle-timezone'   => array( 'cycle_timezone', 'UTC', 'America/Toronto' ),
	'cycle-policy'     => array( 'cycle_policy_key', 'calendar_year|2', 'calendar_year|1' ),
);
foreach ( $stream_identity_tampers as $label => $tamper ) {
	ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$stream_table} SET {$tamper[0]} = %s WHERE stream_id = %s", $tamper[1], (string) $sColl->stream_id ), 'tamper stream ' . $label );
	persist_expect_failure( $wpdb, static function () use ( $stack, $sColl ) {
		return persist_start_build( $stack, $sColl, array(), array( 'no_track' => true ) );
	}, 'GHCA_ACD_Archive_Persistence_Exception', 'stream_row_invalid', 'PERSIST-STREAM-IDENTITY-' . strtoupper( $label ) );
	ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$stream_table} SET {$tamper[0]} = %s WHERE stream_id = %s", $tamper[2], (string) $sColl->stream_id ), 'restore stream ' . $label );
}

// ---------------------------------------------------------------------------
// Projection rows must preserve their full immutable entity identity, not
// merely a sequence/digest cursor.
// ---------------------------------------------------------------------------
$sRevisionIdentity = new GHCA_Persist_Scenario( 'prog-revision-identity' );
persist_request_archive( $stack, $sRevisionIdentity );
$revision_table = ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_revision_state' );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$revision_table} SET stream_id = %s WHERE archive_id = %s", $sRevisionIdentity->id( 'foreign-stream' ), $sRevisionIdentity->id( 'archive-1' ) ), 'tamper revision stream identity' );
persist_expect_failure( $wpdb, static function () use ( $stack, $sRevisionIdentity ) {
	return persist_start_build( $stack, $sRevisionIdentity, array(), array( 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'revision_identity_mismatch', 'PROJECTOR-REVISION-IDENTITY-BINDING' );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$revision_table} SET stream_id = %s WHERE archive_id = %s", $sRevisionIdentity->stream_id, $sRevisionIdentity->id( 'archive-1' ) ), 'restore revision stream identity' );

$sCaseIdentity = new GHCA_Persist_Scenario( 'prog-case-identity' );
persist_request_archive( $stack, $sCaseIdentity );
$case_table = ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_case_state' );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$case_table} SET cycle_timezone = %s WHERE stream_id = %s", 'UTC', $sCaseIdentity->stream_id ), 'tamper case projection identity' );
persist_expect_failure( $wpdb, static function () use ( $stack, $sCaseIdentity ) {
	return persist_start_build( $stack, $sCaseIdentity, array(), array( 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'case_state_identity_mismatch', 'PROJECTOR-CASE-IDENTITY-BINDING' );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$case_table} SET cycle_timezone = %s WHERE stream_id = %s", 'America/Toronto', $sCaseIdentity->stream_id ), 'restore case projection identity' );

$sResetIdentity = new GHCA_Persist_Scenario( 'prog-reset-identity' );
persist_build_finalized( $stack, $sResetIdentity );
persist_request_reset( $stack, $sResetIdentity );
$reset_table = ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_reset_state' );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$reset_table} SET snapshot_id = %s WHERE reset_operation_id = %s", $sResetIdentity->id( 'foreign-snapshot' ), $sResetIdentity->id( 'reset-1' ) ), 'tamper reset snapshot identity' );
$defer_payload = $sResetIdentity->payload( 'ResetDeferred', array( 'reset_operation_id' => $sResetIdentity->id( 'reset-1' ) ) );
persist_expect_failure( $wpdb, static function () use ( $stack, $sResetIdentity, $defer_payload ) {
	return persist_single( $stack, $sResetIdentity, 'DeferReset', 'defer_reset', $defer_payload, array( 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'reset_identity_mismatch', 'PROJECTOR-RESET-IDENTITY-BINDING' );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$reset_table} SET snapshot_id = %s WHERE reset_operation_id = %s", $sResetIdentity->id( 'snapshot-1' ), $sResetIdentity->id( 'reset-1' ) ), 'restore reset snapshot identity' );

$sAuthorizationIdentity = new GHCA_Persist_Scenario( 'prog-authorization-identity' );
persist_build_finalized( $stack, $sAuthorizationIdentity );
persist_request_reset( $stack, $sAuthorizationIdentity );
persist_authorize_reset( $stack, $sAuthorizationIdentity );
$authorization_table = ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_reset_authorizations' );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$authorization_table} SET scope_digest = %s WHERE authorization_id = %s", str_repeat( 'f', 64 ), $sAuthorizationIdentity->id( 'auth-1' ) ), 'tamper authorization scope identity' );
persist_expect_failure( $wpdb, static function () use ( $stack, $sAuthorizationIdentity ) {
	return persist_claim_reset( $stack, $sAuthorizationIdentity );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'authorization_identity_mismatch', 'PROJECTOR-AUTHORIZATION-IDENTITY-BINDING' );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$authorization_table} SET scope_digest = %s WHERE authorization_id = %s", $sAuthorizationIdentity->scope_digest, $sAuthorizationIdentity->id( 'auth-1' ) ), 'restore authorization scope identity' );

// ---------------------------------------------------------------------------
// PERSIST-LOAD-HASH-TAMPER
// ---------------------------------------------------------------------------
$sTam = new GHCA_Persist_Scenario( 'prog-tamper' );
persist_request_archive( $stack, $sTam );
persist_start_build( $stack, $sTam );
$tampered = $wpdb->get_var( $wpdb->prepare( 'SELECT payload_json FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_events' ) . ' WHERE stream_id = %s AND stream_sequence = 2', (string) $sTam->stream_id ) );
ghca_persist_query( $wpdb, $wpdb->prepare( 'UPDATE ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_events' ) . ' SET payload_json = %s WHERE stream_id = %s AND stream_sequence = 2', str_replace( '"capturing"', '"verifying"', (string) $tampered ), (string) $sTam->stream_id ), 'tamper stored payload' );
$tamper_caught = null;
try {
	$stack['event_store']->load_events( (string) $sTam->stream_id );
} catch ( Throwable $error ) {
	$tamper_caught = $error;
}
archive_check( $tamper_caught instanceof GHCA_ACD_Archive_Persistence_Exception && 'event_verification_failed' === $tamper_caught->reason_code(), 'PERSIST-LOAD-HASH-TAMPER a tampered payload fails digest verification on load' );
persist_expect_failure( $wpdb, static function () use ( $stack, $sTam ) {
	return persist_record_snapshot( $stack, $sTam );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'event_verification_failed', 'PERSIST-LOAD-HASH-TAMPER command path' );

// ---------------------------------------------------------------------------
// PERSIST-LOAD-SEQUENCE-GAP
// ---------------------------------------------------------------------------
$sGap = new GHCA_Persist_Scenario( 'prog-gap' );
persist_request_archive( $stack, $sGap );
persist_start_build( $stack, $sGap );
persist_record_snapshot( $stack, $sGap );
ghca_persist_query( $wpdb, $wpdb->prepare( 'DELETE FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_events' ) . ' WHERE stream_id = %s AND stream_sequence = 2', (string) $sGap->stream_id ), 'test-only tamper: remove one stored event' );
$gap_caught = null;
try {
	$stack['event_store']->load_events( (string) $sGap->stream_id );
} catch ( Throwable $error ) {
	$gap_caught = $error;
}
archive_check( $gap_caught instanceof GHCA_ACD_Archive_Persistence_Exception && 'stream_chain_invalid' === $gap_caught->reason_code(), 'PERSIST-LOAD-SEQUENCE-GAP a gapped stream fails chain verification on load' );
persist_expect_failure( $wpdb, static function () use ( $stack, $sGap ) {
	return persist_materialize( $stack, $sGap, 'ledger' );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'stream_chain_invalid', 'PERSIST-LOAD-SEQUENCE-GAP command path' );

// ---------------------------------------------------------------------------
// Failure injection: every write point rolls back everything.
// ---------------------------------------------------------------------------
$fail_db    = new GHCA_Persist_DB_Proxy( $wpdb );
$fail_stack = ghca_persist_stack( $fail_db, '2026-07-16T12:20:00Z', 'failinject' );

$sF1 = new GHCA_Persist_Scenario( 'prog-fail1' );
$fail_db->add_hook( 'insert', 'ghca_acd_archive_events', 'fail' );
persist_expect_failure( $wpdb, static function () use ( $fail_stack, $sF1 ) {
	return persist_request_archive( $fail_stack, $sF1, array( 'idempotency_key' => 'op-f1', 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'event_insert_failed', 'PERSIST-EVENT-INSERT-ROLLBACK' );
$fail_db->clear_hooks();
persist_request_archive( $fail_stack, $sF1, array( 'idempotency_key' => 'op-f1' ) );
persist_assert_stream_consistent( $wpdb, $sF1, 'PERSIST-EVENT-INSERT-ROLLBACK clean retry' );

$sF2 = new GHCA_Persist_Scenario( 'prog-fail2' );
$fail_db->add_hook( 'query', 'projection_heads SET projected_sequence', 'fail' );
persist_expect_failure( $wpdb, static function () use ( $fail_stack, $sF2 ) {
	return persist_request_archive( $fail_stack, $sF2, array( 'idempotency_key' => 'op-f2', 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'projector_head_update_failed', 'PERSIST-PROJECTION-ROLLBACK' );
$fail_db->clear_hooks();
persist_request_archive( $fail_stack, $sF2, array( 'idempotency_key' => 'op-f2' ) );
persist_assert_stream_consistent( $wpdb, $sF2, 'PERSIST-PROJECTION-ROLLBACK clean retry' );

$sTaskFail = new GHCA_Persist_Scenario( 'prog-task-fail' );
$fail_db->add_hook( 'insert', 'ghca_acd_archive_tasks', 'fail' );
persist_expect_failure( $wpdb, static function () use ( $fail_stack, $sTaskFail ) {
	return persist_request_archive( $fail_stack, $sTaskFail, array( 'idempotency_key' => 'op-task-fail', 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_insert_failed', 'PERSIST-TASK-INSERT-ROLLBACK' );
$fail_db->clear_hooks();
persist_request_archive( $fail_stack, $sTaskFail, array( 'idempotency_key' => 'op-task-fail' ) );
archive_check( 1 === (int) $wpdb->get_var( $wpdb->prepare(
	'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_tasks' ) . ' WHERE stream_id = %s',
	(string) $sTaskFail->stream_id
) ), 'PERSIST-TASK-INSERT-ROLLBACK clean retry inserts exactly one durable task' );

$sF3 = new GHCA_Persist_Scenario( 'prog-fail3' );
$fail_db->add_hook( 'query', 'streams SET head_sequence', 'fail' );
persist_expect_failure( $wpdb, static function () use ( $fail_stack, $sF3 ) {
	return persist_request_archive( $fail_stack, $sF3, array( 'idempotency_key' => 'op-f3', 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'stream_head_update_failed', 'PERSIST-STREAM-HEAD-ROLLBACK' );
$fail_db->clear_hooks();
persist_request_archive( $fail_stack, $sF3, array( 'idempotency_key' => 'op-f3' ) );
persist_assert_stream_consistent( $wpdb, $sF3, 'PERSIST-STREAM-HEAD-ROLLBACK clean retry' );

$sF4 = new GHCA_Persist_Scenario( 'prog-fail4' );
$fail_db->add_hook( 'insert', 'ghca_acd_archive_commands', 'fail' );
persist_expect_failure( $wpdb, static function () use ( $fail_stack, $sF4 ) {
	return persist_request_archive( $fail_stack, $sF4, array( 'idempotency_key' => 'op-f4', 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'receipt_insert_failed', 'PERSIST-RECEIPT-ROLLBACK' );
$fail_db->clear_hooks();
persist_request_archive( $fail_stack, $sF4, array( 'idempotency_key' => 'op-f4' ) );
persist_assert_stream_consistent( $wpdb, $sF4, 'PERSIST-RECEIPT-ROLLBACK clean retry' );

// ---------------------------------------------------------------------------
// Domain rejection through the unit of work: exact exception, zero residue.
// ---------------------------------------------------------------------------
persist_expect_failure( $wpdb, static function () use ( $stack, $sF4 ) {
	$payload = $sF4->payload( 'ArchiveRequested', array( 'archive_id' => $sF4->id( 'archive-dup' ) ) );
	return persist_single( $stack, $sF4, 'RequestArchive', 'request_archive', $payload, array( 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Transition_Exception', 'active_build_exists', 'PERSIST-DOMAIN-REJECTION' );

// ---------------------------------------------------------------------------
// Direct projector contract tests on a dedicated stream.
// ---------------------------------------------------------------------------
$sP = new GHCA_Persist_Scenario( 'prog-proj' );
persist_request_archive( $stack, $sP );
$streamP = persist_row( $wpdb, 'streams', 'stream_id', (string) $sP->stream_id );
$priorP  = $stack['event_store']->load_events( (string) $sP->stream_id );

/**
 * Record uncommitted aggregate events for direct projector tests, mirroring
 * the unit-of-work envelope assignment.
 *
 * @param array<int,GHCA_ACD_Archive_Event> $events
 * @return array<int,GHCA_ACD_Archive_Event>
 */
function persist_record_for_test( GHCA_Persist_Scenario $s, array $events, string $start_sequence, ?string $chain_digest, string $slot ) {
	$actor    = $s->actor->canonical();
	$sequence = $start_sequence;
	$recorded = array();
	$counter  = 0;
	foreach ( $events as $event ) {
		$counter++;
		$sequence = (string) ( (int) $sequence + 1 );
		$payload  = $event->payload();
		$archive_id = null;
		foreach ( array( 'archive_id', 'target_archive_id', 'bound_archive_id' ) as $field ) {
			if ( array_key_exists( $field, $payload ) ) {
				$archive_id = $payload[ $field ];
				break;
			}
		}
		$attempt = array_key_exists( 'build_attempt_id', $payload ) ? $payload['build_attempt_id'] : null;
		if ( 'ArchiveRetryRequested' === $event->type() ) {
			$attempt = $payload['new_build_attempt_id'];
		}
		$recorded_event = $event->with_recording_context( array(
			'canonical_format_version' => 1,
			'event_id'                 => $s->id( 'test-event-' . $slot . '-' . $counter ),
			'stream_id'                => (string) $s->stream_id,
			'case_key_digest'          => $s->case_key->digest(),
			'case_key_format_version'  => 1,
			'stream_sequence'          => $sequence,
			'archive_id'               => $archive_id,
			'build_attempt_id'         => $attempt,
			'reset_operation_id'       => array_key_exists( 'reset_operation_id', $payload ) ? $payload['reset_operation_id'] : null,
			'actor_kind'               => $actor['actor_kind'],
			'actor_user_id'            => $actor['actor_user_id'],
			'initiating_user_id'       => $actor['initiating_user_id'],
			'source_channel'           => $actor['source_channel'],
			'authority_code'           => $actor['authority_code'],
			'authority_context'        => $actor['authority_context'],
			'occurred_at_gmt'          => '2026-07-16T12:30:00Z',
			'effective_at_gmt'         => null,
			'correlation_id'           => $s->id( 'test-corr-' . $slot ),
			'causation_event_id'       => null,
			'command_id'               => $s->id( 'test-cmd-' . $slot ),
			'upstream_operation_id'    => array_key_exists( 'upstream_operation_id', $payload ) ? $payload['upstream_operation_id'] : null,
			'idempotency_scope_digest' => str_repeat( '1', 64 ),
			'idempotency_key_digest'   => str_repeat( '2', 64 ),
			'command_digest'           => str_repeat( '3', 64 ),
			'reason_code'              => null,
			'reason_text'              => null,
			'previous_event_digest'    => $chain_digest,
			'recorded_at_gmt'          => '2026-07-16T12:30:00Z',
		) );
		$recorded[]   = $recorded_event;
		$chain_digest = $recorded_event->event_digest();
	}
	return $recorded;
}

// PROJECTOR-EXACT-NEXT: the exact next recorded event applies inside a
// transaction we deliberately roll back.
$aggP = GHCA_ACD_Archive_Case::rehydrate( $priorP );
$aggP->start_build( $sP->payload( 'ArchiveBuildStarted', array(
	'archive_id'       => $sP->id( 'archive-1' ),
	'build_attempt_id' => $sP->id( 'attempt-1' ),
) ) );
$nextP = persist_record_for_test( $sP, $aggP->uncommitted_events(), '1', (string) $streamP['head_event_digest'], 'exact' );
ghca_persist_query( $wpdb, 'START TRANSACTION', 'open projector test transaction' );
$stack['projector']->apply_new_events( $streamP, $priorP, $nextP, '2026-07-16T12:30:00Z' );
$head_in_txn = $wpdb->get_var( $wpdb->prepare( 'SELECT projected_sequence FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_projection_heads' ) . " WHERE stream_id = %s AND projector_key = 'revision_state'", (string) $sP->stream_id ) );
ghca_persist_query( $wpdb, 'ROLLBACK', 'discard projector test transaction' );
archive_check( '2' === (string) $head_in_txn, 'PROJECTOR-EXACT-NEXT the exact next sequence applies and advances every head' );
$head_after = $wpdb->get_var( $wpdb->prepare( 'SELECT projected_sequence FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_projection_heads' ) . " WHERE stream_id = %s AND projector_key = 'revision_state'", (string) $sP->stream_id ) );
archive_check( '1' === (string) $head_after, 'PROJECTOR-EXACT-NEXT the projection advance participates in the surrounding transaction' );

// PROJECTOR-GAP-REJECTED: sequence head+2 fails closed with zero residue.
$agg_gap = GHCA_ACD_Archive_Case::rehydrate( $priorP );
$agg_gap->start_build( $sP->payload( 'ArchiveBuildStarted', array(
	'archive_id'       => $sP->id( 'archive-1' ),
	'build_attempt_id' => $sP->id( 'attempt-1' ),
) ) );
$gap_events = persist_record_for_test( $sP, $agg_gap->uncommitted_events(), '2', str_repeat( 'a', 64 ), 'gap' );
persist_expect_failure( $wpdb, static function () use ( $stack, $streamP, $priorP, $gap_events ) {
	$stack['projector']->apply_new_events( $streamP, $priorP, $gap_events, '2026-07-16T12:30:00Z' );
	return null;
}, 'GHCA_ACD_Archive_Persistence_Exception', 'projector_gap', 'PROJECTOR-GAP-REJECTED' );

// PROJECTOR-IDEMPOTENT-REPLAY: redelivering the already-applied batch is a
// safe no-op.
$replay_fingerprint = ghca_persist_db_fingerprint( $wpdb );
$stack['projector']->apply_new_events( $streamP, $priorP, array( $priorP[0] ), '2026-07-16T12:30:00Z' );
archive_check( $replay_fingerprint === ghca_persist_db_fingerprint( $wpdb ), 'PROJECTOR-IDEMPOTENT-REPLAY an identical already-applied event changes nothing' );

// PROJECTOR-CONFLICTING-DUPLICATE: a different event at an already-projected
// sequence fails closed.
$agg_dup = new GHCA_ACD_Archive_Case();
$agg_dup->request_archive( $sP->payload( 'ArchiveRequested', array( 'archive_id' => $sP->id( 'archive-1' ) ) ) );
$dup_events = persist_record_for_test( $sP, $agg_dup->uncommitted_events(), '0', null, 'dup' );
persist_expect_failure( $wpdb, static function () use ( $stack, $streamP, $priorP, $dup_events ) {
	$stack['projector']->apply_new_events( $streamP, $priorP, $dup_events, '2026-07-16T12:30:00Z' );
	return null;
}, 'GHCA_ACD_Archive_Persistence_Exception', 'projector_conflicting_duplicate', 'PROJECTOR-CONFLICTING-DUPLICATE' );

// ---------------------------------------------------------------------------
// PROJECTOR-ALL-EVENT-TYPES: every approved event type flows through either
// the scoped Unit of Work or the explicit later-slice component harness.
// ---------------------------------------------------------------------------

// Scenario C: correction with pre-claim invalidation, then replacement.
$sC = new GHCA_Persist_Scenario( 'prog-c' );
persist_build_finalized( $stack, $sC );
persist_request_reset( $stack, $sC );
persist_request_correction( $stack, $sC, array( 'reset-1|' ) );
$resetC = persist_row( $wpdb, 'reset_state', 'reset_operation_id', $sC->id( 'reset-1' ) );
archive_check( null !== $resetC && 'INVALIDATED' === $resetC['reset_state'] && null !== $resetC['invalidated_at_gmt'], 'ALL-EVENT-TYPES correction atomically invalidates the pre-claim reset' );
$revC1 = persist_row( $wpdb, 'revision_state', 'archive_id', $sC->id( 'archive-1' ) );
archive_check( null !== $revC1 && 'REVOKED' === $revC1['validity_state'] && null !== $revC1['revoked_at_gmt'], 'ALL-EVENT-TYPES the corrected archive is irreversibly revoked' );
persist_request_replacement( $stack, $sC );
persist_start_build( $stack, $sC, array( 'archive_id' => $sC->id( 'archive-2' ), 'build_attempt_id' => $sC->id( 'attempt-2' ) ) );
persist_record_snapshot( $stack, $sC, array(
	'archive_id'      => $sC->id( 'archive-2' ),
	'snapshot_id'     => $sC->id( 'snapshot-2' ),
	'revision_number' => 2,
) );
persist_materialize( $stack, $sC, 'ledger', array(
	'archive_id'         => $sC->id( 'archive-2' ),
	'snapshot_id'        => $sC->id( 'snapshot-2' ),
	'build_attempt_id'   => $sC->id( 'attempt-2' ),
	'ledger_artifact_id' => $sC->id( 'ledger-2' ),
) );
persist_materialize( $stack, $sC, 'packet', array(
	'archive_id'         => $sC->id( 'archive-2' ),
	'snapshot_id'        => $sC->id( 'snapshot-2' ),
	'build_attempt_id'   => $sC->id( 'attempt-2' ),
	'packet_artifact_id' => $sC->id( 'packet-2' ),
) );
persist_verify_finalize( $stack, $sC, $sC->id( 'archive-1' ), '2' );
$revC1 = persist_row( $wpdb, 'revision_state', 'archive_id', $sC->id( 'archive-1' ) );
$revC2 = persist_row( $wpdb, 'revision_state', 'archive_id', $sC->id( 'archive-2' ) );
$caseC = persist_row( $wpdb, 'case_state', 'stream_id', (string) $sC->stream_id );
archive_check( null !== $revC1 && 'SUPERSEDED' === $revC1['validity_state'] && $sC->id( 'archive-2' ) === $revC1['superseded_by_archive_id'], 'ALL-EVENT-TYPES replacement finalization atomically supersedes the named predecessor' );
archive_check( null !== $revC2 && 'ACTIVE' === $revC2['validity_state'] && $sC->id( 'archive-1' ) === $revC2['supersedes_archive_id'] && null !== $caseC && $sC->id( 'archive-2' ) === $caseC['active_archive_id'], 'ALL-EVENT-TYPES the replacement becomes the sole active revision with explicit lineage' );
persist_assert_stream_consistent( $wpdb, $sC, 'ALL-EVENT-TYPES scenario C' );

// Scenario D: deferred/rejected, cancelled, and expired reset operations.
$sD = new GHCA_Persist_Scenario( 'prog-d' );
persist_build_finalized( $stack, $sD );
persist_request_reset( $stack, $sD, 'reset-1' );
persist_single( $stack, $sD, 'DeferReset', 'defer_reset', $sD->payload( 'ResetDeferred', array( 'reset_operation_id' => $sD->id( 'reset-1' ) ) ) );
persist_single( $stack, $sD, 'RejectReset', 'reject_reset', $sD->payload( 'ResetRejected', array( 'reset_operation_id' => $sD->id( 'reset-1' ) ) ) );
persist_request_reset( $stack, $sD, 'reset-2' );
persist_single( $stack, $sD, 'CancelReset', 'cancel_reset', $sD->payload( 'ResetCancelled', array( 'reset_operation_id' => $sD->id( 'reset-2' ), 'authorization_id' => null ) ) );
persist_request_reset( $stack, $sD, 'reset-3' );
persist_authorize_reset( $stack, $sD, 'reset-3', 'auth-3' );
persist_single( $stack, $sD, 'ExpireResetAuthorization', 'expire_reset_authorization', $sD->payload( 'ResetAuthorizationExpired', array( 'reset_operation_id' => $sD->id( 'reset-3' ), 'authorization_id' => $sD->id( 'auth-3' ) ) ) );
$rows = array(
	'reset-1' => 'REJECTED',
	'reset-2' => 'CANCELLED',
	'reset-3' => 'EXPIRED',
);
foreach ( $rows as $slot => $expected_state ) {
	$row = persist_row( $wpdb, 'reset_state', 'reset_operation_id', $sD->id( $slot ) );
	archive_check( null !== $row && $expected_state === $row['reset_state'], "ALL-EVENT-TYPES scenario D reset {$slot} reaches {$expected_state}" );
}
$authD = persist_row( $wpdb, 'reset_authorizations', 'authorization_id', $sD->id( 'auth-3' ) );
archive_check( null !== $authD && 'expired' === $authD['auth_state'] && null !== $authD['closed_at_gmt'] && null !== $authD['terminal_event_id'], 'ALL-EVENT-TYPES scenario D the unused authorization closes as expired' );
persist_assert_stream_consistent( $wpdb, $sD, 'ALL-EVENT-TYPES scenario D' );

// Scenarios E-H: claimed reset outcomes and reconciliations.
$sE = new GHCA_Persist_Scenario( 'prog-e' );
persist_build_finalized( $stack, $sE );
persist_request_reset( $stack, $sE );
persist_authorize_reset( $stack, $sE );
persist_claim_reset( $stack, $sE );
persist_single( $stack, $sE, 'RecordResetOutcomeUncertain', 'record_reset_outcome_uncertain', $sE->payload( 'ResetOutcomeBecameUncertain', array( 'reset_operation_id' => $sE->id( 'reset-1' ) ) ) );
persist_single( $stack, $sE, 'ReconcileResetAsCompleted', 'reconcile_reset_as_completed', $sE->payload( 'ResetReconciledAsCompleted', array( 'reset_operation_id' => $sE->id( 'reset-1' ) ) ) );
$resetE = persist_row( $wpdb, 'reset_state', 'reset_operation_id', $sE->id( 'reset-1' ) );
archive_check( null !== $resetE && 'COMPLETED' === $resetE['reset_state'] && 'reconciled_completed' === $resetE['reconciliation_code'], 'ALL-EVENT-TYPES scenario E uncertain reset reconciles as completed' );

$sF = new GHCA_Persist_Scenario( 'prog-f' );
persist_build_finalized( $stack, $sF );
persist_request_reset( $stack, $sF );
persist_authorize_reset( $stack, $sF );
persist_claim_reset( $stack, $sF );
persist_single( $stack, $sF, 'RecordResetFailedSafe', 'record_reset_failed_safe', $sF->payload( 'ResetFailedSafe', array( 'reset_operation_id' => $sF->id( 'reset-1' ) ) ) );
$resetF = persist_row( $wpdb, 'reset_state', 'reset_operation_id', $sF->id( 'reset-1' ) );
$caseF  = persist_row( $wpdb, 'case_state', 'stream_id', (string) $sF->stream_id );
archive_check( null !== $resetF && 'FAILED_SAFE' === $resetF['reset_state'] && null !== $caseF && '1' === (string) $caseF['reset_eligible'], 'ALL-EVENT-TYPES scenario F a safely failed reset restores derived eligibility' );

$sG = new GHCA_Persist_Scenario( 'prog-g' );
persist_build_finalized( $stack, $sG );
persist_request_reset( $stack, $sG );
persist_authorize_reset( $stack, $sG );
persist_claim_reset( $stack, $sG );
persist_single( $stack, $sG, 'RecordResetOutcomeUncertain', 'record_reset_outcome_uncertain', $sG->payload( 'ResetOutcomeBecameUncertain', array( 'reset_operation_id' => $sG->id( 'reset-1' ) ) ) );
persist_single( $stack, $sG, 'ReconcileResetAsNoChange', 'reconcile_reset_as_no_change', $sG->payload( 'ResetReconciledAsNoChange', array( 'reset_operation_id' => $sG->id( 'reset-1' ) ) ) );
$resetG = persist_row( $wpdb, 'reset_state', 'reset_operation_id', $sG->id( 'reset-1' ) );
archive_check( null !== $resetG && 'FAILED_SAFE' === $resetG['reset_state'] && 'reconciled_no_change' === $resetG['reconciliation_code'], 'ALL-EVENT-TYPES scenario G uncertain reset reconciles as no change' );

$sH = new GHCA_Persist_Scenario( 'prog-h' );
persist_build_finalized( $stack, $sH );
persist_request_reset( $stack, $sH );
persist_authorize_reset( $stack, $sH );
persist_claim_reset( $stack, $sH );
persist_single( $stack, $sH, 'RecordResetOutcomeUncertain', 'record_reset_outcome_uncertain', $sH->payload( 'ResetOutcomeBecameUncertain', array( 'reset_operation_id' => $sH->id( 'reset-1' ) ) ) );
persist_single( $stack, $sH, 'RequireResetRemediation', 'require_reset_remediation', $sH->payload( 'ResetRemediationRequired', array( 'reset_operation_id' => $sH->id( 'reset-1' ), 'remediation_case_id' => $sH->id( 'remcase' ) ) ) );
persist_single( $stack, $sH, 'RecordResetRemediatedRestored', 'record_reset_remediated_restored', $sH->payload( 'ResetRemediatedRestored', array( 'reset_operation_id' => $sH->id( 'reset-1' ), 'remediation_case_id' => $sH->id( 'remcase' ), 'partial_effect_reference_id' => $sH->id( 'partial' ) ) ) );
$resetH = persist_row( $wpdb, 'reset_state', 'reset_operation_id', $sH->id( 'reset-1' ) );
archive_check( null !== $resetH && 'REMEDIATED_RESTORED' === $resetH['reset_state'] && 'remediated_restored' === $resetH['reconciliation_code'], 'ALL-EVENT-TYPES scenario H remediated restoration is never mislabeled failed-safe' );

// Scenario I: pre-capture drift with mandatory candidate failure, restored.
$sI = new GHCA_Persist_Scenario( 'prog-i' );
persist_request_archive( $stack, $sI );
persist_start_build( $stack, $sI );
persist_detect_drift( $stack, $sI );
$caseI = persist_row( $wpdb, 'case_state', 'stream_id', (string) $sI->stream_id );
archive_check( null !== $caseI && 'OPEN' === $caseI['source_drift_state'] && $sI->id( 'drift-1' ) === $caseI['source_drift_incident_id'] && 'source_drift' === $caseI['last_failure_code'] && 'FAILED' === $caseI['build_state'], 'ALL-EVENT-TYPES scenario I drift opens the incident and fails the candidate atomically' );
persist_single( $stack, $sI, 'ResolveSourceDriftRestored', 'resolve_source_drift_restored', $sI->payload( 'SourceDriftResolved', array( 'incident_id' => $sI->id( 'drift-1' ) ) ) );
$caseI = persist_row( $wpdb, 'case_state', 'stream_id', (string) $sI->stream_id );
archive_check( null !== $caseI && 'RESOLVED' === $caseI['source_drift_state'], 'ALL-EVENT-TYPES scenario I verified restoration resolves the drift incident' );
persist_assert_stream_consistent( $wpdb, $sI, 'ALL-EVENT-TYPES scenario I' );

// Scenario J: replacement-rebase drift recovery in one atomic decision.
$sJ = new GHCA_Persist_Scenario( 'prog-j' );
persist_request_archive( $stack, $sJ );
persist_start_build( $stack, $sJ );
persist_detect_drift( $stack, $sJ );
persist_rebase_drift( $stack, $sJ );
$caseJ = persist_row( $wpdb, 'case_state', 'stream_id', (string) $sJ->stream_id );
$revJ1 = persist_row( $wpdb, 'revision_state', 'archive_id', $sJ->id( 'archive-1' ) );
$revJ2 = persist_row( $wpdb, 'revision_state', 'archive_id', $sJ->id( 'archive-2' ) );
archive_check( null !== $caseJ && 'RESOLVED' === $caseJ['source_drift_state'] && null !== $revJ1 && 'CANCELLED' === $revJ1['build_state'] && null !== $revJ2 && 'REQUESTED' === $revJ2['build_state'] && $sJ->id( 'archive-2' ) === $caseJ['current_archive_id'], 'ALL-EVENT-TYPES scenario J rebase resolves drift, cancels the candidate, and accepts the new request atomically' );
persist_assert_stream_consistent( $wpdb, $sJ, 'ALL-EVENT-TYPES scenario J' );

// Scenario K: unprotected reset detected then dismissed.
$sK = new GHCA_Persist_Scenario( 'prog-k' );
persist_request_archive( $stack, $sK );
persist_detect_unprotected( $stack, $sK );
$caseK = persist_row( $wpdb, 'case_state', 'stream_id', (string) $sK->stream_id );
archive_check( null !== $caseK && 'OPEN' === $caseK['unprotected_reset_state'] && '1' === (string) $caseK['edit_locked'] && 'unprotected_reset_open' === $caseK['edit_lock_reason'], 'ALL-EVENT-TYPES scenario K an open unprotected-reset incident fails closed' );
persist_single( $stack, $sK, 'DismissUnprotectedReset', 'dismiss_unprotected_reset', $sK->payload( 'UnprotectedResetDismissed', array( 'incident_id' => $sK->id( 'unprotected-1' ) ) ) );
$caseK = persist_row( $wpdb, 'case_state', 'stream_id', (string) $sK->stream_id );
archive_check( null !== $caseK && 'DISMISSED_NO_RESET' === $caseK['unprotected_reset_state'], 'ALL-EVENT-TYPES scenario K dismissal preserves the visible incident history' );

// Scenario L: unprotected reset confirmed.
$sL = new GHCA_Persist_Scenario( 'prog-l' );
persist_request_archive( $stack, $sL );
persist_detect_unprotected( $stack, $sL );
persist_single( $stack, $sL, 'ConfirmUnprotectedReset', 'confirm_unprotected_reset', $sL->payload( 'UnprotectedResetConfirmed', array( 'incident_id' => $sL->id( 'unprotected-1' ) ) ) );
$caseL = persist_row( $wpdb, 'case_state', 'stream_id', (string) $sL->stream_id );
archive_check( null !== $caseL && 'CONFIRMED_RESET' === $caseL['unprotected_reset_state'] && '0' === (string) $caseL['reset_eligible'] && 'destructive_reset_recorded' === $caseL['reset_block_reason'], 'ALL-EVENT-TYPES scenario L a confirmed out-of-band reset permanently blocks reset' );

// Scenario M: integrity violation and disposition.
$sM = new GHCA_Persist_Scenario( 'prog-m' );
persist_request_archive( $stack, $sM );
persist_detect_integrity( $stack, $sM );
$caseM = persist_row( $wpdb, 'case_state', 'stream_id', (string) $sM->stream_id );
archive_check( null !== $caseM && 'OPEN' === $caseM['integrity_state'] && 'integrity_blocked' === $caseM['edit_lock_reason'], 'ALL-EVENT-TYPES scenario M an open integrity incident fails closed' );
persist_single( $stack, $sM, 'RecordIntegrityDisposition', 'record_integrity_disposition', $sM->payload( 'IntegrityIncidentDispositionRecorded', array( 'incident_id' => $sM->id( 'integrity-1' ) ) ) );
$caseM = persist_row( $wpdb, 'case_state', 'stream_id', (string) $sM->stream_id );
archive_check( null !== $caseM && 'DISPOSITION_RECORDED' === $caseM['integrity_state'], 'ALL-EVENT-TYPES scenario M the disposition is recorded without erasing incident history' );
persist_assert_stream_consistent( $wpdb, $sM, 'ALL-EVENT-TYPES scenario M' );

// Coverage: every approved event type was committed and projected.
$seen_types = $wpdb->get_col( 'SELECT DISTINCT event_type FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_events' ) );
$missing    = array_diff( GHCA_ACD_Archive_Event_Types::all(), (array) $seen_types );
archive_check( array() === $missing && 35 === count( GHCA_ACD_Archive_Event_Types::all() ), 'PROJECTOR-ALL-EVENT-TYPES all 35 approved event types were persisted and synchronously projected [missing: ' . implode( ',', $missing ) . ']' );

// ---------------------------------------------------------------------------
// PERSIST-REHYDRATION-INTEGRITY: a stored stream whose digests verify but
// whose replay is transition-illegal is an integrity failure of the
// authoritative record, never a caller-facing domain rejection.
// ---------------------------------------------------------------------------
$sRehyd = new GHCA_Persist_Scenario( 'prog-rehyd' );
// Built directly (not through the aggregate, which would reject it): a
// schema-valid ArchiveBuildStarted as the first event of a stream is
// transition-illegal on replay while every digest still verifies.
$illegal_events = array( new GHCA_ACD_Archive_Event(
	'ArchiveBuildStarted',
	1,
	$sRehyd->payload( 'ArchiveBuildStarted', array(
		'archive_id'       => $sRehyd->id( 'archive-1' ),
		'build_attempt_id' => $sRehyd->id( 'attempt-1' ),
	) ),
	array( 'decision_index' => 0, 'decision_size' => 1 )
) );
ghca_persist_query( $wpdb, 'START TRANSACTION', 'seed illegal stream' );
$illegal_stream = $stack['event_store']->create_stream( array(
	'stream_id'        => $sRehyd->id( 'stream' ),
	'case_key_digest'  => $sRehyd->case_key->digest(),
	'tenant_id'        => $sRehyd->case_canonical['tenant_id'],
	'site_id'          => $sRehyd->case_canonical['site_id_decimal'],
	'employee_user_id' => $sRehyd->case_canonical['employee_user_id_decimal'],
	'program_key'      => $sRehyd->program,
	'cycle_key'        => $sRehyd->case_canonical['cycle_key'],
	'cycle_key_digest' => hash( 'sha256', $sRehyd->case_canonical['cycle_key'] ),
	'cycle_start_gmt'  => '2026-01-01T05:00:00Z',
	'cycle_end_gmt'    => '2027-01-01T05:00:00Z',
	'cycle_timezone'   => 'America/Toronto',
	'cycle_policy_key' => 'calendar_year|1',
), '2026-07-16T12:50:00Z' );
$sRehyd->stream_id = $sRehyd->id( 'stream' );
$illegal_recorded  = persist_record_for_test( $sRehyd, $illegal_events, '0', null, 'rehyd' );
$stack['event_store']->append_events( $illegal_recorded );
$stack['event_store']->advance_stream_head( $sRehyd->id( 'stream' ), '0', null, '1', $illegal_recorded[0]->event_digest(), '2026-07-16T12:50:00Z' );
ghca_persist_query( $wpdb, 'COMMIT', 'commit illegal stream seed' );
$sRehyd->head_sequence = '1';
$sRehyd->head_digest   = $illegal_recorded[0]->event_digest();
persist_expect_failure( $wpdb, static function () use ( $stack, $sRehyd ) {
	return persist_start_build( $stack, $sRehyd, array(), array( 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'rehydration_failed', 'PERSIST-REHYDRATION-INTEGRITY' );

// ---------------------------------------------------------------------------
// PERSIST-APPEND-ONLY
// ---------------------------------------------------------------------------
$store_methods = get_class_methods( 'GHCA_ACD_WPDB_Archive_Event_Store' );
sort( $store_methods, SORT_STRING );
archive_check( array( '__construct', 'advance_stream_head', 'append_events', 'create_stream', 'database', 'find_stream_for_update', 'load_events', 'stream_identity_matches' ) === $store_methods, 'PERSIST-APPEND-ONLY the event store exposes no update or delete surface for events' );
$mutation_hits = array();
$archive_dir   = dirname( __DIR__, 2 ) . '/includes/archive';
$iterator      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $archive_dir ) );
foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || substr( $file->getFilename(), -4 ) !== '.php' ) {
		continue;
	}
	$contents = file_get_contents( $file->getPathname() );
	if ( preg_match( '/\b(UPDATE|DELETE)\b[^;]{0,200}archive_events/is', $contents ) ) {
		$mutation_hits[] = $file->getFilename();
	}
}
archive_check( array() === $mutation_hits, 'PERSIST-APPEND-ONLY no production code contains event UPDATE or DELETE SQL [' . implode( ',', $mutation_hits ) . ']' );
$events_before_rows = array();
foreach ( persist_event_rows( $wpdb ) as $row ) {
	$events_before_rows[ $row['event_id'] ] = json_encode( $row );
}
$sAppend = new GHCA_Persist_Scenario( 'prog-append' );
persist_request_archive( $stack, $sAppend );
persist_start_build( $stack, $sAppend );
$events_after_rows = array();
foreach ( persist_event_rows( $wpdb ) as $row ) {
	$events_after_rows[ $row['event_id'] ] = json_encode( $row );
}
$immutable = count( $events_after_rows ) === count( $events_before_rows ) + 2;
foreach ( $events_before_rows as $event_id => $bytes ) {
	$immutable = $immutable && isset( $events_after_rows[ $event_id ] ) && $events_after_rows[ $event_id ] === $bytes;
}
archive_check( $immutable, 'PERSIST-APPEND-ONLY committed event rows remain byte-identical while the log grows' );

// ---------------------------------------------------------------------------
// PERSIST-NO-RUNTIME-WIRING
// ---------------------------------------------------------------------------
$entrypoint = file_get_contents( dirname( __DIR__, 2 ) . '/gridhouse-admin-compliance-dashboard.php' );
archive_check( false === stripos( $entrypoint, 'archive' ), 'PERSIST-NO-RUNTIME-WIRING the plugin entrypoint contains zero archive references' );
$wiring_hits = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $archive_dir ) ) as $file ) {
	if ( ! $file->isFile() || substr( $file->getFilename(), -4 ) !== '.php' ) {
		continue;
	}
	$contents = file_get_contents( $file->getPathname() );
	if ( preg_match( '/\b(add_action|add_filter|register_activation_hook|register_deactivation_hook|wp_schedule_event|set_transient)\s*\(/', $contents ) ) {
		$wiring_hits[] = $file->getFilename();
	}
	if ( preg_match( '/wp-load\.php|wp-config\.php/', $contents ) ) {
		$wiring_hits[] = $file->getFilename() . ':bootstrap';
	}
}
archive_check( array() === $wiring_hits, 'PERSIST-NO-RUNTIME-WIRING the archive module registers no hooks, cron, transients, or site bootstrap [' . implode( ',', $wiring_hits ) . ']' );
$flag_rows = $wpdb->get_results( 'SELECT option_name, option_value FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'options' ) . " WHERE option_name IN ('ghca_acd_archive_enabled','ghca_acd_archive_dual_layer')", ARRAY_A );
$flags_off = true;
foreach ( (array) $flag_rows as $row ) {
	$flags_off = $flags_off && '0' === $row['option_value'];
}
archive_check( $flags_off, 'PERSIST-NO-RUNTIME-WIRING both feature flags remain off' );

// ---------------------------------------------------------------------------
// PERSIST-CUSTOM-PREFIX (run last: it swaps the shared connection prefix)
// ---------------------------------------------------------------------------
$wpdb->set_prefix( 'custom_wp_' );
ghca_persist_ensure_options_table( $wpdb );
ghca_persist_fresh_schema( $wpdb );
$custom_stack = ghca_persist_stack( $wpdb, '2026-07-16T12:40:00Z', 'customprefix' );
$sCustom      = new GHCA_Persist_Scenario( 'prog-custom' );
persist_build_finalized( $custom_stack, $sCustom );
persist_assert_stream_consistent( $wpdb, $sCustom, 'PERSIST-CUSTOM-PREFIX' );
$custom_events = persist_count( $wpdb, 'events' );
archive_check( 7 === $custom_events, 'PERSIST-CUSTOM-PREFIX the full lifecycle persists under an arbitrary table prefix' );
foreach ( ghca_persist_table_names( $wpdb ) as $table ) {
	if ( strpos( $table, 'custom_wp_ghca_acd_archive_' ) !== 0 ) {
		throw new RuntimeException( 'custom prefix table set is polluted: ' . $table );
	}
	ghca_persist_query( $wpdb, 'DROP TABLE ' . ghca_persist_quote_identifier( $table ), 'drop custom prefix table' );
}
$wpdb->set_prefix( 'wp_' );

echo "DB_TARGET={$database->version}|{$database->version_comment}\n";
archive_finish();
