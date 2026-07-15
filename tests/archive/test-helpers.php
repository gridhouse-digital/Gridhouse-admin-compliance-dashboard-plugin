<?php

$archive_test_failures = 0;
$archive_test_checks   = 0;

error_reporting( E_ALL );
set_error_handler( static function ( int $severity, string $message, string $file, int $line ): bool {
	if ( 0 === ( error_reporting() & $severity ) ) {
		return false;
	}
	throw new ErrorException( $message, 0, $severity, $file, $line );
} );

function archive_check( bool $condition, string $message ): void {
	global $archive_test_failures, $archive_test_checks;
	$archive_test_checks++;
	if ( $condition ) {
		echo "PASS: {$message}\n";
		return;
	}
	$archive_test_failures++;
	echo "FAIL: {$message}\n";
}

function archive_expect_exception( callable $callback, string $message, string $expected_class, ?string $expected_reason_code = null ): void {
	if ( Throwable::class === $expected_class || Exception::class === $expected_class || Error::class === $expected_class ) {
		archive_check( false, $message . ' (negative tests must name one exact expected exception class)' );
		return;
	}
	try {
		$callback();
		archive_check( false, $message );
	} catch ( Throwable $error ) {
		$class_matches  = get_class( $error ) === $expected_class;
		$reason_matches = true;
		if ( null !== $expected_reason_code ) {
			$reason_matches = $error instanceof GHCA_ACD_Archive_Transition_Exception && $error->reason_code() === $expected_reason_code;
		}
		archive_check( $class_matches && $reason_matches, $message . ( $class_matches ? '' : ' [caught ' . get_class( $error ) . ': ' . $error->getMessage() . ']' ) );
	}
}

function archive_expect_transition_rejection( GHCA_ACD_Archive_Case $case, callable $callback, string $reason_code, string $message ): void {
	$before_state = GHCA_ACD_Archive_Canonical_JSON::encode( $case->state() );
	$before_count = count( $case->uncommitted_events() );
	$caught = null;
	try {
		$callback();
	} catch ( Throwable $error ) {
		$caught = $error;
	}
	archive_check( $caught instanceof GHCA_ACD_Archive_Transition_Exception, $message . ' uses the transition exception' );
	archive_check( $caught instanceof GHCA_ACD_Archive_Transition_Exception && $reason_code === $caught->reason_code(), $message . ' exposes reason ' . $reason_code );
	archive_check( $before_state === GHCA_ACD_Archive_Canonical_JSON::encode( $case->state() ), $message . ' leaves aggregate state unchanged' );
	archive_check( $before_count === count( $case->uncommitted_events() ), $message . ' leaves uncommitted events unchanged' );
}

function archive_finish(): void {
	global $archive_test_failures, $archive_test_checks;
	if ( 0 === $archive_test_failures ) {
		echo "\nALL PASS ({$archive_test_checks} checks)\n";
		exit( 0 );
	}
	echo "\n{$archive_test_failures} FAILED of {$archive_test_checks}\n";
	exit( 1 );
}

/** @return mixed */
function archive_fixture_value( string $field, string $rule ) {
	if ( 'int' === $rule ) {
		return 1;
	}
	if ( 'bool' === $rule ) {
		return true;
	}
	if ( 'string_list' === $rule ) {
		if ( false !== strpos( $field, 'digest' ) || false !== strpos( $field, 'fingerprint' ) || false !== strpos( $field, 'proof' ) ) {
			return array( str_repeat( 'b', 64 ) );
		}
		if ( false !== strpos( $field, '_ids' ) ) {
			return array( str_repeat( 'a', 32 ) );
		}
		return array( 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' );
	}
	if ( 'object' === $rule ) {
		return array( 'key' => 'value' );
	}
	if ( 'nullable_string' === $rule ) {
		return null;
	}
	if ( false !== strpos( $field, '_at_gmt' ) || false !== strpos( $field, 'deadline_gmt' ) || false !== strpos( $field, 'expires_at_gmt' ) ) {
		return '2026-07-13T12:00:00Z';
	}
	if ( false !== strpos( $field, 'fingerprint' ) || false !== strpos( $field, 'digest' ) || false !== strpos( $field, 'proof' ) ) {
		return str_repeat( 'b', 64 );
	}
	if ( false !== strpos( $field, '_id' ) ) {
		return str_repeat( 'a', 32 );
	}
	return 'test-value';
}

/** @param array<string,mixed> $overrides @return array<string,mixed> */
function archive_event_payload( string $event_type, array $overrides = array() ): array {
	$payload = remediation_payload( $event_type );
	foreach ( $overrides as $field => $value ) {
		$payload[ $field ] = $value;
	}
	return $payload;
}
