<?php

require __DIR__ . '/persistence-bootstrap.php';
require __DIR__ . '/persistence-fixtures.php';

$signal = isset( $argv[1] ) ? $argv[1] : '';
$program = isset( $argv[2] ) ? $argv[2] : '';
$idempotency_key = isset( $argv[3] ) ? $argv[3] : '';

try {
	if ( '' === $signal || '' === $program || '' === $idempotency_key ) {
		throw new RuntimeException( 'race worker arguments are incomplete' );
	}
	$deadline = microtime( true ) + 10.0;
	while ( ! file_exists( $signal ) && microtime( true ) < $deadline ) {
		usleep( 10000 );
	}
	if ( ! file_exists( $signal ) ) {
		throw new RuntimeException( 'race worker signal timed out' );
	}
	global $wpdb;
	ghca_persist_query( $wpdb, 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ', 'race worker isolation' );
	$stack = ghca_persist_stack( $wpdb, '2026-07-16T12:15:00Z', 'rr-worker' );
	$scenario = new GHCA_Persist_Scenario( $program );
	$response = persist_request_archive( $stack, $scenario, array(
		'idempotency_key' => $idempotency_key,
		'no_track'        => true,
	) );
	echo 'RACE_RESPONSE=' . base64_encode( GHCA_ACD_Archive_Canonical_JSON::encode( $response ) ) . PHP_EOL;
	exit( 0 );
} catch ( Throwable $error ) {
	$reason = $error instanceof GHCA_ACD_Archive_Persistence_Exception ? $error->reason_code() : get_class( $error );
	fwrite( STDERR, 'RACE_FAILURE=' . $reason . PHP_EOL );
	exit( 1 );
}
