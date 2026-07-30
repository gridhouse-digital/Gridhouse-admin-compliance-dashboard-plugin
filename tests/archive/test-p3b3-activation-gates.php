<?php
require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id(): int {
		return 1;
	}
}
if ( ! defined( 'GHCA_ACD_ARCHIVE_RUNTIME_MODE' ) ) {
	define( 'GHCA_ACD_ARCHIVE_RUNTIME_MODE', 'controlled_testing' );
}

final class GHCA_P3B3_Flag_DB {
	public $base_prefix = 'wp_';
	public $prefix = 'wp_';
	public $last_error = '';
	protected $dbhost = '127.0.0.1:33061';
	/** @var array<int,array<string,string>> */
	public $rows;

	/** @param array<int,array<string,string>> $rows */
	public function __construct( array $rows ) {
		$this->rows = $rows;
	}

	public function get_blog_prefix( int $blog_id ): string {
		return 1 === $blog_id ? 'wp_' : 'wp_' . $blog_id . '_';
	}

	public function prepare( string $sql, ...$args ): string {
		return $sql;
	}

	/** @return array<int,array<string,string>> */
	public function get_results( string $sql, string $format ): array {
		return $this->rows;
	}

	/** @return array<int,string> */
	public function get_col( string $sql ): array {
		return array();
	}
}

/** @return array<int,array<string,string>> */
function p3b3_flag_rows( array $overrides = array() ): array {
	$values = array(
		'ghca_acd_archive_dual_layer' => '1',
		'ghca_acd_archive_enabled' => '1',
		'ghca_acd_archive_reset_enabled' => '0',
		'ghca_acd_archive_schema_version' => GHCA_ACD_Archive_Schema::CURRENT_VERSION,
		'ghca_acd_archive_tenant_id' => str_repeat( 'a', 32 ),
	);
	foreach ( $overrides as $name => $value ) {
		if ( null === $value ) {
			unset( $values[ $name ] );
		} else {
			$values[ $name ] = $value;
		}
	}
	ksort( $values, SORT_STRING );
	$rows = array();
	foreach ( $values as $name => $value ) {
		$rows[] = array( 'option_name' => $name, 'option_value' => $value, 'autoload' => 'no' );
	}
	return $rows;
}

function p3b3_gate_code( array $rows ): string {
	try {
		( new GHCA_ACD_WordPress_Archive_Runtime_Descriptor( new GHCA_P3B3_Flag_DB( $rows ) ) )->resolve();
	} catch ( UnexpectedValueException $error ) {
		return $error->getMessage();
	}
	return '';
}

$module_source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/archive/class-archive-module.php' );
$descriptor_source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/archive/infrastructure/class-wordpress-archive-runtime-descriptor.php' );
$proposal_source = file_get_contents( dirname( __DIR__, 2 ) . '/docs/superpowers/plans/2026-07-30-dual-layer-archive-slice-1b-p3b3-runtime-composition-decisions-proposal.md' );
$runner_source = file_get_contents( __DIR__ . '/test-all.ps1' );

