<?php
require_once __DIR__ . '/persistence-bootstrap.php';
require_once __DIR__ . '/persistence-fixtures.php';

function p3b2ac_id( string $seed ): string {
	return substr( hash( 'sha256', 'p3b2ac|' . $seed ), 0, 32 );
}

/** @return array<string,mixed> */
function p3b2ac_enqueue_packet( GHCA_ACD_WPDB_Archive_Task_Store $store ): array {
	$event_id = p3b2ac_id( 'packet-event' );
	$payload = array(
		'canonical_format_version' => 1,
		'stream_id' => p3b2ac_id( 'packet-stream' ),
		'task_schema_version' => 1,
		'task_type' => 'materialize_packet',
		'trigger_event_id' => $event_id,
	);
	$row = array(
		'task_id' => p3b2ac_id( 'packet-task' ),
		'trigger_kind' => 'event',
		'trigger_event_id' => $event_id,
		'trigger_command_id' => null,
		'stream_id' => $payload['stream_id'],
		'archive_id' => p3b2ac_id( 'packet-archive' ),
		'build_attempt_id' => null,
		'reset_operation_id' => null,
		'task_type' => 'materialize_packet',
		'task_schema_version' => 1,
		'dedupe_digest' => GHCA_ACD_Archive_Digester::task_dedupe( array(
			'payload' => $payload, 'task_type' => 'materialize_packet', 'trigger_event_id' => $event_id,
		) ),
		'payload_json' => GHCA_ACD_Archive_Canonical_JSON::encode( $payload ),
		'task_state' => 'pending',
		'attempt_count' => 0,
		'max_attempts' => 5,
		'available_at_gmt' => '2026-07-26 14:59:00',
		'lease_owner' => null,
		'lease_token' => null,
		'lease_until_gmt' => null,
		'last_error_code' => null,
		'last_error_text' => null,
		'created_at_gmt' => '2026-07-26 14:59:00',
		'updated_at_gmt' => '2026-07-26 14:59:00',
		'completed_at_gmt' => null,
	);
	$store->enqueue( $row );
	return $row;
}

ghca_persist_fresh_schema( $wpdb );
$stack = ghca_persist_stack( $wpdb, '2026-07-26T15:00:00Z', 'p3b2a-concurrency' );
$scenario = new GHCA_Persist_Scenario( 'p3b2a_concurrency' );
$packet = p3b2ac_enqueue_packet( $stack['task_store'] );
persist_request_archive( $stack, $scenario );
$capture = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}ghca_acd_archive_tasks WHERE stream_id = %s AND task_type = 'capture_evidence'",
	$scenario->stream_id
), ARRAY_A );

$owner_a = p3b2ac_id( 'owner-a' );
$token_a = p3b2ac_id( 'token-a' );
$owner_b = p3b2ac_id( 'owner-b' );
$token_b = p3b2ac_id( 'token-b' );
$claimed_a = $stack['task_store']->claim_available( $owner_a, $token_a, '2026-07-26T15:00:00Z', array( 'capture_evidence' ) );
$connection_b = ghca_persist_new_connection();
$store_b = new GHCA_ACD_WPDB_Archive_Task_Store( $connection_b );
$claimed_b = $store_b->claim_available( $owner_b, $token_b, '2026-07-26T15:00:00Z', array( 'capture_evidence' ) );
$packet_after = $stack['task_store']->find( $packet['task_id'] );
archive_check(
	$capture['task_id'] === $claimed_a['task_id'] && null === $claimed_b && 'pending' === $packet_after['task_state'],
	'P3B2A-INSTALLED-TYPE-CLAIM-FILTERING leaves an earlier packet task untouched while claiming eligible evidence'
);

$stale_rejected = false;
try {
	$store_b->assert_live_lease( $capture['task_id'], $owner_b, $token_b, '2026-07-26T15:00:00Z' );
} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
	$stale_rejected = GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED === $error->category()
		&& 'task_lease_lost' === $error->reason_code();
}
archive_check(
	$stale_rejected && $owner_a === $stack['task_store']->find( $capture['task_id'] )['lease_owner'],
	'P3B2A-TWO-WORKER-ONE-OWNER proves two real connections cannot both own or fence one capture task'
);

$without_constructor = ( new ReflectionClass( GHCA_ACD_Archive_Build_Coordinator::class ) )->newInstanceWithoutConstructor();
$p3b1_method = new ReflectionMethod( GHCA_ACD_Archive_Build_Coordinator::class, 'derived_id' );
$capture_method = new ReflectionMethod( GHCA_ACD_Archive_Build_Coordinator::class, 'capture_derived_id' );
$task_id = '0123456789abcdef0123456789abcdef';
$p3b1_purposes = array(
	'FailArchive',
	'FailArchiveCorrelation',
	'RecordMaterializedArtifact',
	'RecordMaterializedArtifactCorrelation',
);
$p3b1_unchanged = true;
foreach ( $p3b1_purposes as $purpose ) {
	$expected = substr( hash( 'sha256', 'ghca-p3b1-command-id-v1|' . $purpose . '|' . $task_id ), 0, 32 );
	$p3b1_unchanged = $p3b1_unchanged && $expected === $p3b1_method->invoke( $without_constructor, $purpose, $task_id );
}
$capture_purposes = array(
	'BuildAttempt',
	'DriftIncident',
	'Snapshot',
	'command:DetectSourceDrift',
	'command:FailArchive',
	'command:RecordEvidenceSnapshot',
	'command:StartBuild',
	'correlation:DetectSourceDrift',
	'correlation:FailArchive',
	'correlation:RecordEvidenceSnapshot',
	'correlation:StartBuild',
);
$capture_isolated = true;
foreach ( $capture_purposes as $purpose ) {
	$capture_id = $capture_method->invoke( $without_constructor, $purpose, $task_id );
	$capture_isolated = $capture_isolated
		&& substr( hash( 'sha256', 'ghca-p3b2a-capture-id-v1|' . $purpose . '|' . $task_id ), 0, 32 ) === $capture_id
		&& $p3b1_method->invoke( $without_constructor, $purpose, $task_id ) !== $capture_id;
}
archive_check(
	$p3b1_unchanged && $capture_isolated,
	'P3B2A-P3B1-ID-DOMAINS-UNCHANGED preserves every retained ledger ID and isolates every capture identity'
);

$stack['task_store']->complete( $capture['task_id'], $owner_a, $token_a, '2026-07-26T15:00:01Z' );
archive_check(
	'completed' === $stack['task_store']->find( $capture['task_id'] )['task_state']
	&& 'pending' === $stack['task_store']->find( $packet['task_id'] )['task_state'],
	'P3B2A-INSTALLED-TYPE-COMPLETION-BOUNDARY completes only the owned evidence task'
);

archive_finish();
