<?php
require_once __DIR__ . '/persistence-bootstrap.php';
require_once __DIR__ . '/persistence-fixtures.php';

const GHCA_P3B1_CURSOR_TEST_KEY = '9d6bb5a9a85eaab71a696f8e0ca764a80de108dd96c432c0f3e05f25ca506ea4';

function p3b_build_root( string $seed ): string {
	$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ghca_p3b1_' . substr( hash( 'sha256', $seed ), 0, 24 );
	if ( ! is_dir( $root ) && ! mkdir( $root, 0700, true ) ) {
		throw new RuntimeException( 'Could not create the isolated P3B1 test root.' );
	}
	return $root;
}

function p3b_build_cleanup( string $root ): void {
	$resolved = realpath( $root );
	$base = realpath( sys_get_temp_dir() );
	if ( false === $resolved || false === $base
		|| 0 !== strpos( $resolved, $base . DIRECTORY_SEPARATOR . 'ghca_p3b1_' ) ) {
		throw new RuntimeException( 'P3B1 cleanup boundary rejected the requested root.' );
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $resolved, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $entry ) {
		$path = $entry->getPathname();
		if ( $entry->isLink() || $entry->isFile() ) {
			unlink( $path );
		} else {
			rmdir( $path );
		}
	}
	rmdir( $resolved );
}

/** @return array<string,mixed> */
function p3b_build_fixture( $db, string $seed, string $now, string $root ): array {
	ghca_persist_fresh_schema( $db );
	$stack = ghca_persist_stack( $db, $now, 'p3b-build-' . $seed );
	$scenario = new GHCA_Persist_Scenario( 'p3b_build_' . $seed );
	persist_request_archive( $stack, $scenario );
	persist_start_build( $stack, $scenario );
	persist_record_snapshot( $stack, $scenario );
	$task_table = $db->prefix . 'ghca_acd_archive_tasks';
	$task = $db->get_row( $db->prepare(
		"SELECT * FROM {$task_table} WHERE stream_id = %s AND task_type = 'materialize_ledger'",
		$scenario->stream_id
	), ARRAY_A );
	$store = new GHCA_ACD_Private_Archive_Artifact_Store( $root, array( ABSPATH ), GHCA_P3B1_CURSOR_TEST_KEY );
	$materializer = new GHCA_ACD_Archive_Ledger_Materializer();
	$handler = new GHCA_ACD_Archive_Ledger_Task_Handler(
		$stack['event_store'], $stack['snapshot_store'], $stack['artifact_repository'], $store, $materializer
	);
	$build = new GHCA_ACD_Archive_Build_Coordinator(
		$stack['event_store'], $stack['snapshot_store'], $stack['artifact_repository'], $stack['uow']
	);
	return array(
		'stack' => $stack, 'scenario' => $scenario, 'task' => $task,
		'store' => $store, 'handler' => $handler, 'build' => $build,
	);
}

function p3b_event_count( $db, string $stream_id, string $event_type ): int {
	$table = $db->prefix . 'ghca_acd_archive_events';
	return (int) $db->get_var( $db->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE stream_id = %s AND event_type = %s",
		$stream_id, $event_type
	) );
}

