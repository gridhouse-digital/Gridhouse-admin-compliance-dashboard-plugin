<?php
require __DIR__ . '/bootstrap.php';

$a64 = str_repeat( 'a', 64 );
$b64 = str_repeat( 'b', 64 );

archive_check(
	GHCA_ACD_Archive_Digester::case_key(
		'0123456789abcdef0123456789abcdef',
		'1',
		'42',
		'annual',
		'v1|calendar_year|1|2026-01-01T05:00:00Z|2027-01-01T05:00:00Z|America/Toronto|[)'
	) === '919395ff4c61e9b7596c4a852c7d59ee3aa35ae6e0c1119c0964fb4ffcf7aac9',
	'ghca-case-key-v1 golden vector'
);

archive_check(
	GHCA_ACD_Archive_Digester::idempotency( $b64, $a64 ) === '2878f4ea8e787189fc9d890b3d483b9b3c9db6bc376b13b68b8ab92cb2e1e0d6',
	'ghca-idempotency-v1 golden vector'
);

// Independent vectors: expected bytes are literal in-test strings hashed with
// PHP's hash() directly, never produced by the production digester/encoder.
$case_key_literal = '{"cycle_key":"v1|calendar_year|1|2026-01-01T05:00:00Z|2027-01-01T05:00:00Z|America/Toronto|[)","employee_user_id_decimal":"42","program_key":"annual","site_id_decimal":"1","tenant_id":"0123456789abcdef0123456789abcdef"}';
archive_check(
	'919395ff4c61e9b7596c4a852c7d59ee3aa35ae6e0c1119c0964fb4ffcf7aac9' === hash( 'sha256', "ghca-case-key-v1\n" . $case_key_literal ),
	'GOLDEN-CASE-INDEPENDENT frozen ghca-case-key-v1 vector reproduces from literal bytes without the production digester'
);
$reset_scope_literal = '{"course_ids":["10","20"],"cycle_key":"v1|calendar_year|1|2026-01-01T05:00:00Z|2027-01-01T05:00:00Z|America/Toronto|[)","employee_user_id_decimal":"42","program_key":"annual"}';
archive_check(
	'0d496fcaba7e6ed68c3c20e9fdb510d5e1f97d49fb9e5f8fa7291ef2410a874c' === hash( 'sha256', "ghca-reset-scope-v1\n" . $reset_scope_literal ),
	'GOLDEN-SCOPE-INDEPENDENT frozen ghca-reset-scope-v1 vector reproduces from literal bytes without the production digester'
);
$reset_scope_object = new GHCA_ACD_Archive_Reset_Scope(
	'42',
	'annual',
	'v1|calendar_year|1|2026-01-01T05:00:00Z|2027-01-01T05:00:00Z|America/Toronto|[)',
	array( '20', '10' )
);
archive_check(
	'0d496fcaba7e6ed68c3c20e9fdb510d5e1f97d49fb9e5f8fa7291ef2410a874c' === $reset_scope_object->digest(),
	'GOLDEN-SCOPE-PRODUCTION production reset-scope digest matches the independent frozen vector'
);