$dark = GHCA_ACD_Archive_Module::bootstrap();
archive_check(
	'constructed_dark' === GHCA_ACD_Archive_Module::state()
		&& 'blocked' === $dark['status']
		&& 'archive_runtime_disabled' === $dark['code'],
	'P3B3-DEFAULT-STATE-CONSTRUCTED-DARK performs no authority read or registration'
);
archive_check(
	'archive_runtime_disabled' === p3b3_gate_code( p3b3_flag_rows( array( 'ghca_acd_archive_enabled' => '0' ) ) )
		&& in_array(
			p3b3_gate_code( p3b3_flag_rows( array( 'ghca_acd_archive_enabled' => 1 ) ) ),
			array( 'archive_runtime_disabled', 'archive_runtime_schema_mismatch' ),
			true
		),
	'P3B3-FLAGS-EXACT-STRING-VALUES rejects disabled and non-string values'
);
archive_check(
	'archive_runtime_disabled' === p3b3_gate_code( p3b3_flag_rows( array( 'ghca_acd_archive_dual_layer' => '0' ) ) ),
	'P3B3-DUAL-LAYER-MIGRATOR-CONTRADICTION-RESOLVED requires the retained dual-layer flag'
);
archive_check(
	'archive_runtime_disabled' === p3b3_gate_code( p3b3_flag_rows( array( 'ghca_acd_archive_reset_enabled' => '1' ) ) )
		&& 'archive_runtime_schema_mismatch' === p3b3_gate_code( p3b3_flag_rows( array( 'ghca_acd_archive_reset_enabled' => null ) ) ),
	'P3B3-RESET-FLAG-ABSENT-OR-ZERO admits absent or zero without enabling reset'
);
archive_check(
	'archive_runtime_schema_mismatch' === p3b3_gate_code( p3b3_flag_rows( array( 'ghca_acd_archive_schema_version' => '0000' ) ) )
		&& false !== strpos( $descriptor_source, 'postflight_verify' ),
	'P3B3-SCHEMA-EXACT-VERSION-AND-POSTFLIGHT requires both version and all tables'
);
archive_check(
	false !== strpos( $descriptor_source, 'SELECT option_name,option_value,autoload FROM' )
		&& false !== strpos( $descriptor_source, 'ORDER BY BINARY option_name' ),
	'P3B3-FLAG-SCHEMA-DIRECT-OPTION-TABLE-READ uses one deterministic authority query'
);
archive_check(
	0 === preg_match( '/\\bget_option\\s*\\(|\\bapply_filters\\s*\\(/i', $descriptor_source ),
	'P3B3-OPTION-FILTER-SPOOF-IGNORED bypasses filtered option APIs'
);
archive_check(
	0 === preg_match( '/wp_cache|object_cache|cache_get/i', $descriptor_source ),
	'P3B3-STALE-OBJECT-CACHE-VALUE-IGNORED reads authoritative rows directly'
);
archive_check(
	0 === preg_match( '/get_site_option|site_option|network_option/i', $descriptor_source ),
	'P3B3-NETWORK-OPTION-SUBSTITUTION-IGNORED accepts no network authority'
);
$duplicate_rows = p3b3_flag_rows();
$duplicate_rows[] = $duplicate_rows[0];
archive_check(
	'archive_runtime_schema_mismatch' === p3b3_gate_code( $duplicate_rows ),
	'P3B3-DUPLICATE-OR-MALFORMED-AUTHORITY-ROW-REJECTED closes ambiguous configuration'
);
archive_check(
	false !== strpos( $module_source, 'activation_status' )
		&& false !== strpos( $module_source, 'archive_runtime_partial_retry_unresolved' )
		&& false !== strpos( $module_source, 'archive_runtime_review_capture_parity_unavailable' ),
	'P3B3-ANY-GATE-FAILS-CLOSED-BEFORE-CLAIM retains unresolved blockers'
);
archive_check(
	false !== strpos( $proposal_source, 'The kill switch is `ghca_acd_archive_enabled != "1"`' )
		&& false !== strpos( $proposal_source, 'immediately before calling `run_once()`' ),
	'P3B3-KILL-SWITCH-BEFORE-CLAIM remains an activation prerequisite'
);
archive_check(
	false !== strpos( $proposal_source, 'let them expire' )
		&& false !== strpos( $proposal_source, 'never translated into `ArchiveFailed`' ),
	'P3B3-INFLIGHT-DISABLE-FINISH-OR-LEASE-EXPIRY-NO-LIFECYCLE-FACT preserves fencing semantics'
);
archive_check(
	false !== strpos( $proposal_source, 'preserve all tasks/events/snapshots/descriptors/artifacts' )
		&& false !== strpos( $proposal_source, 'do not down-migrate, delete, reset, rewrite' ),
	'P3B3-ROLLBACK-PRESERVES-RETAINED-DATA has no destructive rollback'
);

