<?php
require __DIR__ . '/bootstrap.php';

$cycle = new GHCA_ACD_Archive_Cycle(
	'calendar_year',
	1,
	'2026-01-01T05:00:00Z',
	'2027-01-01T05:00:00Z',
	'America/Toronto',
	'2026'
);
$cycle_key = 'v1|calendar_year|1|2026-01-01T05:00:00Z|2027-01-01T05:00:00Z|America/Toronto|[)';
archive_check( $cycle->key() === $cycle_key, 'cycle key freezes policy, UTC bounds, IANA timezone, and [) inclusivity' );

$case_key = new GHCA_ACD_Archive_Case_Key( '0123456789abcdef0123456789abcdef', '1', '42', 'annual', $cycle );
archive_check( $case_key->digest() === '919395ff4c61e9b7596c4a852c7d59ee3aa35ae6e0c1119c0964fb4ffcf7aac9', 'case key object matches frozen ghca-case-key-v1 vector' );

$actor = new GHCA_ACD_Archive_Actor( 'wp_user', '7', '7', 'test', 'archive_create', remediation_authority_context() );
archive_check( $actor->canonical()['actor_user_id'] === '7', 'actor preserves authenticated and initiating identities' );

$scope_a = new GHCA_ACD_Archive_Reset_Scope( '42', 'annual', $cycle_key, array( '20', '10' ) );
$scope_b = new GHCA_ACD_Archive_Reset_Scope( '42', 'annual', $cycle_key, array( '10', '20' ) );
archive_check( $scope_a->canonical() === $scope_b->canonical(), 'reset scope normalizes course identity order' );
archive_check( $scope_a->digest() === $scope_b->digest(), 'reset scope digest is deterministic' );

$command = GHCA_ACD_Archive_Command::request_archive(
	str_repeat( 'c', 32 ), str_repeat( 'd', 64 ), str_repeat( 'e', 64 ), '0', $actor,
	array( 'case_key' => remediation_case_key(), 'request_kind' => 'initial' ),
	array( 'archive_id' => remediation_id( 'a' ), 'policy_digest' => remediation_digest( '2' ), 'resolved_cycle' => remediation_cycle(), 'reviewed_source_fingerprint' => remediation_digest( '1' ), 'revision_number' => 1, 'subject_scope_digest' => remediation_scope_digest() )
);
archive_check( 64 === strlen( $command->digest() ), 'command creates a versioned canonical digest' );
archive_check( $command->expected_sequence() === '0', 'command preserves expected sequence as a canonical unsigned decimal string' );
$conflicting_command = GHCA_ACD_Archive_Command::request_archive(
	str_repeat( 'c', 32 ), str_repeat( 'd', 64 ), str_repeat( 'e', 64 ), '0', $actor,
	array( 'case_key' => array_replace( remediation_case_key(), array( 'program_key' => 'orientation' ) ), 'request_kind' => 'initial' ),
	array( 'archive_id' => remediation_id( 'a' ), 'policy_digest' => remediation_digest( '2' ), 'resolved_cycle' => remediation_cycle(), 'reviewed_source_fingerprint' => remediation_digest( '1' ), 'revision_number' => 1, 'subject_scope_digest' => remediation_scope_digest() )
);
archive_check( $command->digest() !== $conflicting_command->digest() && $command->client_intent_digest() !== $conflicting_command->client_intent_digest(), 'AC-25 same idempotency identity with different intent has a different command digest' );