$actor = new GHCA_ACD_Archive_Actor( 'wp_user', '7', '7', 'test', 'archive_create', remediation_authority_context() );
$command = GHCA_ACD_Archive_Command::request_archive(
	remediation_id( '1' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '0', $actor,
	array( 'case_key' => remediation_case_key(), 'request_kind' => 'initial' ),
	array( 'archive_id' => remediation_id( 'a' ), 'policy_digest' => remediation_digest( '2' ), 'resolved_cycle' => remediation_cycle(), 'reviewed_source_fingerprint' => remediation_digest( '1' ), 'revision_number' => 1, 'subject_scope_digest' => remediation_scope_digest() )
);
// Deliberately refrozen for T1: the archive subject_scope_digest (and the actor
// authority scope it binds to) is now the exact reset-scope digest of the
// authorized course set, so the accepted RequestArchive command document changed.
archive_check( GHCA_ACD_Archive_Digester::command( $command->canonical() ) === '361dd1a6b3ec8e02756ae2b7ea8b85d5605b043301038520d33ebe300a123f92', 'real RequestArchive ghca-command-v1 golden vector' );

$source = array( 'course_ids' => array( '10', '20' ), 'policy_digest' => $b64, 'user_id' => '42' );
archive_check( GHCA_ACD_Archive_Digester::source_fingerprint( $source ) === '9462a3dc569182c1e44d5fa354a90523d542ce968d7999d746e34db87733499d', 'ghca-source-fingerprint-v1 golden vector' );

$snapshot = array( 'captured_at_gmt' => '2026-07-13T12:00:00Z', 'courses' => array(), 'schema_version' => 1 );
archive_check( GHCA_ACD_Archive_Digester::snapshot( $snapshot ) === '320bee9b2e28cddbeed81d63567e4eb1b8272659d8ff88e64d51e197323acd5d', 'ghca-snapshot-v1 golden vector' );

$item = array( 'completion_status' => 'completed', 'course_id' => '10', 'item_ordinal' => 0 );
archive_check( GHCA_ACD_Archive_Digester::item( $item ) === '9068e030ad612bab1712a5f12725d30f4b83511a30c1bb737b9d8c09e8f71131', 'ghca-item-v1 golden vector' );

$event = array(
	'actor_kind'                 => 'system',
	'actor_user_id'              => null,
	'archive_id'                 => str_repeat( 'a', 32 ),
	'authority_code'             => 'archive_create',
	'authority_context'          => array( 'scope' => 'site:1' ),
	'build_attempt_id'           => null,
	'canonical_format_version'   => 1,
	'case_key_digest'            => $b64,
	'case_key_format_version'    => 1,
	'causation_event_id'         => null,
	'command_digest'             => str_repeat( 'c', 64 ),
	'command_id'                 => str_repeat( 'd', 32 ),
	'correlation_id'             => str_repeat( 'e', 32 ),
	'effective_at_gmt'           => null,
	'event_id'                   => str_repeat( 'f', 32 ),
	'event_schema_version'       => 1,
	'event_type'                 => 'ArchiveRequested',
	'idempotency_key_digest'     => $a64,
	'idempotency_scope_digest'   => $b64,
	'initiating_user_id'         => '7',
	'metadata'                   => array( 'source' => 'test' ),
	'occurred_at_gmt'            => '2026-07-13T12:00:00Z',
	'payload'                    => array( 'payload_schema_version' => 1 ),
	'previous_event_digest'      => null,
	'reason_code'                => null,
	'reason_text'                => null,
	'recorded_at_gmt'            => '2026-07-13T12:00:00Z',
	'reset_operation_id'         => null,
	'source_channel'             => 'test',
	'stream_id'                  => str_repeat( '1', 32 ),
	'stream_sequence'            => '1',
	'upstream_operation_id'      => null,
);

$event_hash = GHCA_ACD_Archive_Digester::event_hash( $event );
archive_check( $event_hash === '0a03ff0a6a9300ff67552b6236a3a3800e88f776e5eca0a65b95d0f650494d88', 'ghca-event-hash-v1 golden vector' );

$tampered = $event;
$tampered['actor_kind'] = 'worker';
archive_check( ! GHCA_ACD_Archive_Digester::verify_event_hash( $tampered, $event_hash ), 'actor tampering breaks event verification' );

$tampered = $event;
$tampered['payload']['payload_schema_version'] = 2;
archive_check( ! GHCA_ACD_Archive_Digester::verify_event_hash( $tampered, $event_hash ), 'payload tampering breaks event verification' );

$tampered = $event;
$tampered['previous_event_digest'] = $a64;
archive_check( ! GHCA_ACD_Archive_Digester::verify_event_hash( $tampered, $event_hash ), 'predecessor tampering breaks event verification' );

$tamper_cases = array(
	'event envelope'  => array( 'event_id', str_repeat( '0', 32 ) ),
	'metadata'        => array( 'metadata', array( 'source' => 'tampered' ) ),
	'stream sequence' => array( 'stream_sequence', '2' ),
	'case key'        => array( 'case_key_digest', str_repeat( '0', 64 ) ),
	'event type'      => array( 'event_type', 'ArchiveFailed' ),
	'recorded time'   => array( 'recorded_at_gmt', '2026-07-13T12:00:01Z' ),
);
foreach ( $tamper_cases as $label => $change ) {
	$tampered = $event;
	$tampered[ $change[0] ] = $change[1];
	archive_check( ! GHCA_ACD_Archive_Digester::verify_event_hash( $tampered, $event_hash ), $label . ' tampering breaks event verification' );
}

archive_expect_exception( static function () use ( $event ): void {
	$event['event_digest'] = str_repeat( '0', 64 );
	GHCA_ACD_Archive_Digester::event_hash( $event );
}, 'event hash rejects unexpected envelope fields', InvalidArgumentException::class );

archive_finish();