// Complete dark-mode ledger materialization through the fenced Build Coordinator/UoW.
$root = p3b_build_root( 'success' );
try {
	$fixture = p3b_build_fixture( $wpdb, 'success', '2026-07-24T15:00:00Z', $root );
	$stack = $fixture['stack'];
	$task = $fixture['task'];
	$result = ( new GHCA_ACD_Archive_Worker_Coordinator(
		$stack['task_store'], $stack['clock'], new GHCA_Persist_Sequential_Ids( 'p3b-success-worker' ),
		substr( hash( 'sha256', 'p3b-success-owner' ), 0, 32 ),
		array( 'materialize_ledger' => $fixture['handler'] ), null, $fixture['build']
	) )->run_once();
	$row = $stack['task_store']->find( $task['task_id'] );
	$payload = GHCA_ACD_Archive_Canonical_JSON::decode_canonical( $task['payload_json'] );
	$descriptor = $stack['artifact_repository']->find_descriptor( $payload['ledger_artifact_id'] );
	$items = $stack['artifact_repository']->load_ledger_items( $payload['ledger_artifact_id'] );
	archive_check(
		'completed' === $result['status'] && 'committed' === $result['response']['result_code']
		&& 'RecordMaterializedArtifact' === $result['response']['command_type']
		&& 'completed' === $row['task_state'] && 1 === p3b_event_count( $wpdb, $fixture['scenario']->stream_id, 'LedgerMaterialized' ),
		'P3B1-BUILD-COORDINATOR-FENCED-SUCCESS commits the lifecycle outcome before task completion'
	);
	archive_check(
		null !== $descriptor && 2 === count( $items ) && $payload['ledger_artifact_id'] === $descriptor['artifact_id']
		&& 'ghca_archive_ledger_materializer' === $descriptor['producer_key']
		&& '1.0.0' === $descriptor['producer_version'],
		'P3B1-BUILD-COORDINATOR-EXACT-SIDE-RECORDS persists the descriptor and ordered ledger items atomically'
	);
} finally {
	p3b_build_cleanup( $root );
}

// Attempt five: command commit followed by process loss must replay and complete without ArchiveFailed.
$root = p3b_build_root( 'attempt5-command' );
try {
	$fixture = p3b_build_fixture( $wpdb, 'attempt5_command', '2026-07-24T16:00:00Z', $root );
	$stack = $fixture['stack'];
	$task = $fixture['task'];
	$task_table = $wpdb->prefix . 'ghca_acd_archive_tasks';
	ghca_persist_query( $wpdb, $wpdb->prepare(
		"UPDATE {$task_table} SET task_state = 'retry', attempt_count = 4, last_error_code = 'task_handler_failed', last_error_text = %s WHERE task_id = %s",
		GHCA_ACD_Archive_Worker_Coordinator::FAILURE_MESSAGES['task_handler_failed'], $task['task_id']
	), 'prepare attempt-five command-loss fixture' );
	$owner = substr( hash( 'sha256', 'attempt5-command-owner' ), 0, 32 );
	$token = substr( hash( 'sha256', 'attempt5-command-token' ), 0, 32 );
	$claimed = $stack['task_store']->claim_available( $owner, $token, '2026-07-24T16:00:00Z', array( 'materialize_ledger' ) );
	$claimed = $stack['task_store']->validate_claimed_v1( $stack['task_store']->load_claimed( $claimed['task_id'], $owner, $token, '2026-07-24T16:00:00Z' ) );
	$prepared = $fixture['handler']( $claimed, static function (): void {} );
	$prepared = $fixture['handler']->validate_prepared_result( $claimed, $prepared );
	$outcome_key = GHCA_ACD_Archive_Digester::task_outcome( array(
		'logical_outcome' => 'completed', 'task_id' => $claimed['task_id'], 'task_schema_version' => 1,
	) );
	$fence = array( 'task_id' => $claimed['task_id'], 'lease_owner' => $owner, 'lease_token' => $token );
	$first_response = $fixture['build']->record_ledger( $claimed, $prepared, $outcome_key, $fence );
	$event_count = p3b_event_count( $wpdb, $fixture['scenario']->stream_id, 'LedgerMaterialized' );
	$command_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_commands' ) );
	$artifact_count = (int) $wpdb->get_var( $wpdb->prepare(
		'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_artifacts' ) . ' WHERE artifact_id = %s',
		$claimed['payload']['ledger_artifact_id']
	) );
	$stack['clock']->set( '2026-07-24T16:02:00Z' );
	$replayed = ( new GHCA_ACD_Archive_Worker_Coordinator(
		$stack['task_store'], $stack['clock'], new GHCA_Persist_Sequential_Ids( 'p3b-attempt5-command-replay' ),
		substr( hash( 'sha256', 'attempt5-command-new-owner' ), 0, 32 ),
		array( 'materialize_ledger' => $fixture['handler'] ), null, $fixture['build']
	) )->run_once();
	$after = $stack['task_store']->find( $task['task_id'] );
	archive_check(
		'completed' === $replayed['status'] && $first_response === $replayed['response']
		&& 'completed' === $after['task_state'] && '5' === (string) $after['attempt_count']
		&& 1 === $event_count && $event_count === p3b_event_count( $wpdb, $fixture['scenario']->stream_id, 'LedgerMaterialized' )
		&& 0 === p3b_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' )
		&& $command_count === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_commands' ) )
		&& $artifact_count === (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_artifacts' ) . ' WHERE artifact_id = %s',
			$claimed['payload']['ledger_artifact_id']
		) ),
		'P3B1-ATTEMPT5-CRASH-AFTER-COMMAND-BEFORE-TASK-COMPLETE replays one receipt with no duplicate or false ArchiveFailed'
	);
} finally {
	p3b_build_cleanup( $root );
}

