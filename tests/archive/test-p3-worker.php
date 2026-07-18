<?php
require __DIR__ . '/persistence-bootstrap.php';
require __DIR__ . '/persistence-fixtures.php';

function p3_task_id( string $seed ): string {
	return substr( hash( 'sha256', 'p3-task|' . $seed ), 0, 32 );
}

/** @return array<string,mixed> */
function p3_enqueue_task( GHCA_ACD_WPDB_Archive_Task_Store $store, string $seed, string $available = '2026-07-18T12:00:00Z', string $type = 'capture_evidence' ): array {
	$task_id  = p3_task_id( $seed . '|task' );
	$event_id = p3_task_id( $seed . '|event' );
	$stream_id = p3_task_id( $seed . '|stream' );
	$payload = array(
		'canonical_format_version' => GHCA_ACD_Archive_Canonical_JSON::FORMAT_VERSION,
		'stream_id'                => $stream_id,
		'task_schema_version'      => 1,
		'task_type'                => $type,
		'trigger_event_id'         => $event_id,
	);
	$dedupe = GHCA_ACD_Archive_Digester::task_dedupe( array(
		'payload' => $payload, 'task_type' => $type, 'trigger_event_id' => $event_id,
	) );
	$row = array(
		'task_id' => $task_id, 'trigger_kind' => 'event', 'trigger_event_id' => $event_id,
		'trigger_command_id' => null, 'stream_id' => $stream_id, 'archive_id' => p3_task_id( $seed . '|archive' ),
		'build_attempt_id' => null, 'reset_operation_id' => null, 'task_type' => $type,
		'task_schema_version' => 1, 'dedupe_digest' => $dedupe,
		'payload_json' => GHCA_ACD_Archive_Canonical_JSON::encode( $payload ), 'task_state' => 'pending',
		'attempt_count' => 0, 'max_attempts' => 5,
		'available_at_gmt' => GHCA_ACD_Archive_Db_Format::utc_to_db( $available ),
		'lease_owner' => null, 'lease_token' => null, 'lease_until_gmt' => null,
		'last_error_code' => null, 'last_error_text' => null,
		'created_at_gmt' => '2026-07-18 12:00:00', 'updated_at_gmt' => '2026-07-18 12:00:00',
		'completed_at_gmt' => null,
	);
	$store->enqueue( $row );
	return $row;
}