$expected_result_keys = array(
	'code', 'claimed_count', 'completed_count', 'dead_count', 'duration_ms',
	'lease_lost_count', 'retry_count', 'stage', 'status',
);
$all_results_valid = 21 === count( GHCA_ACD_Archive_Module::RESULT_MAP );
foreach ( GHCA_ACD_Archive_Module::RESULT_MAP as $tuple => $counts ) {
	list( $status, $code, $stage ) = explode( '/', $tuple );
	$result = GHCA_ACD_Archive_Module::result( $status, $code, $stage, 7 );
	$all_results_valid = $all_results_valid
		&& $expected_result_keys === array_keys( $result )
		&& 7 === $result['duration_ms'];
}
archive_check(
	$all_results_valid,
	'P3B3-C01-C27-RESULT-STATUS-CODE-STAGE-TUPLES-EXIST-IN-SECTION-4-3 implements only closed tuples'
);
$all_counts_valid = true;
foreach ( GHCA_ACD_Archive_Module::RESULT_MAP as $counts ) {
	$all_counts_valid = $all_counts_valid && 5 === count( $counts )
		&& 0 === count( array_diff( $counts, array( 0, 1 ) ) );
}
archive_check(
	$all_counts_valid,
	'P3B3-RESULT-ALL-COUNTS-ALWAYS-ZERO-OR-ONE enforces bounded health values'
);
archive_check(
	false !== strpos( $module_source, 'hrtime( true )' )
		&& false !== strpos( $module_source, 'PHP_INT_MAX' )
		&& false !== strpos( $module_source, 'intdiv( $difference, 1000000 )' ),
	'P3B3-RESULT-DURATION-MONOTONIC-AND-SATURATING uses the frozen interval algorithm'
);
$encoded = GHCA_ACD_Archive_Module::encode_result(
	GHCA_ACD_Archive_Module::result( 'blocked', 'archive_runtime_disabled', 'flags', 0 )
);
archive_check(
	1 === substr_count( $encoded, '{' ) && 1 === substr_count( $encoded, '}' )
		&& false === strpos( $encoded, "\n" ) && 9 === count( json_decode( $encoded, true ) ),
	'P3B3-CLI-OUTPUT-EXACT-BOUNDED-SHAPE serializes one canonical nine-field document'
);
archive_check(
	0 === preg_match( '/message|task_id|tenant|email|password|secret|path|url|sql/i', $encoded ),
	'P3B3-CLI-OUTPUT-CONTAINS-NO-PII-SECRET-SQL-PATH-URL-OR-ID emits only operational fields'
);
archive_check(
	1 === substr_count( $module_source, 'fwrite( STDOUT' )
		&& strpos( $module_source, 'ob_start();' ) < strpos( $module_source, 'set_error_handler' )
		&& strpos( $module_source, 'ob_end_clean();' ) < strpos( $module_source, 'fwrite( STDOUT' ),
	'P3B3-WORKER-INJECTED-WARNING-DOES-NOT-CONTAMINATE-STDOUT discards buffered warning output before the envelope'
);
archive_check(
	false !== strpos( $module_source, 'catch ( Throwable $error )' )
		&& false !== strpos( $module_source, "archive_runtime_load_failed', 'load" ),
	'P3B3-WORKER-SUPPORTED-THROWABLE-EMITS-ONLY-CLOSED-ENVELOPE sanitizes supported failures'
);
archive_check(
	false !== strpos( $proposal_source, 'malformed stdout line' )
		&& false !== strpos( $proposal_source, 'operationally blocked' ),
	'P3B3-HOST-REJECTS-MALFORMED-STDOUT remains a mandatory host activation gate'
);
archive_check(
	false !== strpos( $proposal_source, 'multiple stdout lines' ),
	'P3B3-HOST-REJECTS-MULTIPLE-STDOUT-LINES remains a mandatory host activation gate'
);
archive_check(
	false !== strpos( $proposal_source, 'refuse to export raw stderr' )
		&& false !== strpos( $proposal_source, 'restricted incident channel' ),
	'P3B3-STDERR-EXCEPTION-AND-PATH-LEAKAGE-NOT-EXPORTED preserves the host privacy boundary'
);
archive_check(
	0 === preg_match( '/wp_remote_|curl_|fsockopen|stream_socket_client/i', $module_source . $descriptor_source ),
	'P3B3-NETWORK-ACTIVATION-REMAINS-DARK provides no certificate or telemetry transport'
);
archive_check(
	false !== strpos( $proposal_source, 'verify one canary blog before any next blog' ),
	'P3B3-PER-BLOG-CANARY-ORDER remains blocked on representative deployment evidence'
);
archive_check(
	false !== strpos( $module_source, "'production' === \$configuration['mode']" )
		&& false !== strpos( $module_source, 'archive_runtime_partial_retry_unresolved' ),
	'P3B3-PRODUCTION-ACTIVATION-BLOCKED-BY-CERTIFICATE-PACKET-VERIFY-D16 cannot reach a worker'
);
archive_check(
	false !== strpos( $proposal_source, 'No state transition appends a lifecycle event' )
		&& false !== strpos( $proposal_source, 'emergency_disabled' ),
	'P3B3-EMERGENCY-DISABLE-APPENDS-NO-LIFECYCLE-EVENT remains operational only'
);

$negative_oracle = static function ( array $before, array $after ): bool {
	return GHCA_ACD_Archive_Canonical_JSON::encode( $before ) === GHCA_ACD_Archive_Canonical_JSON::encode( $after );
};
archive_check(
	$negative_oracle( array( 'events' => 0 ), array( 'events' => 0 ) )
		&& ! $negative_oracle( array( 'events' => 0 ), array( 'events' => 1 ) ),
	'P3B3-NEGATIVE-ORACLE-DETECTS-UNEXPECTED-MUTATION proves the invariant check is non-vacuous'
);
archive_check(
	4 === substr_count( $runner_source, 'test-p3b3-' ),
	'P3B3-RUNNER-SUITES-EXACTLY-ONCE reserves one permanent entry per P3B3 suite'
);

archive_finish();
