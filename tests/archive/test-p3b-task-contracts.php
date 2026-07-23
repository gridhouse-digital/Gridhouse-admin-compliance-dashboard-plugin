<?php
require_once __DIR__ . '/persistence-bootstrap.php';
require_once __DIR__ . '/persistence-fixtures.php';

/** @param array<string,mixed> $row @param array<string,mixed> $payload @return array<string,mixed> */
function p3b_contract_row( array $row, array $payload ): array {
	$row['payload_json'] = GHCA_ACD_Archive_Canonical_JSON::encode( $payload );
	$row['dedupe_digest'] = GHCA_ACD_Archive_Digester::task_dedupe( array(
		'payload' => $payload, 'task_type' => $row['task_type'], 'trigger_event_id' => $row['trigger_event_id'],
	) );
	return $row;
}

function p3b_contract_expect_failure( callable $callback, string $expected_class, string $expected_reason, string $message ): void {
	$caught = null;
	$result = null;
	try {
		$result = $callback();
	} catch ( Throwable $error ) {
		$caught = $error;
	}
	archive_check( null !== $caught, $message . ' throws' );
	archive_check( null === $result, $message . ' returns no successful response' );
	archive_check( null !== $caught && get_class( $caught ) === $expected_class, $message . ' uses exact class ' . $expected_class );
	$reason = $caught instanceof GHCA_ACD_Archive_Persistence_Exception ? $caught->reason_code() : null;
	archive_check( $expected_reason === $reason, $message . ' exposes reason ' . $expected_reason );
}

ghca_persist_fresh_schema( $wpdb );
$stack = ghca_persist_stack( $wpdb, '2026-07-24T12:00:00Z', 'p3b-contracts' );
$scenario = new GHCA_Persist_Scenario( 'p3b_contracts' );
persist_request_archive( $stack, $scenario );
persist_start_build( $stack, $scenario );
persist_record_snapshot( $stack, $scenario );

$task_table = $wpdb->prefix . 'ghca_acd_archive_tasks';
$tasks = $wpdb->get_results( $wpdb->prepare(
	"SELECT * FROM {$task_table} WHERE stream_id = %s ORDER BY task_row_id ASC",
	$scenario->stream_id
), ARRAY_A );
$ledger = null;
$packet = null;
$capture = null;
foreach ( $tasks as $row ) {
	if ( 'materialize_ledger' === $row['task_type'] ) { $ledger = $row; }
	if ( 'materialize_packet' === $row['task_type'] ) { $packet = $row; }
	if ( 'capture_evidence' === $row['task_type'] ) { $capture = $row; }
}
$ledger_payload = GHCA_ACD_Archive_Canonical_JSON::decode_canonical( $ledger['payload_json'] );
$packet_payload = GHCA_ACD_Archive_Canonical_JSON::decode_canonical( $packet['payload_json'] );
archive_check(
	array_keys( $ledger_payload ) === GHCA_ACD_Archive_Task_Catalog::LEDGER_PAYLOAD_FIELDS
	&& strlen( $ledger['payload_json'] ) <= GHCA_ACD_Archive_Task_Catalog::LEDGER_PAYLOAD_MAX_BYTES
	&& 1 === preg_match( '/^[a-f0-9]{32}$/', $ledger_payload['ledger_artifact_id'] ),
	'P3B1-PAYLOAD-LEDGER-EXACT-V1 freezes the exact nine-field bounded payload and preallocated artifact identity'
);
archive_check(
	! array_key_exists( 'ledger_artifact_id', $packet_payload )
	&& array_keys( $packet_payload ) === array( 'archive_id', 'build_attempt_id', 'canonical_format_version', 'snapshot_id', 'stream_id', 'task_schema_version', 'task_type', 'trigger_event_id' ),
	'P3B1-DEFERRED-PAYLOADS-UNCHANGED preserves the packet v1 task bytes'
);

$before_capture = $capture;
$before_packet = $packet;
$claimed = $stack['task_store']->claim_available(
	$scenario->id( 'claim-owner' ), $scenario->id( 'claim-token' ), '2026-07-24T12:00:01Z', array( 'materialize_ledger' )
);
$after_capture = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$task_table} WHERE task_id = %s", $capture['task_id'] ), ARRAY_A );
$after_packet = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$task_table} WHERE task_id = %s", $packet['task_id'] ), ARRAY_A );
archive_check(
	$claimed['task_id'] === $ledger['task_id'] && $before_capture === $after_capture && $before_packet === $after_packet,
	'P3B1-INSTALLED-TYPE-AVAILABLE-CLAIM leaves earlier capture/packet rows byte-for-byte untouched'
);