// Attempt five: an exact committed object without a DB outcome is reused, never overwritten.
$root = p3b_build_root( 'attempt5-object' );
try {
	$fixture = p3b_build_fixture( $wpdb, 'attempt5_object', '2026-07-24T17:00:00Z', $root );
	$stack = $fixture['stack'];
	$task = $fixture['task'];
	$task_table = $wpdb->prefix . 'ghca_acd_archive_tasks';
	ghca_persist_query( $wpdb, $wpdb->prepare(
		"UPDATE {$task_table} SET task_state = 'retry', attempt_count = 4, last_error_code = 'task_handler_failed', last_error_text = %s WHERE task_id = %s",
		GHCA_ACD_Archive_Worker_Coordinator::FAILURE_MESSAGES['task_handler_failed'], $task['task_id']
	), 'prepare attempt-five object-loss fixture' );
	$owner = substr( hash( 'sha256', 'attempt5-object-owner' ), 0, 32 );
	$token = substr( hash( 'sha256', 'attempt5-object-token' ), 0, 32 );
	$claimed = $stack['task_store']->claim_available( $owner, $token, '2026-07-24T17:00:00Z', array( 'materialize_ledger' ) );
	$claimed = $stack['task_store']->validate_claimed_v1( $stack['task_store']->load_claimed( $claimed['task_id'], $owner, $token, '2026-07-24T17:00:00Z' ) );
	$prepared = $fixture['handler']( $claimed, static function (): void {} );
	$descriptor = $prepared['artifact_descriptor'];
	$handle = $fixture['store']->open_committed( $descriptor['storage_key'], 'ledger', $descriptor['byte_count'], $descriptor['content_digest'] );
	$before_bytes = stream_get_contents( $handle );
	fclose( $handle );
	$stack['clock']->set( '2026-07-24T17:02:00Z' );
	$recovered = ( new GHCA_ACD_Archive_Worker_Coordinator(
		$stack['task_store'], $stack['clock'], new GHCA_Persist_Sequential_Ids( 'p3b-attempt5-object-replay' ),
		substr( hash( 'sha256', 'attempt5-object-new-owner' ), 0, 32 ),
		array( 'materialize_ledger' => $fixture['handler'] ), null, $fixture['build']
	) )->run_once();
	$handle = $fixture['store']->open_committed( $descriptor['storage_key'], 'ledger', $descriptor['byte_count'], $descriptor['content_digest'] );
	$after_bytes = stream_get_contents( $handle );
	fclose( $handle );
	archive_check(
		'completed' === $recovered['status'] && $before_bytes === $after_bytes
		&& hash( 'sha256', $after_bytes ) === $descriptor['content_digest']
		&& 1 === p3b_event_count( $wpdb, $fixture['scenario']->stream_id, 'LedgerMaterialized' )
		&& 0 === p3b_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' ),
		'P3B1-ATTEMPT5-CRASH-AFTER-OBJECT-BEFORE-COMMAND reuses exact immutable bytes and commits materialization once'
	);
} finally {
	p3b_build_cleanup( $root );
}

archive_finish();