// T7: expected_sequence is a canonical unsigned decimal string spanning the full
// BIGINT UNSIGNED range, not a PHP signed integer.
function t7_request_command( $expected_sequence ): GHCA_ACD_Archive_Command {
	global $actor;
	return GHCA_ACD_Archive_Command::request_archive(
		str_repeat( 'c', 32 ), str_repeat( 'd', 64 ), str_repeat( 'e', 64 ), $expected_sequence, $actor,
		array( 'case_key' => remediation_case_key(), 'request_kind' => 'initial' ),
		array( 'archive_id' => remediation_id( 'a' ), 'policy_digest' => remediation_digest( '2' ), 'resolved_cycle' => remediation_cycle(), 'reviewed_source_fingerprint' => remediation_digest( '1' ), 'revision_number' => 1, 'subject_scope_digest' => remediation_scope_digest() )
	);
}
archive_check( '0' === t7_request_command( '0' )->expected_sequence(), 'T7-SEQ-ZERO accepts a zero expected sequence' );
archive_check( '9223372036854775808' === t7_request_command( '9223372036854775808' )->expected_sequence(), 'T7-SEQ-ABOVE-INT-MAX accepts a value just above PHP_INT_MAX' );
$t7_max = t7_request_command( '18446744073709551615' );
archive_check( '18446744073709551615' === $t7_max->expected_sequence(), 'T7-SEQ-MAX-UNSIGNED accepts the exact BIGINT UNSIGNED maximum' );
archive_check( false !== strpos( GHCA_ACD_Archive_Canonical_JSON::encode( $t7_max->canonical() ), '"expected_sequence":"18446744073709551615"' ), 'T7-SEQ-CANONICAL the unsigned maximum serializes as an exact decimal string in the command document' );
foreach ( array(
	'T7-SEQ-NEGATIVE rejects a negative sequence'          => '-1',
	'T7-SEQ-OVERFLOW rejects one above BIGINT UNSIGNED'    => '18446744073709551616',
	'T7-SEQ-LEADING-ZERO rejects a leading-zero variant'   => '07',
	'T7-SEQ-FLOAT rejects a decimal/float string'          => '7.0',
	'T7-SEQ-EMPTY rejects an empty sequence'               => '',
) as $t7_message => $t7_bad ) {
	archive_expect_exception( static function () use ( $t7_bad ): void {
		t7_request_command( $t7_bad );
	}, $t7_message, InvalidArgumentException::class );
}
foreach ( array(
	'T7-SEQ-ACTUAL-FLOAT rejects a PHP float before coercion' => 7.0,
	'T7-SEQ-INTEGER rejects a PHP integer before coercion'    => 7,
	'T7-SEQ-BOOLEAN rejects a PHP boolean before coercion'    => true,
	'T7-SEQ-NULL rejects null before coercion'                => null,
) as $t7_message => $t7_bad_type ) {
	archive_expect_exception( static function () use ( $t7_bad_type ): void {
		t7_request_command( $t7_bad_type );
	}, $t7_message, InvalidArgumentException::class );
}
archive_expect_exception( static function (): void {
	// Passing the unsigned maximum as a PHP numeric literal overflows to a float
	// and stringifies to scientific notation: a lossy cast that must be rejected.
	t7_request_command( 18446744073709551615 );
}, 'T7-SEQ-LOSSY-CAST rejects a PHP numeric literal that cannot represent the value exactly', InvalidArgumentException::class );

$payload = archive_event_payload( 'ArchiveRequested' );
$event = new GHCA_ACD_Archive_Event( 'ArchiveRequested', 1, $payload, array( 'decision_index' => 0, 'decision_size' => 1 ) );
archive_check( $event->type() === 'ArchiveRequested' && $event->payload() === $payload, 'event validates and preserves its immutable payload' );

archive_expect_exception( static function (): void {
	new GHCA_ACD_Archive_Cycle( 'calendar_year', 1, '2027-01-01T00:00:00Z', '2026-01-01T00:00:00Z', 'UTC', 'bad' );
}, 'cycle rejects end at or before start', InvalidArgumentException::class );

archive_expect_exception( static function () use ( $cycle ): void {
	new GHCA_ACD_Archive_Case_Key( 'not-a-tenant', '1', '42', 'annual', $cycle );
}, 'case key rejects malformed tenant identity', InvalidArgumentException::class );