foreach ( array( array(), array( 'future_task' ), array( 123 ) ) as $invalid_allowlist ) {
	$before = ghca_persist_db_fingerprint( $wpdb );
	p3b_contract_expect_failure( static function () use ( $stack, $scenario, $invalid_allowlist ) {
		$stack['task_store']->claim_available( $scenario->id( 'bad-owner' ), $scenario->id( 'bad-token' ), '2026-07-24T12:00:02Z', $invalid_allowlist );
	}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_type_unsupported', 'P3B1-INSTALLED-TYPE-FILTER-INVALID' );
	archive_check( $before === ghca_persist_db_fingerprint( $wpdb ), 'P3B1-INSTALLED-TYPE-FILTER-INVALID fails before transaction residue' );
}

$contract_negative_fingerprint = ghca_persist_db_fingerprint( $wpdb );
$claimed['payload'] = $ledger_payload;
$bad_payload = $ledger_payload;
$bad_payload['extra'] = 'forbidden';
$bad_row = p3b_contract_row( $claimed, $bad_payload );
p3b_contract_expect_failure( static function () use ( $stack, $bad_row ) {
	$stack['task_store']->validate_claimed_v1( $bad_row );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_payload_invalid', 'P3B1-PAYLOAD-LEDGER-EXTRA-FIELD' );

foreach ( GHCA_ACD_Archive_Task_Catalog::LEDGER_PAYLOAD_FIELDS as $field ) {
	$missing = $ledger_payload;
	unset( $missing[ $field ] );
	$missing_row = p3b_contract_row( $claimed, $missing );
	p3b_contract_expect_failure( static function () use ( $stack, $missing_row ) {
		$stack['task_store']->validate_claimed_v1( $missing_row );
	}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_payload_invalid', 'P3B1-PAYLOAD-LEDGER-MISSING-' . strtoupper( $field ) );
}

$wrong_types = array(
	'archive_id' => 1, 'build_attempt_id' => 1, 'canonical_format_version' => 1,
	'ledger_artifact_id' => 1, 'snapshot_id' => 1, 'stream_id' => 1,
	'task_schema_version' => '1', 'task_type' => array( 'materialize_ledger' ), 'trigger_event_id' => 1,
);
foreach ( $wrong_types as $field => $value ) {
	$wrong = $ledger_payload;
	$wrong[ $field ] = $value;
	$wrong_row = p3b_contract_row( $claimed, $wrong );
	p3b_contract_expect_failure( static function () use ( $stack, $wrong_row ) {
		$stack['task_store']->validate_claimed_v1( $wrong_row );
	}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_payload_invalid', 'P3B1-PAYLOAD-LEDGER-WRONG-TYPE-' . strtoupper( $field ) );
}

foreach ( array( 'archive_id', 'build_attempt_id', 'ledger_artifact_id', 'snapshot_id', 'stream_id', 'trigger_event_id' ) as $field ) {
	foreach ( array( 'A' . substr( $ledger_payload[ $field ], 1 ), substr( $ledger_payload[ $field ], 1 ) ) as $index => $bad_id ) {
		$invalid_id = $ledger_payload;
		$invalid_id[ $field ] = $bad_id;
		$invalid_row = p3b_contract_row( $claimed, $invalid_id );
		p3b_contract_expect_failure( static function () use ( $stack, $invalid_row ) {
			$stack['task_store']->validate_claimed_v1( $invalid_row );
		}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_payload_invalid', 'P3B1-PAYLOAD-LEDGER-' . ( 0 === $index ? 'UPPERCASE-' : 'WRONG-LENGTH-' ) . strtoupper( $field ) );
	}
}

$null_payload = $ledger_payload;
$null_payload['snapshot_id'] = null;
$null_row = p3b_contract_row( $claimed, $null_payload );
p3b_contract_expect_failure( static function () use ( $stack, $null_row ) {
	$stack['task_store']->validate_claimed_v1( $null_row );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_payload_invalid', 'P3B1-PAYLOAD-LEDGER-NULL-REJECTED' );

$binding_row = $claimed;
$binding_row['archive_id'] = str_repeat( 'f', 32 );
p3b_contract_expect_failure( static function () use ( $stack, $binding_row ) {
	$stack['task_store']->validate_claimed_v1( $binding_row );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_payload_invalid', 'P3B1-PAYLOAD-LEDGER-ROW-BINDING' );

$trigger_kind_row = $claimed;
$trigger_kind_row['trigger_kind'] = 'command';
p3b_contract_expect_failure( static function () use ( $stack, $trigger_kind_row ) {
	$stack['task_store']->validate_claimed_v1( $trigger_kind_row );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_payload_invalid', 'P3B1-PAYLOAD-LEDGER-TRIGGER-KIND' );

$noncanonical_row = $claimed;
$noncanonical_row['payload_json'] .= ' ';
p3b_contract_expect_failure( static function () use ( $stack, $noncanonical_row ) {
	$stack['task_store']->validate_claimed_v1( $noncanonical_row );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_payload_invalid', 'P3B1-PAYLOAD-LEDGER-NONCANONICAL' );

$deep_payload = $ledger_payload;
$deep_payload['extra'] = array( 'nested' => array( 'forbidden' ) );
p3b_contract_expect_failure( static function () use ( $claimed, $deep_payload ) {
	GHCA_ACD_Archive_Task_Catalog::validate_ledger_payload( $claimed, $deep_payload );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_payload_invalid', 'P3B1-PAYLOAD-LEDGER-DEPTH' );

$many_payload = $ledger_payload;
$many_payload['extra'] = array_fill( 0, GHCA_ACD_Archive_Canonical_JSON::MAX_VALUES + 1, 1 );
p3b_contract_expect_failure( static function () use ( $claimed, $many_payload ) {
	GHCA_ACD_Archive_Task_Catalog::validate_ledger_payload( $claimed, $many_payload );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_payload_invalid', 'P3B1-PAYLOAD-LEDGER-VALUE-COUNT' );

$oversized_payload = $ledger_payload;
$oversized_payload['extra'] = str_repeat( 'x', GHCA_ACD_Archive_Task_Catalog::LEDGER_PAYLOAD_MAX_BYTES );
p3b_contract_expect_failure( static function () use ( $claimed, $oversized_payload ) {
	GHCA_ACD_Archive_Task_Catalog::validate_ledger_payload( $claimed, $oversized_payload );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'task_payload_invalid', 'P3B1-PAYLOAD-LEDGER-512-BYTE-BOUNDARY' );
archive_check( $contract_negative_fingerprint === ghca_persist_db_fingerprint( $wpdb ), 'P3B1-PAYLOAD-NEGATIVE-CASES leave zero aggregate database residue' );

ghca_persist_fresh_schema( $wpdb );
$stack = ghca_persist_stack( $wpdb, '2026-07-24T13:00:00Z', 'p3b-reclaim' );
$scenario = new GHCA_Persist_Scenario( 'p3b_reclaim' );
persist_request_archive( $stack, $scenario );
persist_start_build( $stack, $scenario );
persist_record_snapshot( $stack, $scenario );
$capture = $stack['task_store']->claim_available( $scenario->id( 'capture-owner' ), $scenario->id( 'capture-token' ), '2026-07-24T13:00:00Z', array( 'capture_evidence' ) );
$packet = $stack['task_store']->claim_available( $scenario->id( 'packet-owner' ), $scenario->id( 'packet-token' ), '2026-07-24T13:00:00Z', array( 'materialize_packet' ) );
$ledger = $stack['task_store']->claim_available( $scenario->id( 'ledger-owner' ), $scenario->id( 'ledger-token' ), '2026-07-24T13:00:00Z', array( 'materialize_ledger' ) );
$capture_before = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$task_table} WHERE task_id = %s", $capture['task_id'] ), ARRAY_A );
$packet_before = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$task_table} WHERE task_id = %s", $packet['task_id'] ), ARRAY_A );
$reclaimed = $stack['task_store']->reclaim_expired( $scenario->id( 'new-owner' ), $scenario->id( 'new-token' ), '2026-07-24T13:02:00Z', array( 'materialize_ledger' ) );
$capture_after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$task_table} WHERE task_id = %s", $capture['task_id'] ), ARRAY_A );
$packet_after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$task_table} WHERE task_id = %s", $packet['task_id'] ), ARRAY_A );
archive_check(
	$reclaimed['task_id'] === $ledger['task_id'] && $capture_before === $capture_after && $packet_before === $packet_after,
	'P3B1-INSTALLED-TYPE-EXPIRED-RECLAIM leaves earlier expired capture/packet leases untouched'
);

ghca_persist_fresh_schema( $wpdb );
$stack = ghca_persist_stack( $wpdb, '2026-07-24T14:00:00Z', 'p3b-retry-deferred' );
$scenario = new GHCA_Persist_Scenario( 'p3b_retry_deferred' );
persist_request_archive( $stack, $scenario );
persist_start_build( $stack, $scenario );
persist_record_snapshot( $stack, $scenario );
$failure = $scenario->payload( 'ArchiveFailed', array(
	'archive_id' => $scenario->id( 'archive-1' ), 'build_attempt_id' => $scenario->id( 'attempt-1' ),
	'candidate_artifact_ids' => array(), 'failure_code' => 'archive_ledger_invalid',
	'phase' => 'materializing', 'retryable' => false, 'sealed_snapshot_id' => $scenario->id( 'snapshot-1' ),
) );
persist_single( $stack, $scenario, 'FailArchive', 'fail_archive', $failure );
$ledger_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$task_table} WHERE task_type = 'materialize_ledger'" );
$packet_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$task_table} WHERE task_type = 'materialize_packet'" );
$retry = $scenario->payload( 'ArchiveRetryRequested', array(
	'archive_id' => $scenario->id( 'archive-1' ), 'new_build_attempt_id' => $scenario->id( 'attempt-2' ),
	'prior_build_attempt_id' => $scenario->id( 'attempt-1' ), 'resume_phase' => 'materializing',
	'sealed_snapshot_id' => $scenario->id( 'snapshot-1' ),
) );
persist_single( $stack, $scenario, 'RetryArchive', 'request_retry', $retry );
$ledger_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$task_table} WHERE task_type = 'materialize_ledger'" );
$packet_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$task_table} WHERE task_type = 'materialize_packet'" );
archive_check(
	$ledger_before === $ledger_after && $packet_after === $packet_before + 1,
	'P3B1-LIFECYCLE-RETRY-DEFERRED does not enqueue an installed ledger task from ArchiveRetryRequested'
);

archive_finish();