function p3_table_count( $db, string $suffix ): int {
	return (int) $db->get_var( 'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $db->prefix . 'ghca_acd_archive_' . $suffix ) );
}

$task_table = $wpdb->prefix . 'ghca_acd_archive_tasks';

// Separate selection statements and deterministic order.
ghca_persist_fresh_schema( $wpdb );
$reclaim_selects = 0;
$available_selects = 0;
$proxy = new GHCA_Persist_DB_Proxy( $wpdb );
$proxy->add_hook( 'get_row', "task_state = 'leased' AND lease_until_gmt <=", static function () use ( &$reclaim_selects ): void { $reclaim_selects++; } );
$proxy->add_hook( 'get_row', "task_state IN ('pending','retry')", static function () use ( &$available_selects ): void { $available_selects++; } );
$proxy_store = new GHCA_ACD_WPDB_Archive_Task_Store( $proxy );
$proxy_store->reclaim_expired( p3_task_id( 'select-owner' ), p3_task_id( 'select-reclaim-token' ), '2026-07-18T12:00:00Z' );
$proxy_store->claim_available( p3_task_id( 'select-owner' ), p3_task_id( 'select-available-token' ), '2026-07-18T12:00:00Z' );
archive_check( 1 === $reclaim_selects && 1 === $available_selects, 'TASK-CLAIM-SEPARATE-QUERIES execute distinct expired and pending/retry selectors' );

// A failed COMMIT must explicitly roll back and leave the connection reusable.
ghca_persist_fresh_schema( $wpdb );
$commit_proxy = new GHCA_Persist_DB_Proxy( $wpdb );
$commit_store = new GHCA_ACD_WPDB_Archive_Task_Store( $commit_proxy );
$commit_task = p3_enqueue_task( $commit_store, 'commit-failure-cleanup' );
$rollback_attempts = 0;
$commit_proxy->add_hook( 'query', 'COMMIT', 'fail' );
$commit_proxy->add_hook( 'query', 'ROLLBACK', static function () use ( &$rollback_attempts ): void { $rollback_attempts++; } );
persist_expect_failure( $wpdb, static function () use ( $commit_store ) {
	$commit_store->claim_available( p3_task_id( 'commit-failure-owner' ), p3_task_id( 'commit-failure-token' ), '2026-07-18T12:00:00Z' );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_claim_update_failed', 'TASK-CLAIM-COMMIT-FAILURE-ROLLBACK' );
$commit_row = $commit_store->find( $commit_task['task_id'] );
$connection_clean = 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = CONNECTION_ID()' );
$commit_reclaim = $commit_store->claim_available( p3_task_id( 'commit-recovery-owner' ), p3_task_id( 'commit-recovery-token' ), '2026-07-18T12:00:00Z' );
archive_check( 1 === $rollback_attempts && $connection_clean && 'pending' === $commit_row['task_state']
	&& '0' === (string) $commit_row['attempt_count'] && $commit_task['task_id'] === $commit_reclaim['task_id'],
	'TASK-CLAIM-COMMIT-FAILURE-ROLLBACK attempts rollback, leaves no claim residue, cleans the session, and permits the next claim' );

$store = new GHCA_ACD_WPDB_Archive_Task_Store( $wpdb );
$first  = p3_enqueue_task( $store, 'order-first', '2026-07-18T12:00:00Z' );
$second = p3_enqueue_task( $store, 'order-second', '2026-07-18T12:00:00Z' );
$later  = p3_enqueue_task( $store, 'order-later', '2026-07-18T12:01:00Z' );
$owner  = p3_task_id( 'order-owner' );
$claim1 = $store->claim_available( $owner, p3_task_id( 'order-token-1' ), '2026-07-18T12:02:00Z' );
$store->complete( $claim1['task_id'], $owner, $claim1['lease_token'], '2026-07-18T12:02:30Z' );
$claim2 = $store->claim_available( $owner, p3_task_id( 'order-token-2' ), '2026-07-18T12:02:30Z' );
$store->complete( $claim2['task_id'], $owner, $claim2['lease_token'], '2026-07-18T12:03:00Z' );
$claim3 = $store->claim_available( $owner, p3_task_id( 'order-token-3' ), '2026-07-18T12:03:00Z' );
$store->complete( $claim3['task_id'], $owner, $claim3['lease_token'], '2026-07-18T12:03:30Z' );
archive_check( array( $first['task_id'], $second['task_id'], $later['task_id'] ) === array( $claim1['task_id'], $claim2['task_id'], $claim3['task_id'] ), 'TASK-CLAIM-DETERMINISTIC-ORDER uses available_at_gmt then task_row_id' );

// Live lease protection, reclaim, replacement token, and every stale mutation.
ghca_persist_fresh_schema( $wpdb );
$store = new GHCA_ACD_WPDB_Archive_Task_Store( $wpdb );
$task  = p3_enqueue_task( $store, 'lease-fence' );
$owner_a = p3_task_id( 'lease-owner-a' );
$owner_b = p3_task_id( 'lease-owner-b' );
$token_a = p3_task_id( 'lease-token-a' );
$token_b = p3_task_id( 'lease-token-b' );
$claimed = $store->claim_available( $owner_a, $token_a, '2026-07-18T12:00:00Z' );
$connection_two = ghca_persist_new_connection();
$store_two = new GHCA_ACD_WPDB_Archive_Task_Store( $connection_two );
$stolen = $store_two->claim_available( $owner_b, $token_b, '2026-07-18T12:01:59Z' );
archive_check( $task['task_id'] === $claimed['task_id'] && null === $stolen, 'TASK-LIVE-LEASE-NOT-STOLEN protects one live lease across two real connections' );
persist_expect_failure( $wpdb, static function () use ( $store, $task, $owner_a, $token_a ) {
	$store->heartbeat( $task['task_id'], $owner_a, $token_a, '2026-07-18T12:00:30Z', '2026-07-18T12:02:31Z' );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_lease_extension_exceeded', 'TASK-HEARTBEAT-MAX-EXTENSION' );
$reclaimed = $store_two->reclaim_expired( $owner_b, $token_b, '2026-07-18T12:02:00Z' );
archive_check( $task['task_id'] === $reclaimed['task_id'] && $token_b === $reclaimed['lease_token'] && '2' === (string) $reclaimed['attempt_count'], 'TASK-EXPIRED-LEASE-RECLAIM replaces the fencing token and increments once' );

persist_expect_failure( $wpdb, static function () use ( $store, $task, $owner_a, $token_a ) {
	$store->heartbeat( $task['task_id'], $owner_a, $token_a, '2026-07-18T12:02:01Z', '2026-07-18T12:04:01Z' );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_heartbeat_fence_failed', 'TASK-STALE-HEARTBEAT' );
persist_expect_failure( $wpdb, static function () use ( $store, $task, $owner_a, $token_a ) {
	$store->complete( $task['task_id'], $owner_a, $token_a, '2026-07-18T12:02:01Z' );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_completion_fence_failed', 'TASK-STALE-COMPLETION' );
persist_expect_failure( $wpdb, static function () use ( $store, $task, $owner_a, $token_a ) {
	$store->retry( $task['task_id'], $owner_a, $token_a, 'task_handler_failed', GHCA_ACD_Archive_Worker_Coordinator::FAILURE_MESSAGES['task_handler_failed'], '2026-07-18T12:02:01Z' );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_retry_fence_failed', 'TASK-STALE-RETRY' );
persist_expect_failure( $wpdb, static function () use ( $store, $task, $owner_a, $token_a ) {
	$store->dead_letter( $task['task_id'], $owner_a, $token_a, 'task_handler_failed', GHCA_ACD_Archive_Worker_Coordinator::FAILURE_MESSAGES['task_handler_failed'], '2026-07-18T12:02:01Z' );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_dead_letter_fence_failed', 'TASK-STALE-DEAD-LETTER' );
$store_two->complete( $task['task_id'], $owner_b, $token_b, '2026-07-18T12:03:00Z' );

// Retry timing and exact fifth-attempt terminal transition.
ghca_persist_fresh_schema( $wpdb );
$store = new GHCA_ACD_WPDB_Archive_Task_Store( $wpdb );
$retry_task = p3_enqueue_task( $store, 'retry-timing' );
$retry_owner = p3_task_id( 'retry-owner' );
$retry_token = p3_task_id( 'retry-token-1' );
$store->claim_available( $retry_owner, $retry_token, '2026-07-18T12:00:00Z' );
$store->retry( $retry_task['task_id'], $retry_owner, $retry_token, 'task_handler_failed', GHCA_ACD_Archive_Worker_Coordinator::FAILURE_MESSAGES['task_handler_failed'], '2026-07-18T12:00:00Z' );
$too_early = $store->claim_available( $retry_owner, p3_task_id( 'retry-too-early' ), '2026-07-18T12:00:59Z' );
$on_time   = $store->claim_available( $retry_owner, p3_task_id( 'retry-token-2' ), '2026-07-18T12:01:00Z' );
archive_check( null === $too_early && $retry_task['task_id'] === $on_time['task_id'], 'TASK-RETRY-AVAILABILITY rejects one second early and accepts the exact timestamp' );

ghca_persist_fresh_schema( $wpdb );
$store = new GHCA_ACD_WPDB_Archive_Task_Store( $wpdb );
$attempt_task = p3_enqueue_task( $store, 'attempt-limit' );
$attempt_owner = p3_task_id( 'attempt-owner' );
$claim_times = array( '2026-07-18T12:00:00Z', '2026-07-18T12:01:00Z', '2026-07-18T12:06:00Z', '2026-07-18T12:21:00Z', '2026-07-18T13:21:00Z' );
foreach ( $claim_times as $index => $claim_time ) {
	$attempt_token = p3_task_id( 'attempt-token-' . ( $index + 1 ) );
	$current = $store->claim_available( $attempt_owner, $attempt_token, $claim_time );
	if ( $index < 4 ) {
		$store->retry( $attempt_task['task_id'], $attempt_owner, $attempt_token, 'task_handler_failed', GHCA_ACD_Archive_Worker_Coordinator::FAILURE_MESSAGES['task_handler_failed'], $claim_time );
	} else {
		$store->dead_letter( $attempt_task['task_id'], $attempt_owner, $attempt_token, 'task_attempts_exhausted', GHCA_ACD_Archive_Worker_Coordinator::FAILURE_MESSAGES['task_attempts_exhausted'], $claim_time );
	}
}
$dead = $store->find( $attempt_task['task_id'] );
archive_check( 'dead' === $dead['task_state'] && '5' === (string) $dead['attempt_count'] && 'task_attempts_exhausted' === $dead['last_error_code'], 'TASK-MAX-ATTEMPTS-DEAD-EXACTLY-ONCE stops at attempt five' );
persist_expect_failure( $wpdb, static function () use ( $store, $attempt_task, $attempt_owner, $attempt_token ) {
	$store->dead_letter( $attempt_task['task_id'], $attempt_owner, $attempt_token, 'task_attempts_exhausted', GHCA_ACD_Archive_Worker_Coordinator::FAILURE_MESSAGES['task_attempts_exhausted'], '2026-07-18T13:21:01Z' );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_dead_letter_fence_failed', 'TASK-MAX-ATTEMPTS-NO-SECOND-DEAD-LETTER' );

// Fixed persisted messages and retained 2,000-byte backward read.
ghca_persist_fresh_schema( $wpdb );
$store = new GHCA_ACD_WPDB_Archive_Task_Store( $wpdb );
$failure_task = p3_enqueue_task( $store, 'fixed-failure' );
$clock = new GHCA_Persist_Fixed_Clock( '2026-07-18T12:00:00Z' );
$handler_calls = 0;
$committer_calls = 0;
$coordinator = new GHCA_ACD_Archive_Worker_Coordinator(
	$store, $clock, new GHCA_Persist_Sequential_Ids( 'fixed-failure' ), p3_task_id( 'fixed-worker' ),
	array( 'capture_evidence' => static function () use ( &$handler_calls ) {
		$handler_calls++;
		throw new RuntimeException( 'secret@example.test C:\\private\\root token=raw-secret' );
	} ),
	static function () use ( &$committer_calls ) { $committer_calls++; return array(); }
);
$failure_result = $coordinator->run_once();
$failed_row = $store->find( $failure_task['task_id'] );
archive_check( 'retry' === $failure_result['status'] && GHCA_ACD_Archive_Worker_Coordinator::FAILURE_MESSAGES['task_handler_failed'] === $failed_row['last_error_text'], 'WORKER-FAILURE-FIXED-MESSAGE persists only the reviewed fixed message' );
archive_check( false === strpos( $failed_row['last_error_text'], 'secret' ) && 1 === $handler_calls && 0 === $committer_calls, 'WORKER-FAILURE-HANDLER-TEXT-REJECTED never persists or forwards handler exception text' );

$retained = p3_enqueue_task( $store, 'retained-error', '2099-01-01T00:00:00Z' );
$retained_text = str_repeat( 'x', 2000 );
ghca_persist_query( $wpdb, $wpdb->prepare(
	"UPDATE {$task_table} SET task_state = 'dead', attempt_count = 1, last_error_code = 'legacy_failure', last_error_text = %s WHERE task_id = %s",
	$retained_text, $retained['task_id']
), 'write retained legacy task error' );
archive_check( $retained_text === $store->find( $retained['task_id'] )['last_error_text'], 'TASK-RETAINED-ERROR-TEXT-2000-READABLE preserves backward-compatible retained history' );

// Unknown schema, malformed payload, and unsupported type become dead with no handler call.
foreach ( array( 'schema', 'payload', 'type' ) as $invalid_kind ) {
	ghca_persist_fresh_schema( $wpdb );
	$store = new GHCA_ACD_WPDB_Archive_Task_Store( $wpdb );
	$invalid_task = p3_enqueue_task( $store, 'invalid-' . $invalid_kind );
	if ( 'schema' === $invalid_kind ) {
		ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$task_table} SET task_schema_version = 99 WHERE task_id = %s", $invalid_task['task_id'] ), 'tamper unknown task schema' );
		$expected_reason = 'task_schema_unsupported';
	} elseif ( 'payload' === $invalid_kind ) {
		ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$task_table} SET payload_json = '{} ' WHERE task_id = %s", $invalid_task['task_id'] ), 'tamper malformed task payload' );
		$expected_reason = 'task_payload_invalid';
	} else {
		ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$task_table} SET task_type = 'future_task' WHERE task_id = %s", $invalid_task['task_id'] ), 'tamper unknown task type' );
		$expected_reason = 'task_type_unsupported';
	}
	$invalid_calls = 0;
	$coordinator = new GHCA_ACD_Archive_Worker_Coordinator(
		$store, new GHCA_Persist_Fixed_Clock( '2026-07-18T12:00:00Z' ), new GHCA_Persist_Sequential_Ids( 'invalid-' . $invalid_kind ),
		p3_task_id( 'invalid-worker-' . $invalid_kind ),
		array( 'capture_evidence' => static function () use ( &$invalid_calls ) { $invalid_calls++; return array( 'logical_outcome' => 'completed', 'outcome' => array( 'result_code' => 'committed' ) ); } ),
		static function () { throw new RuntimeException( 'committer must not run' ); }
	);
	$result = $coordinator->run_once();
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$task_table} WHERE task_id = %s", $invalid_task['task_id'] ), ARRAY_A );
	archive_check( 'dead' === $result['status'] && $expected_reason === $row['last_error_code'] && 0 === $invalid_calls, 'TASK-INVALID-' . strtoupper( $invalid_kind ) . '-DEAD-ZERO-HANDLER' );
}

ghca_persist_fresh_schema( $wpdb );
$store = new GHCA_ACD_WPDB_Archive_Task_Store( $wpdb );
$unsupported_task = p3_enqueue_task( $store, 'unsupported-handler' );
$unsupported_coordinator = new GHCA_ACD_Archive_Worker_Coordinator(
	$store, new GHCA_Persist_Fixed_Clock( '2026-07-18T12:00:00Z' ), new GHCA_Persist_Sequential_Ids( 'unsupported-handler' ),
	p3_task_id( 'unsupported-handler-worker' ), array(), static function () { throw new RuntimeException( 'must not commit' ); }
);
$unsupported_result = $unsupported_coordinator->run_once();
archive_check( 'dead' === $unsupported_result['status'] && 'task_type_unsupported' === $store->find( $unsupported_task['task_id'] )['last_error_code'], 'WORKER-UNSUPPORTED-HANDLER-DEAD invokes no external side effect' );

ghca_persist_fresh_schema( $wpdb );
$store = new GHCA_ACD_WPDB_Archive_Task_Store( $wpdb );
$heartbeat_task = p3_enqueue_task( $store, 'heartbeat-callback' );
$heartbeat_clock = new GHCA_Persist_Fixed_Clock( '2026-07-18T12:00:00Z' );
$heartbeat_until = null;
$heartbeat_coordinator = new GHCA_ACD_Archive_Worker_Coordinator(
	$store, $heartbeat_clock, new GHCA_Persist_Sequential_Ids( 'heartbeat-callback' ), p3_task_id( 'heartbeat-worker' ),
	array( 'capture_evidence' => static function ( array $task, callable $heartbeat ) use ( $heartbeat_clock, $store, &$heartbeat_until ) {
		$heartbeat_clock->set( '2026-07-18T12:00:30Z' );
		$heartbeat();
		$heartbeat_until = $store->find( $task['task_id'] )['lease_until_gmt'];
		return array( 'logical_outcome' => 'completed', 'outcome' => array( 'result_code' => 'committed' ) );
	} ),
	static function () { return array( 'result_code' => 'committed' ); }
);
$heartbeat_result = $heartbeat_coordinator->run_once();
archive_check( 'completed' === $heartbeat_result['status'] && '2026-07-18 12:02:30' === $heartbeat_until, 'WORKER-HEARTBEAT-30-SECOND-POLICY extends the lease by exactly 120 seconds at the callback' );

// Lease expiry during a failure disposition is an operational lease-loss result.
ghca_persist_fresh_schema( $wpdb );
$store = new GHCA_ACD_WPDB_Archive_Task_Store( $wpdb );
$retry_loss_task = p3_enqueue_task( $store, 'retry-lease-loss' );
$retry_loss_clock = new GHCA_Persist_Fixed_Clock( '2026-07-18T12:00:00Z' );
$retry_loss = ( new GHCA_ACD_Archive_Worker_Coordinator(
	$store, $retry_loss_clock, new GHCA_Persist_Sequential_Ids( 'retry-lease-loss' ), p3_task_id( 'retry-loss-worker' ),
	array( 'capture_evidence' => static function () use ( $retry_loss_clock ) {
		$retry_loss_clock->set( '2026-07-18T12:02:00Z' );
		throw new RuntimeException( 'handler failed after lease expiry' );
	} ),
	static function () { throw new RuntimeException( 'committer must not run' ); }
) )->run_once();
$retry_loss_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$task_table} WHERE task_id = %s", $retry_loss_task['task_id'] ), ARRAY_A );
archive_check( 'lease_lost' === $retry_loss['status'] && 'task_lease_lost' === $retry_loss['reason_code']
	&& 'leased' === $retry_loss_row['task_state'] && null === $retry_loss_row['last_error_code'],
	'WORKER-LEASE-LOSS-DURING-RETRY returns the approved result and writes no stale failure disposition' );

ghca_persist_fresh_schema( $wpdb );
$store = new GHCA_ACD_WPDB_Archive_Task_Store( $wpdb );
$dead_loss_task = p3_enqueue_task( $store, 'dead-letter-lease-loss' );
ghca_persist_query( $wpdb, $wpdb->prepare(
	"UPDATE {$task_table} SET task_state = 'retry', attempt_count = 4, last_error_code = 'task_handler_failed', last_error_text = %s WHERE task_id = %s",
	GHCA_ACD_Archive_Worker_Coordinator::FAILURE_MESSAGES['task_handler_failed'], $dead_loss_task['task_id']
), 'prepare fifth attempt lease-loss task' );
$dead_loss_clock = new GHCA_Persist_Fixed_Clock( '2026-07-18T12:00:00Z' );
$dead_loss = ( new GHCA_ACD_Archive_Worker_Coordinator(
	$store, $dead_loss_clock, new GHCA_Persist_Sequential_Ids( 'dead-letter-lease-loss' ), p3_task_id( 'dead-loss-worker' ),
	array( 'capture_evidence' => static function () use ( $dead_loss_clock ) {
		$dead_loss_clock->set( '2026-07-18T12:02:00Z' );
		throw new RuntimeException( 'fifth handler attempt failed after lease expiry' );
	} ),
	static function () { throw new RuntimeException( 'committer must not run' ); }
) )->run_once();
$dead_loss_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$task_table} WHERE task_id = %s", $dead_loss_task['task_id'] ), ARRAY_A );
archive_check( 'lease_lost' === $dead_loss['status'] && 'task_lease_lost' === $dead_loss['reason_code']
	&& 'leased' === $dead_loss_row['task_state'] && '5' === (string) $dead_loss_row['attempt_count']
	&& 'task_handler_failed' === $dead_loss_row['last_error_code'],
	'WORKER-LEASE-LOSS-DURING-DEAD-LETTER returns the approved result and writes no stale terminal disposition' );

// A real database execution failure at disposition is never called lease loss.
ghca_persist_fresh_schema( $wpdb );
$database_proxy = new GHCA_Persist_DB_Proxy( $wpdb );
$database_store = new GHCA_ACD_WPDB_Archive_Task_Store( $database_proxy );
$database_task = p3_enqueue_task( $database_store, 'database-failure-disposition' );
$database_proxy->add_hook( 'query', "SET task_state = 'retry'", 'fail' );
$database_error = null;
$database_result = null;
try {
	$database_result = ( new GHCA_ACD_Archive_Worker_Coordinator(
		$database_store, new GHCA_Persist_Fixed_Clock( '2026-07-18T12:00:00Z' ), new GHCA_Persist_Sequential_Ids( 'database-failure-disposition' ),
		p3_task_id( 'database-failure-worker' ), array( 'capture_evidence' => static function () { throw new RuntimeException( 'handler failure' ); } ),
		static function () { throw new RuntimeException( 'committer must not run' ); }
	) )->run_once();
} catch ( Throwable $error ) {
	$database_error = $error;
}
$database_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$task_table} WHERE task_id = %s", $database_task['task_id'] ), ARRAY_A );
archive_check( null === $database_result && $database_error instanceof GHCA_ACD_Archive_Persistence_Exception
	&& 'task_retry_fence_failed' === $database_error->reason_code()
	&& GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL === $database_error->category()
	&& 'leased' === $database_row['task_state'] && null === $database_row['last_error_code']
	&& 0 === p3_table_count( $wpdb, 'events' ) && 0 === p3_table_count( $wpdb, 'artifacts' ),
	'WORKER-DATABASE-FAILURE-NOT-MASKED-AS-LEASE-LOSS exposes the internal error and adds no outcome or failure residue' );

// Handler output is the single exact 27-byte approved machine document.
$recursive_outcome = static function () {
	$value = array();
	$value['result_code'] =& $value;
	return array( 'logical_outcome' => 'completed', 'outcome' => $value );
};
$invalid_outcomes = array(
	'WORKER-OUTCOME-DEPTH-BOUND' => static function () { return array( 'logical_outcome' => 'completed', 'outcome' => array( 'result_code' => array( 'nested' => 'committed' ) ) ); },
	'WORKER-OUTCOME-VALUE-COUNT-BOUND' => static function () { return array( 'logical_outcome' => 'completed', 'outcome' => array( 'result_code' => 'committed', 'extra' => 'x' ) ); },
	'WORKER-OUTCOME-STRING-BOUND' => static function () { return array( 'logical_outcome' => 'completed', 'outcome' => array( 'result_code' => '0123456789' ) ); },
	'WORKER-OUTCOME-RECURSION-REJECTED' => $recursive_outcome,
	'WORKER-OUTCOME-FREE-FORM-REJECTED' => static function () { return array( 'logical_outcome' => 'completed', 'outcome' => array( 'result_code' => 'secret' ) ); },
);
foreach ( $invalid_outcomes as $case_name => $case_handler ) {
	ghca_persist_fresh_schema( $wpdb );
	$store = new GHCA_ACD_WPDB_Archive_Task_Store( $wpdb );
	$outcome_task = p3_enqueue_task( $store, strtolower( $case_name ) );
	$outcome_commits = 0;
	$outcome_result = ( new GHCA_ACD_Archive_Worker_Coordinator(
		$store, new GHCA_Persist_Fixed_Clock( '2026-07-18T12:00:00Z' ), new GHCA_Persist_Sequential_Ids( strtolower( $case_name ) ),
		p3_task_id( strtolower( $case_name ) . '|worker' ), array( 'capture_evidence' => $case_handler ),
		static function () use ( &$outcome_commits ) { $outcome_commits++; return array(); }
	) )->run_once();
	$outcome_row = $store->find( $outcome_task['task_id'] );
	archive_check( 'retry' === $outcome_result['status'] && 'task_handler_failed' === $outcome_result['reason_code']
		&& 'task_handler_failed' === $outcome_row['last_error_code'] && 0 === $outcome_commits
		&& GHCA_ACD_Archive_Worker_Coordinator::FAILURE_MESSAGES['task_handler_failed'] === $outcome_row['last_error_text'],
		$case_name . ' rejects the document with the fixed result and no authoritative outcome' );
}

ghca_persist_fresh_schema( $wpdb );
$store = new GHCA_ACD_WPDB_Archive_Task_Store( $wpdb );
$valid_outcome_task = p3_enqueue_task( $store, 'valid-machine-fields' );
$valid_machine_document = array( 'result_code' => 'committed' );
$valid_machine_canonical = GHCA_ACD_Archive_Canonical_JSON::encode( $valid_machine_document );
$valid_outcome_seen = null;
$valid_outcome_result = ( new GHCA_ACD_Archive_Worker_Coordinator(
	$store, new GHCA_Persist_Fixed_Clock( '2026-07-18T12:00:00Z' ), new GHCA_Persist_Sequential_Ids( 'valid-machine-fields' ), p3_task_id( 'valid-machine-worker' ),
	array( 'capture_evidence' => static function () use ( $valid_machine_document ) { return array( 'logical_outcome' => 'completed', 'outcome' => $valid_machine_document ); } ),
	static function ( array $task, string $logical, array $outcome ) use ( &$valid_outcome_seen ) { $valid_outcome_seen = $outcome; return array( 'stored' => true ); }
) )->run_once();
archive_check( 'completed' === $valid_outcome_result['status'] && $valid_machine_document === $valid_outcome_seen
	&& 27 === strlen( $valid_machine_canonical ) && 9 === strlen( $valid_machine_document['result_code'] )
	&& 11 === strlen( 'result_code' ) && 'completed' === $store->find( $valid_outcome_task['task_id'] )['task_state'],
	'WORKER-OUTCOME-VALID-MACHINE-FIELDS accepts only result_code=committed under the exact 27-byte contract' );

// Two independent PHP processes contend at the same instant for one row.
ghca_persist_fresh_schema( $wpdb );
$store = new GHCA_ACD_WPDB_Archive_Task_Store( $wpdb );
$race_task = p3_enqueue_task( $store, 'process-race' );
$race_script = __DIR__ . '/p3-lease-race-worker.php';
$base = array( PHP_BINARY );
if ( PHP_VERSION_ID < 80000 ) {
	$base[] = '-d';
	$base[] = 'extension_dir=' . dirname( PHP_BINARY ) . '/ext';
	$base[] = '-d';
	$base[] = 'extension=mysqli';
}
$start = microtime( true ) + 0.75;
$commands = array(
	array_merge( $base, array( $race_script, p3_task_id( 'race-owner-a' ), p3_task_id( 'race-token-a' ), '2026-07-18T12:00:00Z', (string) $start ) ),
	array_merge( $base, array( $race_script, p3_task_id( 'race-owner-b' ), p3_task_id( 'race-token-b' ), '2026-07-18T12:00:00Z', (string) $start ) ),
);
$processes = array();
foreach ( $commands as $command ) {
	$pipes = array();
	$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, __DIR__, null, array( 'bypass_shell' => true ) );
	fclose( $pipes[0] );
	$processes[] = array( $process, $pipes );
}
$race_outputs = array();
$race_ok = true;
foreach ( $processes as $process_data ) {
	list( $process, $pipes ) = $process_data;
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$exit = proc_close( $process );
	$decoded = json_decode( $stdout, true );
	$race_ok = $race_ok && 0 === $exit && is_array( $decoded ) && array_key_exists( 'task_id', $decoded ) && '' === $stderr;
	if ( 0 !== $exit || ! is_array( $decoded ) || '' !== $stderr ) {
		echo 'LEASE_RACE_CHILD_DIAGNOSTIC=' . json_encode( array( 'exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr ) ) . "\n";
	}
	$race_outputs[] = is_array( $decoded ) ? $decoded['task_id'] : 'invalid';
}
$winner_count = count( array_filter( $race_outputs, static function ( $task_id ) use ( $race_task ) { return $task_id === $race_task['task_id']; } ) );
archive_check( $race_ok && 1 === $winner_count && 1 === count( array_filter( $race_outputs, static function ( $task_id ) { return null === $task_id; } ) ), 'TASK-TWO-CONNECTION-LEASE-RACE exactly one independent process owns the task' );
echo 'LEASE_RACE_TRACE=' . json_encode( $race_outputs ) . "\n";

// Crash after authoritative outcome commit: stale fence is rejected, new lease replays one receipt.
ghca_persist_fresh_schema( $wpdb );
$stack = ghca_persist_stack( $wpdb, '2026-07-18T12:00:00Z', 'outcome-replay' );
$scenario = new GHCA_Persist_Scenario( 'p3-outcome-replay' );
persist_request_archive( $stack, $scenario );
$task = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$task_table} WHERE stream_id = %s AND task_type = 'capture_evidence'", $scenario->stream_id ), ARRAY_A );
$old_owner = p3_task_id( 'outcome-old-owner' );
$old_token = p3_task_id( 'outcome-old-token' );
$stack['task_store']->claim_available( $old_owner, $old_token, '2026-07-18T12:00:00Z' );
$outcome_key = GHCA_ACD_Archive_Digester::task_outcome( array( 'logical_outcome' => 'completed', 'task_id' => $task['task_id'], 'task_schema_version' => 1 ) );
$payload = $scenario->payload( 'ArchiveBuildStarted', array( 'archive_id' => $scenario->id( 'archive-1' ), 'build_attempt_id' => $scenario->id( 'attempt-1' ) ) );
list( $caller, $server ) = ghca_persist_split( 'StartBuild', $payload );
$scope = ghca_persist_scope_document( $scenario, 'StartBuild' );
$command = GHCA_ACD_Archive_Command::start_build(
	$scenario->id( 'p3-outcome-command' ), GHCA_ACD_Archive_Digester::idempotency_scope( $scope ), $outcome_key,
	$scenario->head_sequence, $scenario->actor, $caller, $server
);
$base_request = array(
	'command' => $command, 'case_key' => $scenario->case_key, 'idempotency_scope' => $scope,
	'expected_head_digest' => $scenario->head_digest, 'correlation_id' => $scenario->id( 'p3-outcome-correlation' ),
);
$old_fence = array( 'task_id' => $task['task_id'], 'lease_owner' => $old_owner, 'lease_token' => $old_token );
$first_request = $base_request;
$first_request['task_fence'] = $old_fence;
$stack['clock']->set( '2026-07-18T12:00:30Z' );
$first_response = $stack['uow']->execute( $first_request );
$events_after_first = p3_table_count( $wpdb, 'events' );
$receipts_after_first = p3_table_count( $wpdb, 'commands' );
$artifacts_after_first = p3_table_count( $wpdb, 'artifacts' );
$stack['clock']->set( '2026-07-18T12:02:00Z' );
persist_expect_failure( $wpdb, static function () use ( $stack, $first_request ) {
	return $stack['uow']->execute( $first_request );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_outcome_fence_failed', 'TASK-OUTCOME-STALE-FENCE-REPLAY-REJECTED' );

$replay_handler_calls = 0;
$replay_committer_calls = 0;
$coordinator = new GHCA_ACD_Archive_Worker_Coordinator(
	$stack['task_store'], $stack['clock'], new GHCA_Persist_Sequential_Ids( 'outcome-replay-coordinator' ), p3_task_id( 'outcome-new-owner' ),
	array( 'capture_evidence' => static function () use ( &$replay_handler_calls ) {
		$replay_handler_calls++;
		return array( 'logical_outcome' => 'completed', 'outcome' => array( 'result_code' => 'committed' ) );
	} ),
	static function ( array $claimed_task, string $logical_outcome, array $outcome, string $key, array $fence ) use ( &$replay_committer_calls, $stack, $base_request, $outcome_key ) {
		$replay_committer_calls++;
		if ( 'completed' !== $logical_outcome || $key !== $outcome_key ) {
			throw new RuntimeException( 'wrong frozen task outcome key' );
		}
		$request = $base_request;
		$request['task_fence'] = $fence;
		return $stack['uow']->execute( $request );
	}
);
$replay_result = $coordinator->run_once();
$completed_task = $stack['task_store']->find( $task['task_id'] );
archive_check( 'completed' === $replay_result['status'] && $first_response === $replay_result['response'] && '2' === (string) $completed_task['attempt_count'], 'TASK-OUTCOME-CRASH-REPLAY returns the stored response then completes the replacement lease' );
archive_check( 1 === $replay_handler_calls && 1 === $replay_committer_calls && $events_after_first === p3_table_count( $wpdb, 'events' ) && $receipts_after_first === p3_table_count( $wpdb, 'commands' ) && $artifacts_after_first === p3_table_count( $wpdb, 'artifacts' ), 'TASK-OUTCOME-CRASH-NO-DUPLICATES adds no duplicate event, receipt, or artifact descriptor' );

archive_finish();