archive_expect_exception( static function (): void {
	new GHCA_ACD_Archive_Actor( 'wp_user', null, '7', 'test', 'archive_create', array() );
}, 'wp_user actor requires an authenticated user ID', InvalidArgumentException::class );

$signed_boundary_actor = new GHCA_ACD_Archive_Actor( 'wp_user', '9223372036854775808', '7', 'test', 'archive_create', remediation_authority_context() );
archive_check( '9223372036854775808' === $signed_boundary_actor->canonical()['actor_user_id'], 'BIGINT-BOUNDARY-01 decimal identity just above PHP_INT_MAX is a valid BIGINT UNSIGNED value' );
$max_unsigned_actor = new GHCA_ACD_Archive_Actor( 'wp_user', '18446744073709551615', '7', 'test', 'archive_create', remediation_authority_context() );
archive_check( '18446744073709551615' === $max_unsigned_actor->canonical()['actor_user_id'], 'BIGINT-BOUNDARY-02 decimal identity accepts the exact BIGINT UNSIGNED maximum' );
archive_expect_exception( static function (): void {
	new GHCA_ACD_Archive_Actor( 'wp_user', '18446744073709551616', '7', 'test', 'archive_create', remediation_authority_context() );
}, 'BIGINT-BOUNDARY-03 decimal identity rejects BIGINT UNSIGNED maximum plus one', InvalidArgumentException::class );
$boundary_scope = new GHCA_ACD_Archive_Reset_Scope( '42', 'annual', $cycle_key, array( '18446744073709551615', '9223372036854775808' ) );
archive_check( array( '9223372036854775808', '18446744073709551615' ) === $boundary_scope->canonical()['course_ids'], 'BIGINT-BOUNDARY-04 reset scope accepts and orders unsigned 64-bit course identities' );
archive_expect_exception( static function () use ( $cycle_key ): void {
	new GHCA_ACD_Archive_Reset_Scope( '42', 'annual', $cycle_key, array( '18446744073709551616' ) );
}, 'BIGINT-BOUNDARY-05 reset scope rejects a course identity above BIGINT UNSIGNED', InvalidArgumentException::class );

$anniversary_cycle = new GHCA_ACD_Archive_Cycle( 'employee_start_date', 1, '2026-03-15T04:00:00Z', '2027-03-15T04:00:00Z', 'America/Toronto', '2026 anniversary' );
archive_check( 0 === strpos( $anniversary_cycle->key(), 'v1|employee_start_date|1|' ), 'CYCLE-POLICY-01 approved employee_start_date policy is accepted' );
archive_expect_exception( static function (): void {
	new GHCA_ACD_Archive_Cycle( 'fixed_365', 1, '2026-01-01T05:00:00Z', '2027-01-01T05:00:00Z', 'America/Toronto', 'fixed window' );
}, 'CYCLE-POLICY-02 the dashboard fixed-365 policy cannot become archive case identity', InvalidArgumentException::class );
archive_expect_exception( static function (): void {
	new GHCA_ACD_Archive_Cycle( 'wordpress_resolver_future', 1, '2026-01-01T05:00:00Z', '2027-01-01T05:00:00Z', 'America/Toronto', 'future policy' );
}, 'CYCLE-POLICY-03 unapproved future policy names fail closed before becoming identity', InvalidArgumentException::class );

archive_expect_exception( static function () use ( $actor ): void {
	GHCA_ACD_Archive_Command::request_archive( str_repeat( 'c', 32 ), 'bad', str_repeat( 'e', 64 ), '0', $actor, array(), array() );
}, 'command rejects malformed idempotency identity', InvalidArgumentException::class );

archive_expect_exception( static function (): void {
	new GHCA_ACD_Archive_Event( 'StatusUpdated', 1, array(), array( 'decision_index' => 0, 'decision_size' => 1 ) );
}, 'generic/unknown event cannot be constructed', InvalidArgumentException::class );

archive_finish();
