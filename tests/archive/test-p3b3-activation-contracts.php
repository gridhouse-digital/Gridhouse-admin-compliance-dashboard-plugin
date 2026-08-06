<?php

if ( isset( $argv[1] ) && 'calculation-policy-negative-child' === $argv[1] ) {
	final class GHCA_ACD_LearnDash_Archive_Evidence_Source {
		public const CALCULATION_POLICY_KEY = 'caller-selected';
		public const CALCULATION_POLICY_VERSION = 99;
		public static $read_count = 0;
	}
	final class GHCA_P3B3A_Bad_Policy_Runtime {
		public function resolve(): array { return array(); }
	}
	final class GHCA_P3B3A_Bad_Policy_Attestor {
		public function attest(): array { return array(); }
	}
	require_once dirname( __DIR__, 2 ) . '/includes/archive/class-archive-module.php';
	$reflection = new ReflectionClass( GHCA_ACD_Archive_Module::class );
	$module = $reflection->newInstanceWithoutConstructor();
	$runtime = $reflection->getProperty( 'runtime' );
	$runtime->setValue( $module, new GHCA_P3B3A_Bad_Policy_Runtime() );
	$attestor = $reflection->getProperty( 'attestor' );
	$attestor->setValue( $module, new GHCA_P3B3A_Bad_Policy_Attestor() );
	$result = $module->activation_status();
	$passed = 'blocked' === $result['status']
		&& 'archive_runtime_calculation_policy_unapproved' === $result['code']
		&& 'calculation' === $result['stage']
		&& 0 === GHCA_ACD_LearnDash_Archive_Evidence_Source::$read_count;
	fwrite( STDOUT, $passed ? "CALCULATION_POLICY_BLOCKED\n" : "CALCULATION_POLICY_FAILED\n" );
	exit( $passed ? 0 : 1 );
}

require_once __DIR__ . '/bootstrap.php';

final class GHCA_P3B3A_Fixed_Clock implements GHCA_ACD_Archive_Clock {
	/** @var string */ private $now;
	public function __construct( string $now ) { $this->now = $now; }
	public function now_gmt(): string { return $this->now; }
}

final class GHCA_P3B3A_Preflight_DB {
	/** @var string */ public $last_error = '';
	/** @var array<int,string> */ public $queries = array();
	/** @var bool */ public $closed = false;
	/** @var bool */ public $invalid_grants = false;
	/** @var bool */ public $close_result = true;
	/** @var array<string,mixed> */ private $descriptor;

	/** @param array<string,mixed> $descriptor */
	public function __construct( array $descriptor ) { $this->descriptor = $descriptor; }
	public function prepare( string $sql, ...$args ): string { return $sql; }
	/** @return array<int,array<string,mixed>> */
	public function get_results( string $sql, $format ): array {
		$this->queries[] = $sql;
		if ( false !== strpos( $sql, 'CONNECTION_ID()' ) ) {
			return array( array(
				'connection_id' => '77', 'authenticated_user' => 'ghca_source@%',
				'database_name' => $this->descriptor['source_database'], 'session_time_zone' => '+00:00',
				'connection_charset' => 'utf8mb4',
			) );
		}
		$tables = $this->tables();
		if ( false !== strpos( $sql, 'SHOW GRANTS' ) ) {
			$rows = array( array( 'grant' => 'GRANT USAGE ON *.* TO `ghca_source`@`%`' ) );
			foreach ( $tables as $table ) {
				$rows[] = array( 'grant' => 'GRANT SELECT ON `' . $this->descriptor['source_database'] . '`.`' . $table . '` TO `ghca_source`@`%`' );
			}
			if ( $this->invalid_grants ) { array_pop( $rows ); }
			return $rows;
		}
		if ( false !== strpos( $sql, 'information_schema.tables' ) ) {
			return array_map( static function ( string $table ): array {
				return array( 'TABLE_NAME' => $table, 'TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB' );
			}, $tables );
		}
		$reflection = new ReflectionClass( GHCA_ACD_WPDB_Archive_Evidence_Read_Session::class );
		if ( false !== strpos( $sql, 'information_schema.columns' ) ) {
			$rows = array();
			foreach ( $reflection->getConstant( 'TABLE_COLUMNS' ) as $key => $columns ) {
				foreach ( $columns as $column ) { $rows[] = array( 'TABLE_NAME' => $this->descriptor[ $key ], 'COLUMN_NAME' => $column ); }
			}
			return $rows;
		}
		if ( false !== strpos( $sql, 'information_schema.statistics' ) ) {
			$rows = array();
			foreach ( $reflection->getConstant( 'TABLE_INDEXES' ) as $key => $indexes ) {
				foreach ( $indexes as $name => $columns ) {
					foreach ( $columns as $position => $column ) {
						$rows[] = array(
							'TABLE_NAME' => $this->descriptor[ $key ], 'INDEX_NAME' => $name,
							'NON_UNIQUE' => in_array( $name, array( 'PRIMARY', 'option_name' ), true ) ? '0' : '1',
							'SEQ_IN_INDEX' => (string) ( $position + 1 ), 'COLUMN_NAME' => $column, 'SUB_PART' => null,
						);
					}
				}
			}
			return $rows;
		}
		throw new RuntimeException( 'Unexpected preflight query.' );
	}
	public function query( string $sql ) { $this->queries[] = $sql; return true; }
	public function close(): bool { $this->closed = true; return $this->close_result; }
	/** @return array<int,string> */
	private function tables(): array {
		return array(
			$this->descriptor['users_table'], $this->descriptor['usermeta_table'], $this->descriptor['options_table'],
			$this->descriptor['posts_table'], $this->descriptor['postmeta_table'],
			$this->descriptor['learndash_user_activity_table'], $this->descriptor['learndash_user_activity_meta_table'],
		);
	}
}

/** @return array<string,mixed> */
function p3b3a_descriptor(): array {
	return array(
		'base_prefix' => 'wp_', 'blog_id' => 1, 'blog_prefix' => 'wp_', 'capabilities_meta_key' => 'wp_capabilities',
		'learndash_user_activity_meta_table' => 'wp_learndash_user_activity_meta',
		'learndash_user_activity_table' => 'wp_learndash_user_activity', 'options_table' => 'wp_options',
		'postmeta_table' => 'wp_postmeta', 'posts_table' => 'wp_posts', 'site_id' => '1',
		'source_database' => 'ghca_acd_archive_test_activation_source', 'tenant_id' => str_repeat( '1', 32 ),
		'user_roles_option_name' => 'wp_user_roles', 'usermeta_table' => 'wp_usermeta', 'users_table' => 'wp_users',
	);
}

/** @return array<string,int> */
function p3b3a_limits(): array {
	return array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 );
}

/** @return GHCA_ACD_WPDB_Archive_Evidence_Read_Session */
function p3b3a_session( GHCA_P3B3A_Preflight_DB $db ): GHCA_ACD_WPDB_Archive_Evidence_Read_Session {
	return new GHCA_ACD_WPDB_Archive_Evidence_Read_Session(
		$db, p3b3a_descriptor(), array( 'archive_connection_id' => 9, 'current_user' => 'ghca_source@%' ), array( 'wp_ghca_acd_archive_tasks' )
	);
}

/** @return array<string,mixed> */
function p3b3a_raw_fixture(): array {
	$started = (string) gmmktime( 14, 0, 0, 6, 30, 2026 );
	$completed = (string) gmmktime( 15, 0, 0, 6, 30, 2026 );
	$quiz_completed = (string) gmmktime( 14, 45, 0, 6, 30, 2026 );
	$options = array(
		'blogname' => 'Academy Example', 'ghca_dashboard_brand' => serialize( array( 'org_name' => 'Gridhouse Example' ) ),
		'ghca_acd_audit_mapping' => serialize( array( 101 => array(
			'odp_category' => 'individual_rights', 'oltl_category' => 'general', 'credit_hours' => 1.0,
			'sort_order' => 0, 'is_orientation' => 0,
		) ) ),
		'ghca_acd_course_lifespans' => serialize( array( 101 => 365 ) ), 'ghca_acd_warning_days' => '90',
		'ghca_acd_annual_cycle' => 'calendar_year', 'ghca_new_hire_group_ids' => serialize( array( 9 ) ),
		'ghca_new_hire_deadline_days' => '30',
		'learndash_settings_groups_management_display' => serialize( array( 'group_hierarchical_enabled' => 'yes' ) ),
		'wp_user_roles' => serialize( array( 'subscriber' => array( 'name' => 'Subscriber' ) ) ),
	);
	$option_rows = array(); $option_id = 1;
	foreach ( $options as $name => $value ) { $option_rows[] = array( 'option_id' => (string) $option_id++, 'option_name' => $name, 'option_value' => $value ); }
	usort( $option_rows, static function ( array $left, array $right ): int { return strcmp( $left['option_name'], $right['option_name'] ); } );
	return array(
		'activities' => array(
			array( 'activity_id' => '7001', 'user_id' => '42', 'post_id' => '101', 'course_id' => '101', 'activity_type' => 'course', 'activity_status' => '1', 'activity_started' => $started, 'activity_completed' => $completed, 'activity_updated' => $completed ),
			array( 'activity_id' => '8001', 'user_id' => '42', 'post_id' => '201', 'course_id' => '101', 'activity_type' => 'quiz', 'activity_status' => '1', 'activity_started' => $started, 'activity_completed' => $quiz_completed, 'activity_updated' => $quiz_completed ),
		),
		'activity_meta' => array(
			array( 'activity_meta_id' => '21', 'activity_id' => '8001', 'activity_meta_key' => 'pass', 'activity_meta_value' => '1' ),
			array( 'activity_meta_id' => '22', 'activity_id' => '8001', 'activity_meta_key' => 'percentage', 'activity_meta_value' => '88.125' ),
		),
		'certificates' => array(), 'configured_group_ids' => array( '9' ),
		'courses' => array( array( 'ID' => '101', 'post_title' => 'Safety & Rights', 'post_status' => 'publish', 'post_type' => 'sfwd-courses', 'post_modified_gmt' => '2026-06-29 12:00:00', 'post_parent' => '0' ) ),
		'effective_group_ids' => array( '9' ),
		'groups' => array( array( 'ID' => '9', 'post_title' => 'Team', 'post_status' => 'publish', 'post_type' => 'groups', 'post_modified_gmt' => '2026-01-01 00:00:00', 'post_parent' => '0' ) ),
		'options' => $option_rows,
		'postmeta' => array(
			array( 'meta_id' => '11', 'post_id' => '101', 'meta_key' => '_ld_certificate', 'meta_value' => '0' ),
			array( 'meta_id' => '12', 'post_id' => '101', 'meta_key' => '_ld_price_type', 'meta_value' => 'open' ),
			array( 'meta_id' => '13', 'post_id' => '101', 'meta_key' => 'learndash_group_enrolled_9', 'meta_value' => '101' ),
		),
		'query_count' => 29,
		'user' => array( 'ID' => '42', 'user_email' => 'ada@example.test', 'user_registered' => '2025-01-02 03:04:05', 'display_name' => 'Fallback Name' ),
		'usermeta' => array(
			array( 'umeta_id' => '1', 'user_id' => '42', 'meta_key' => 'course_101_access_from', 'meta_value' => $started ),
			array( 'umeta_id' => '2', 'user_id' => '42', 'meta_key' => 'course_completed_101', 'meta_value' => $completed ),
			array( 'umeta_id' => '3', 'user_id' => '42', 'meta_key' => 'first_name', 'meta_value' => 'Ada' ),
			array( 'umeta_id' => '4', 'user_id' => '42', 'meta_key' => 'last_name', 'meta_value' => 'Example' ),
			array( 'umeta_id' => '5', 'user_id' => '42', 'meta_key' => 'learndash_group_users_9', 'meta_value' => '9' ),
			array( 'umeta_id' => '6', 'user_id' => '42', 'meta_key' => 'wp_capabilities', 'meta_value' => serialize( array( 'subscriber' => true, 'edit_posts' => true ) ) ),
		),
	);
}

/** @return array<string,mixed> */
function p3b3a_review_identity(): array {
	return array(
		'case_key' => array( 'cycle_key' => '2026', 'employee_user_id_decimal' => '42', 'program_key' => 'annual_training', 'site_id_decimal' => '1', 'tenant_id' => str_repeat( '1', 32 ) ),
		'resolved_cycle' => array( 'boundary' => '[)', 'display_label' => '2026', 'end_gmt' => '2027-01-01T00:00:00Z', 'key' => '2026', 'policy_key' => 'calendar_year', 'policy_version' => 1, 'start_gmt' => '2026-01-01T00:00:00Z', 'timezone' => 'UTC' ),
	);
}

function p3b3a_exception( callable $call, string $category, string $reason, string $context ): bool {
	try { $call(); } catch ( GHCA_ACD_Archive_Evidence_Source_Exception $error ) {
		return $category === $error->category() && $reason === $error->reason_code() && $context === $error->operation_context();
	}
	return false;
}

$db = new GHCA_P3B3A_Preflight_DB( p3b3a_descriptor() );
$session = p3b3a_session( $db );
$session->preflight_only( p3b3a_limits(), static function (): void {} );
$query_text = implode( "\n", $db->queries );
archive_check(
	$db->closed && 5 === count( $db->queries ) && false === stripos( $query_text, 'START TRANSACTION' )
		&& 0 === preg_match( '/\b(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i', $query_text )
		&& false === strpos( $query_text, 'COUNT(*)' ),
	'ACTIVATION-SOURCE-PREFLIGHT-NO-EVIDENCE-QUERY validates the exact existing identity, grant, table, column, and index grammar only'
);
archive_check( $db->closed, 'ACTIVATION-SOURCE-CONNECTION-CLOSED closes the isolated preflight connection on success' );

$db = new GHCA_P3B3A_Preflight_DB( p3b3a_descriptor() ); $db->invalid_grants = true; $session = p3b3a_session( $db );
$invalid = p3b3a_exception( static function () use ( $session ): void { $session->preflight_only( p3b3a_limits(), static function (): void {} ); }, 'operational_blocked', 'archive_source_schema_unsupported', 'source_preflight' );
archive_check( $invalid && $db->closed, 'ACTIVATION-SOURCE-PREFLIGHT-VALIDATION-FAILURE-CLOSES rejects grant drift after connection cleanup' );

$db = new GHCA_P3B3A_Preflight_DB( p3b3a_descriptor() ); $session = p3b3a_session( $db ); $fence = new RuntimeException( 'exact-fence' ); $seen = null; $calls = 0;
try { $session->preflight_only( p3b3a_limits(), static function () use ( &$calls, $fence ): void { if ( 2 === ++$calls ) { throw $fence; } } ); } catch ( Throwable $error ) { $seen = $error; }
archive_check( $seen === $fence && $db->closed, 'ACTIVATION-SOURCE-PREFLIGHT-FENCE-PRESERVED rethrows the exact checkpoint throwable only after close' );

$db = new GHCA_P3B3A_Preflight_DB( p3b3a_descriptor() ); $db->close_result = false; $session = p3b3a_session( $db );
$priority = p3b3a_exception( static function () use ( $session ): void { $session->preflight_only( p3b3a_limits(), static function (): void { throw new RuntimeException( 'secret fence' ); } ); }, 'operational_blocked', 'archive_source_transaction_failed', 'connection_close' );
archive_check( $priority && $db->closed, 'ACTIVATION-SOURCE-PREFLIGHT-CLEANUP-PRIORITY returns the sanitized close tuple ahead of a pending throwable' );

$db = new GHCA_P3B3A_Preflight_DB( p3b3a_descriptor() ); $session = p3b3a_session( $db ); $session->preflight_only( p3b3a_limits(), static function (): void {} );
$reuse = p3b3a_exception( static function () use ( $session ): void { $session->preflight_only( p3b3a_limits(), static function (): void {} ); }, 'operational_blocked', 'archive_source_transaction_failed', 'connection_close' );
archive_check( $reuse, 'ACTIVATION-SOURCE-PREFLIGHT-CLOSED-SESSION-REUSE-REJECTED fails closed before another query' );

$module_source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/archive/class-archive-module.php' );
$runtime_source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/archive/infrastructure/class-wordpress-archive-runtime-descriptor.php' );
archive_check(
	is_string( $module_source ) && 1 === substr_count( $module_source, 'public function preflight_status' )
		&& false !== strpos( $module_source, '$source = $this->compose_evidence_source( $configuration, $versions );' )
		&& false !== strpos( $module_source, '$source->preflight( self::EVIDENCE_LIMITS, $checkpoint );' )
		&& strpos( $module_source, '$source->preflight( self::EVIDENCE_LIMITS, $checkpoint );' ) < strpos( $module_source, 'private function compose_worker' ),
	'ACTIVATION-REVIEW-USES-C11-COMPOSITION reaches preflight only through the private evidence-source recipe'
);
archive_check(
	is_string( $runtime_source ) && false !== strpos( $runtime_source, 'public function preflight(): array' )
		&& false !== strpos( $runtime_source, "return \$this->resolve_for_flags( '0' );" )
		&& is_string( $module_source ) && false === strpos( substr( $module_source, strpos( $module_source, 'public function preflight_status' ), strpos( $module_source, 'private function admission' ) - strpos( $module_source, 'public function preflight_status' ) ), 'compose_worker(' ),
	'ACTIVATION-FLAGS-OFF-PREFLIGHT-NONREGISTERING uses a separate off-state path without worker construction or claim'
);
archive_check(
	is_string( $module_source ) && 0 === substr_count( $module_source, 'WP_CLI::add_command' )
		&& false !== strpos( $module_source, "return self::result( 'blocked', 'archive_runtime_activation_blocked', 'activation'" ),
	'ACTIVATION-PREFLIGHT-CODE-ENFORCEABLE-GATES-ONLY leaves registration and host prerequisites outside the module'
);

$policy_command = array( PHP_BINARY );
if ( false === php_ini_loaded_file() ) { $policy_command[] = '-n'; }
$policy_command[] = __FILE__;
$policy_command[] = 'calculation-policy-negative-child';
$policy_process = proc_open(
	$policy_command,
	array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
	$policy_pipes
);
$policy_stdout = ''; $policy_stderr = ''; $policy_exit = -1;
if ( is_resource( $policy_process ) ) {
	$policy_stdout = stream_get_contents( $policy_pipes[1] );
	$policy_stderr = stream_get_contents( $policy_pipes[2] );
	fclose( $policy_pipes[1] ); fclose( $policy_pipes[2] );
	$policy_exit = proc_close( $policy_process );
}
archive_check(
	0 === $policy_exit && "CALCULATION_POLICY_BLOCKED\n" === $policy_stdout && '' === $policy_stderr
		&& strpos( $module_source, '$this->assert_calculation_policy();' ) < strpos( $module_source, '$this->compose_evidence_source(' ),
	'ACTIVATION-CALCULATION-POLICY-UNAPPROVED-BLOCKS-BEFORE-EVIDENCE-OR-CLAIM rejects any non-code-frozen policy/version at admission'
);

$adapter_reflection = new ReflectionClass( GHCA_ACD_LearnDash_Archive_Evidence_Source::class );
$adapter = $adapter_reflection->newInstanceWithoutConstructor();
$versions = $adapter_reflection->getProperty( 'versions' );
$versions->setValue( $adapter, array( 'learndash_version' => '5.1.6.1', 'plugin_version' => '1.2.0', 'wordpress_version' => '7.0.2' ) );
$normalize = $adapter_reflection->getMethod( 'normalize' );
$review_identity = p3b3a_review_identity();
$review_document = $normalize->invoke( $adapter, p3b3a_raw_fixture(), $review_identity, false );
$capture_identity = $review_identity; $capture_identity['policy_digest'] = $review_document['policy']['policy_digest'];
$capture_document = $normalize->invoke( $adapter, p3b3a_raw_fixture(), $capture_identity, true );
archive_check(
	isset( $review_document['policy']['policy_digest'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $review_document['policy']['policy_digest'] ),
	'ACTIVATION-REVIEW-POLICY-DIGEST-SERVER-DERIVED computes the E07 policy digest without caller authority'
);
archive_check(
	GHCA_ACD_Archive_Canonical_JSON::encode( $review_document ) === GHCA_ACD_Archive_Canonical_JSON::encode( $capture_document ),
	'ACTIVATION-REVIEW-CAPTURE-E07-BYTE-IDENTICAL uses the one shared normalization path'
);
archive_check(
	GHCA_ACD_Archive_Digester::source_fingerprint( $review_document ) === GHCA_ACD_Archive_Digester::source_fingerprint( $capture_document ),
	'ACTIVATION-REVIEW-CAPTURE-E08-IDENTICAL uses the retained source-fingerprint domain'
);
$missing = p3b3a_exception( static function () use ( $normalize, $adapter, $review_identity ): void { $normalize->invoke( $adapter, p3b3a_raw_fixture(), $review_identity, true ); }, 'invalid', 'archive_build_binding_invalid', 'authoritative_load' );
$mismatch_identity = $review_identity; $mismatch_identity['policy_digest'] = str_repeat( '0', 64 );
$mismatch = p3b3a_exception( static function () use ( $normalize, $adapter, $mismatch_identity ): void { $normalize->invoke( $adapter, p3b3a_raw_fixture(), $mismatch_identity, true ); }, 'invalid', 'archive_build_binding_invalid', 'authoritative_load' );
archive_check( $missing, 'ACTIVATION-CAPTURE-MISSING-POLICY-DIGEST-REJECTED preserves retained capture authority' );
archive_check( $mismatch, 'ACTIVATION-CAPTURE-MISMATCHED-POLICY-DIGEST-REJECTED preserves retained capture binding' );
$caller_identity = $review_identity; $caller_identity['policy_digest'] = $review_document['policy']['policy_digest'];
$caller_rejected = p3b3a_exception( static function () use ( $adapter, $caller_identity ): void { $adapter->read_consistent_review_evidence( $caller_identity, p3b3a_limits(), static function (): void {} ); }, 'invalid', 'archive_build_binding_invalid', 'authoritative_load' );
archive_check( $caller_rejected, 'ACTIVATION-REVIEW-CALLER-POLICY-DIGEST-REJECTED rejects policy authority before a source query' );
archive_check( $missing && $mismatch && $caller_rejected, 'ACTIVATION-REVIEW-DOES-NOT-WEAKEN-CAPTURE-BINDING keeps review and capture authority separate' );

$plugin_root = dirname( __DIR__, 2 );
$test_root = dirname( $plugin_root, 3 ) . '\\ghca_acd_archive_test_activation_contracts';
if ( ! is_dir( $test_root ) ) { mkdir( $test_root, 0700, true ); }
$private_root = $test_root . '\\private'; if ( ! is_dir( $private_root ) ) { mkdir( $private_root, 0700, true ); }
$authorization_path = $test_root . '\\authorization.json';
$wordpress_root = dirname( $plugin_root, 3 );
if ( ! defined( 'GHCA_ACD_ARCHIVE_PRIVATE_DIR' ) ) { define( 'GHCA_ACD_ARCHIVE_PRIVATE_DIR', $private_root ); }
if ( ! defined( 'GHCA_ACD_ARCHIVE_PUBLIC_DOCUMENT_ROOT' ) ) { define( 'GHCA_ACD_ARCHIVE_PUBLIC_DOCUMENT_ROOT', $wordpress_root ); }
if ( ! defined( 'GHCA_ACD_ARCHIVE_CURSOR_HMAC_KEY' ) ) { define( 'GHCA_ACD_ARCHIVE_CURSOR_HMAC_KEY', str_repeat( 'a', 64 ) ); }
if ( ! defined( 'GHCA_ACD_ARCHIVE_ACTIVATION_AUTHORIZATION_FILE' ) ) { define( 'GHCA_ACD_ARCHIVE_ACTIVATION_AUTHORIZATION_FILE', $authorization_path ); }
$clock = new GHCA_P3B3A_Fixed_Clock( '2026-08-03T12:00:00.000000Z' );
$storage_method = ( new ReflectionClass( GHCA_ACD_WordPress_Archive_Runtime_Descriptor::class ) )->getMethod( 'assert_storage_capacity' );
$runtime = new GHCA_ACD_WordPress_Archive_Runtime_Descriptor( new stdClass(), $clock, static function () { return 1090519040; } );
$equality = true; try { $storage_method->invoke( $runtime, 'controlled_testing', $private_root ); } catch ( Throwable $error ) { $equality = false; }
$runtime = new GHCA_ACD_WordPress_Archive_Runtime_Descriptor( new stdClass(), $clock, static function () { return 1090519041; } );
$above = true; try { $storage_method->invoke( $runtime, 'controlled_testing', $private_root ); } catch ( Throwable $error ) { $above = false; }
$runtime = new GHCA_ACD_WordPress_Archive_Runtime_Descriptor( new stdClass(), $clock, static function () { return 1090519039; } );
$below = false; try { $storage_method->invoke( $runtime, 'controlled_testing', $private_root ); } catch ( UnexpectedValueException $error ) { $below = 'archive_runtime_storage_invalid' === $error->getMessage(); }
archive_check( $equality, 'ACTIVATION-STORAGE-THRESHOLD-EQUALITY-ACCEPTED accepts the exact byte threshold' );
archive_check( $above, 'ACTIVATION-STORAGE-THRESHOLD-ABOVE-ACCEPTED accepts one byte above without rounding' );
archive_check( $below, 'ACTIVATION-STORAGE-THRESHOLD-ONE-BYTE-BELOW-REJECTED rejects the exact lower boundary' );

$authorization = array(
	'authorization_schema_version' => 1, 'blog_id' => '1', 'change_role_id' => 'PRODUCT_OWNER',
	'end_at_gmt' => '2026-08-04T12:00:00.000000Z', 'evidence_sha256' => null, 'mode' => 'controlled_testing',
	'operator_role_id' => 'WPCLI_OPERATOR', 'rollback_role_id' => 'ROLLBACK_OWNER', 'site_id' => '1',
	'start_at_gmt' => '2026-08-03T12:00:00.000000Z',
);
file_put_contents( $authorization_path, GHCA_ACD_Archive_Canonical_JSON::encode( $authorization ) );
$runtime = new GHCA_ACD_WordPress_Archive_Runtime_Descriptor( new stdClass(), $clock, static function () { return 1090519040; } );
$authorization_method = ( new ReflectionClass( $runtime ) )->getMethod( 'assert_authorization' );
$storage = array( 'private_root' => realpath( $private_root ), 'public_roots' => array( realpath( $plugin_root ) ) );
$authorized = true; try { $authorization_method->invoke( $runtime, array( 'blog_id' => 1, 'site_id' => '1' ), 'controlled_testing', $storage ); } catch ( Throwable $error ) { $authorized = false; }
archive_check( $authorized, 'ACTIVATION-AUTHORIZATION-EXACT-BLOG-WINDOW accepts the exact canonical active document' );

$rejections = array();
$variants = array(
	'missing' => static function ( array $value ): array { unset( $value['change_role_id'] ); return $value; },
	'extra' => static function ( array $value ): array { $value['unexpected'] = true; return $value; },
	'future' => static function ( array $value ): array { $value['start_at_gmt'] = '2026-08-03T12:00:01.000000Z'; return $value; },
	'expired' => static function ( array $value ): array { $value['end_at_gmt'] = '2026-08-03T12:00:00.000000Z'; return $value; },
	'blog' => static function ( array $value ): array { $value['blog_id'] = '2'; return $value; },
	'roles' => static function ( array $value ): array { $value['rollback_role_id'] = $value['operator_role_id']; return $value; },
	'evidence' => static function ( array $value ): array { $value['evidence_sha256'] = str_repeat( 'a', 64 ); return $value; },
	'window' => static function ( array $value ): array { $value['end_at_gmt'] = '2026-08-05T12:00:01.000000Z'; return $value; },
	'schema' => static function ( array $value ): array { $value['authorization_schema_version'] = '1'; return $value; },
);
foreach ( $variants as $name => $change ) {
	file_put_contents( $authorization_path, GHCA_ACD_Archive_Canonical_JSON::encode( $change( $authorization ) ) );
	try { $authorization_method->invoke( $runtime, array( 'blog_id' => 1, 'site_id' => '1' ), 'controlled_testing', $storage ); $rejections[ $name ] = false; }
	catch ( UnexpectedValueException $error ) { $rejections[ $name ] = 'archive_runtime_activation_blocked' === $error->getMessage(); }
}
file_put_contents( $authorization_path, "{\"blog_id\":\"1\",\"authorization_schema_version\":1}" );
try { $authorization_method->invoke( $runtime, array( 'blog_id' => 1, 'site_id' => '1' ), 'controlled_testing', $storage ); $rejections['reordered'] = false; }
catch ( UnexpectedValueException $error ) { $rejections['reordered'] = 'archive_runtime_activation_blocked' === $error->getMessage(); }
archive_check( ! in_array( false, $rejections, true ), 'ACTIVATION-AUTHORIZATION-MISSING-EXTRA-REORDERED-REJECTED closes every canonical-document contradiction' );
archive_check( $rejections['future'] && $rejections['expired'], 'ACTIVATION-AUTHORIZATION-NOT-YET-VALID-REJECTED enforces start inclusive and end exclusive' );
archive_check( $rejections['blog'] && $rejections['roles'] && $rejections['evidence'] && $rejections['window'] && $rejections['schema'], 'ACTIVATION-AUTHORIZATION-MODE-SITE-BLOG-MISMATCH-REJECTED binds blog, roles, mode, window, and evidence grammar' );

file_put_contents( $authorization_path, "\xEF\xBB\xBF" . GHCA_ACD_Archive_Canonical_JSON::encode( $authorization ) );
$bom_rejected = false; try { $authorization_method->invoke( $runtime, array( 'blog_id' => 1, 'site_id' => '1' ), 'controlled_testing', $storage ); }
catch ( UnexpectedValueException $error ) { $bom_rejected = 'archive_runtime_activation_blocked' === $error->getMessage(); }
file_put_contents( $authorization_path, str_repeat( 'x', 2049 ) );
$oversized_rejected = false; try { $authorization_method->invoke( $runtime, array( 'blog_id' => 1, 'site_id' => '1' ), 'controlled_testing', $storage ); }
catch ( UnexpectedValueException $error ) { $oversized_rejected = 'archive_runtime_activation_blocked' === $error->getMessage(); }
file_put_contents( $authorization_path, GHCA_ACD_Archive_Canonical_JSON::encode( $authorization ) );
$public_rejected = false; try {
	$authorization_method->invoke( $runtime, array( 'blog_id' => 1, 'site_id' => '1' ), 'controlled_testing', array( 'private_root' => realpath( $private_root ), 'public_roots' => array( realpath( $wordpress_root ) ) ) );
} catch ( UnexpectedValueException $error ) { $public_rejected = 'archive_runtime_activation_blocked' === $error->getMessage(); }
archive_check( $bom_rejected && $oversized_rejected && $public_rejected, 'ACTIVATION-AUTHORIZATION-UNSAFE-FILE-BEHAVIOR rejects BOM, overlong, and public-root authorization files' );

// The module, not the intake service, owns the checkpoint before source composition.
$review_start = strpos( $module_source, 'public function review(' );
$review_end = strpos( $module_source, 'private function admission', $review_start );
$review_source = false !== $review_start && false !== $review_end
	? substr( $module_source, $review_start, $review_end - $review_start )
	: '';
archive_check(
	'' !== $review_source
		&& strpos( $review_source, '$checkpoint();' ) < strpos( $review_source, '$this->compose_evidence_source(' ),
	'ACTIVATION-REVIEW-MODULE-PRECOMPOSE-CHECKPOINT-ORDER checkpoints before constructing the fresh source'
);

/** @return array<int,string> */
function p3b3a_assertion_ids( string $path ): array {
	$source = @file_get_contents( $path );
	if ( ! is_string( $source ) ) {
		return array();
	}
	$tokens = token_get_all( $source );
	$ids = array();
	$count = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0]
			|| 'archive_check' !== strtolower( $tokens[ $i ][1] ) ) {
			continue;
		}
		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) { $j++; }
		if ( $j >= $count || '(' !== $tokens[ $j ] ) {
			continue;
		}
		$parentheses = 1;
		$brackets = 0;
		$braces = 0;
		$argument = 1;
		$second = array();
		for ( $j++; $j < $count; $j++ ) {
			$token = $tokens[ $j ];
			if ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) { $braces++; }
			if ( '(' === $token ) { $parentheses++; }
			if ( ')' === $token ) {
				if ( 1 === $parentheses && 0 === $brackets && 0 === $braces ) { break; }
				$parentheses--;
			}
			if ( '[' === $token ) { $brackets++; }
			if ( ']' === $token ) { $brackets--; }
			if ( '{' === $token ) { $braces++; }
			if ( '}' === $token ) { $braces--; }
			if ( ',' === $token && 1 === $parentheses && 0 === $brackets && 0 === $braces ) { $argument++; continue; }
			if ( 2 === $argument ) { $second[] = $token; }
		}
		$second = array_values( array_filter( $second, static function ( $token ): bool {
			return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
		} ) );
		if ( 1 !== count( $second ) || ! is_array( $second[0] ) || T_CONSTANT_ENCAPSED_STRING !== $second[0][0] ) {
			continue;
		}
		$quote = $second[0][1][0];
		$literal = substr( $second[0][1], 1, -1 );
		$literal = "'" === $quote
			? str_replace( array( "\\\\", "\\'" ), array( "\\", "'" ), $literal )
			: stripcslashes( $literal );
		if ( preg_match( '/^([A-Z][A-Z0-9]*(?:-[A-Z0-9]+){2,})(?:\s|$)/D', $literal, $match ) ) {
			$ids[] = $match[1];
		}
	}
	$ids = array_values( array_unique( $ids ) );
	sort( $ids, SORT_STRING );
	return $ids;
}

/** @return string|null */
function p3b3a_evidence_manifest_error( array $manifest, array $approved, string $runner, string $root ) {
	$expected_classes = array( 'DEFERRED_OPERATOR_EVIDENCE', 'EXECUTED_PASS', 'RETAINED_PASS' );
	$classes = array_keys( $manifest );
	sort( $classes, SORT_STRING );
	if ( $expected_classes !== $classes ) { return 'classes'; }
	$seen = array();
	$assertions = array();
	$runner = str_replace( '\\', '/', $runner );
	foreach ( array( 'EXECUTED_PASS', 'RETAINED_PASS' ) as $classification ) {
		foreach ( $manifest[ $classification ] as $requirement => $evidence ) {
			if ( ! is_string( $requirement ) || isset( $seen[ $requirement ] ) ) { return 'duplicate_requirement'; }
			$seen[ $requirement ] = true;
			if ( ! is_array( $evidence ) || array( 'suite', 'check_id' ) !== array_keys( $evidence )
				|| ! is_string( $evidence['suite'] ) || ! is_string( $evidence['check_id'] )
				|| '' === $evidence['suite'] || '' === $evidence['check_id'] ) { return 'mapping'; }
			if ( 1 !== preg_match( '#^tests/archive/test-[a-z0-9-]+\.php$#D', $evidence['suite'] ) ) { return 'suite_path'; }
			$path = $root . '/' . $evidence['suite'];
			if ( ! is_file( $path ) ) { return 'suite_missing'; }
			if ( 1 !== substr_count( $runner, $evidence['suite'] ) ) { return 'suite_runner_count'; }
			if ( 0 === strpos( $evidence['check_id'], 'ACTIVATION-EVIDENCE-MAPPING-' )
				|| 'ACTIVATION-EVIDENCE-MANIFEST-122-CLASSIFIED' === $evidence['check_id'] ) { return 'self_reference'; }
			if ( ! isset( $assertions[ $path ] ) ) { $assertions[ $path ] = p3b3a_assertion_ids( $path ); }
			if ( ! in_array( $evidence['check_id'], $assertions[ $path ], true ) ) { return 'check_missing'; }
		}
	}
	foreach ( $manifest['DEFERRED_OPERATOR_EVIDENCE'] as $requirement ) {
		if ( ! is_string( $requirement ) ) { return 'deferred_mapping'; }
		if ( isset( $seen[ $requirement ] ) ) { return 'duplicate_requirement'; }
		$seen[ $requirement ] = true;
	}
	$actual = array_keys( $seen );
	sort( $actual, SORT_STRING );
	sort( $approved, SORT_STRING );
	return 122 === count( $actual ) && $approved === $actual ? null : 'approved_set';
}

// Classify every approved name without converting deferred evidence into a pass.
$proposal_path = dirname( __DIR__, 2 ) . '/docs/superpowers/plans/2026-08-01-dual-layer-archive-slice-1b-p3b3-activation-contracts-proposal.md';
$proposal = file_get_contents( $proposal_path );
preg_match_all( '/- ' . chr( 96 ) . '((?:ACTIVATION|P3B3)-[A-Z0-9-]+)' . chr( 96 ) . '/', (string) $proposal, $approved_matches );
$approved_names = array_values( array_unique( $approved_matches[1] ?? array() ) );
sort( $approved_names, SORT_STRING );
$evidence_manifest = array(
	'EXECUTED_PASS' => array(
		'ACTIVATION-ATTEST-CONFIG-SPOOF-REJECTED' => array( 'suite' => 'tests/archive/test-p3b3-runtime-composition.php', 'check_id' => 'P3B3-ATTEST-REJECTS-ORDINARY-OPTION-ENV-REQUEST-TASK-DB-GLOBAL-FILTER-SPOOF' ),
		'ACTIVATION-ATTEST-EXACT-TUPLE' => array( 'suite' => 'tests/archive/test-p3b3-runtime-composition.php', 'check_id' => 'P3B3-ATTEST-EXACT-WP-LD-PLUGIN-TUPLE' ),
		'ACTIVATION-ATTEST-RECHECK-BEFORE-CLAIM' => array( 'suite' => 'tests/archive/test-p3b3-activation-concurrency.php', 'check_id' => 'ACTIVATION-ATTEST-RECHECK-BEFORE-CLAIM' ),
		'ACTIVATION-AUTHORIZATION-CANONICAL-DOCUMENT-EXACT' => array( 'suite' => 'tests/archive/test-p3b3-activation-contracts.php', 'check_id' => 'ACTIVATION-AUTHORIZATION-EXACT-BLOG-WINDOW' ),
		'ACTIVATION-AUTHORIZATION-EXACT-BLOG-WINDOW' => array( 'suite' => 'tests/archive/test-p3b3-activation-contracts.php', 'check_id' => 'ACTIVATION-AUTHORIZATION-EXACT-BLOG-WINDOW' ),
		'ACTIVATION-AUTHORIZATION-EXPIRES' => array( 'suite' => 'tests/archive/test-p3b3-activation-contracts.php', 'check_id' => 'ACTIVATION-AUTHORIZATION-NOT-YET-VALID-REJECTED' ),
		'ACTIVATION-AUTHORIZATION-MISSING-EXTRA-REORDERED-REJECTED' => array( 'suite' => 'tests/archive/test-p3b3-activation-contracts.php', 'check_id' => 'ACTIVATION-AUTHORIZATION-MISSING-EXTRA-REORDERED-REJECTED' ),
		'ACTIVATION-AUTHORIZATION-MODE-SITE-BLOG-MISMATCH-REJECTED' => array( 'suite' => 'tests/archive/test-p3b3-activation-contracts.php', 'check_id' => 'ACTIVATION-AUTHORIZATION-MODE-SITE-BLOG-MISMATCH-REJECTED' ),
		'ACTIVATION-AUTHORIZATION-NONEXPOSURE' => array( 'suite' => 'tests/archive/test-p3b3-activation-gates.php', 'check_id' => 'P3B3-CLI-OUTPUT-CONTAINS-NO-PII-SECRET-SQL-PATH-URL-OR-ID' ),
		'ACTIVATION-AUTHORIZATION-NOT-YET-VALID-REJECTED' => array( 'suite' => 'tests/archive/test-p3b3-activation-contracts.php', 'check_id' => 'ACTIVATION-AUTHORIZATION-NOT-YET-VALID-REJECTED' ),
		'ACTIVATION-AUTHORIZATION-UNSAFE-FILE-REJECTED' => array( 'suite' => 'tests/archive/test-p3b3-activation-contracts.php', 'check_id' => 'ACTIVATION-AUTHORIZATION-UNSAFE-FILE-BEHAVIOR' ),
		'ACTIVATION-BOOTSTRAP-MANIFEST-EXACT' => array( 'suite' => 'tests/archive/test-p3b3-runtime-composition.php', 'check_id' => 'P3B3-BOOTSTRAP-LITERAL-55-FILE-MANIFEST-AND-DIGEST' ),
		'ACTIVATION-CERTIFICATE-NETWORK-PROHIBITED' => array( 'suite' => 'tests/archive/test-p3b3-runtime-composition.php', 'check_id' => 'P3B3-NO-NETWORK-OR-CERTIFICATE-ACQUISITION' ),
		'ACTIVATION-CERTIFICATE-PRODUCTION-GATE' => array( 'suite' => 'tests/archive/test-p3b3-activation-gates.php', 'check_id' => 'P3B3-PRODUCTION-ACTIVATION-BLOCKED-BY-CERTIFICATE-PACKET-VERIFY-D16' ),
		'ACTIVATION-CONTROLLED-NO-FINALIZATION' => array( 'suite' => 'tests/archive/test-p3b3-worker-runtime.php', 'check_id' => 'P3B3-HANDLER-REGISTRY-EXACT-CAPTURE-AND-LEDGER' ),
		'ACTIVATION-CONTROLLED-TASK-REGISTRY-CLOSED' => array( 'suite' => 'tests/archive/test-p3b3-worker-runtime.php', 'check_id' => 'P3B3-HANDLER-REGISTRY-EXACT-CAPTURE-AND-LEDGER' ),
		'ACTIVATION-CURSOR-KEY-NONEXPOSURE' => array( 'suite' => 'tests/archive/test-p3b3-worker-runtime.php', 'check_id' => 'P3B3-CURSOR-KEY-NOT-REUSED-OR-EXPOSED' ),
		'ACTIVATION-D16-CONTROLLED-RETRY-REJECTED' => array( 'suite' => 'tests/archive/test-p3b3-worker-runtime.php', 'check_id' => 'P3B3-D16-RETRY-TASK-NOT-CLAIMED' ),
		'ACTIVATION-D16-PRODUCTION-BLOCKED' => array( 'suite' => 'tests/archive/test-p3b3-activation-gates.php', 'check_id' => 'P3B3-PRODUCTION-ACTIVATION-BLOCKED-BY-CERTIFICATE-PACKET-VERIFY-D16' ),
		'ACTIVATION-D16-RETRY-TASK-NOT-INSTALLED' => array( 'suite' => 'tests/archive/test-p3b3-worker-runtime.php', 'check_id' => 'P3B3-D16-RETRY-TASK-NOT-CLAIMED' ),
		'ACTIVATION-ENTRYPOINT-REFERENCE-UNCHANGED' => array( 'suite' => 'tests/archive/test-p3b3-runtime-composition.php', 'check_id' => 'P3B3-ENTRYPOINT-ONE-ARCHIVE-REFERENCE' ),
		'ACTIVATION-FLAGS-NO-INTERMEDIATE-ADMISSION' => array( 'suite' => 'tests/archive/test-p3b3-activation-gates.php', 'check_id' => 'P3B3-FLAGS-EXACT-STRING-VALUES' ),
		'ACTIVATION-FLAGS-OFF-PREFLIGHT-NONREGISTERING' => array( 'suite' => 'tests/archive/test-p3b3-activation-contracts.php', 'check_id' => 'ACTIVATION-FLAGS-OFF-PREFLIGHT-NONREGISTERING' ),
		'ACTIVATION-FLAGS-RECHECK-BEFORE-CLAIM' => array( 'suite' => 'tests/archive/test-p3b3-activation-concurrency.php', 'check_id' => 'ACTIVATION-FLAGS-REREAD-BEFORE-CLAIM' ),
		'ACTIVATION-KILL-SWITCH-NO-DATA-MUTATION' => array( 'suite' => 'tests/archive/test-p3b3-activation-gates.php', 'check_id' => 'P3B3-ROLLBACK-PRESERVES-RETAINED-DATA' ),
		'ACTIVATION-MULTISITE-NO-NETWORK-ACTIVATION' => array( 'suite' => 'tests/archive/test-p3b3-activation-gates.php', 'check_id' => 'P3B3-NETWORK-ACTIVATION-REMAINS-DARK' ),
		'ACTIVATION-MULTISITE-NO-SWITCH-LOOP' => array( 'suite' => 'tests/archive/test-p3b3-multisite.php', 'check_id' => 'P3B3-MIXED-BLOG-OR-SWITCHED-CONTEXT-REJECTED' ),
		'ACTIVATION-MULTISITE-PER-BLOG-AUTHORITY' => array( 'suite' => 'tests/archive/test-p3b3-multisite.php', 'check_id' => 'P3B3-BLOG-ID-PREFIX-TABLE-DESCRIPTOR-EXACT' ),
		'ACTIVATION-NO-CERTIFICATE-PACKET-D16-HANDLER' => array( 'suite' => 'tests/archive/test-p3b3-worker-runtime.php', 'check_id' => 'P3B3-HANDLER-REGISTRY-EXACT-CAPTURE-AND-LEDGER' ),
		'ACTIVATION-OUTPUT-RAW-PATH-EXCEPTION-NOT-LEAKED' => array( 'suite' => 'tests/archive/test-p3b3-activation-gates.php', 'check_id' => 'P3B3-STDERR-EXCEPTION-AND-PATH-LEAKAGE-NOT-EXPORTED' ),
		'ACTIVATION-PACKET-HANDLER-ABSENT' => array( 'suite' => 'tests/archive/test-p3b3-worker-runtime.php', 'check_id' => 'P3B3-HANDLER-REGISTRY-EXACT-CAPTURE-AND-LEDGER' ),
		'ACTIVATION-PARITY-DEPENDENCY-DESCRIPTORS-EXACT' => array( 'suite' => 'tests/archive/test-p3b3-activation-persistence.php', 'check_id' => 'ACTIVATION-PARITY-DEPENDENCY-DESCRIPTORS-EXACT' ),
		'ACTIVATION-PARITY-E07-BYTES-EXACT' => array( 'suite' => 'tests/archive/test-p3b3-activation-persistence.php', 'check_id' => 'ACTIVATION-PARITY-E07-BYTES-EXACT' ),
		'ACTIVATION-PARITY-E08-DIGEST-EXACT' => array( 'suite' => 'tests/archive/test-p3b3-activation-persistence.php', 'check_id' => 'ACTIVATION-PARITY-E08-DIGEST-EXACT' ),
		'ACTIVATION-PARITY-MUTATION-DRIFT-FENCED' => array( 'suite' => 'tests/archive/test-p3b3-activation-persistence.php', 'check_id' => 'ACTIVATION-PARITY-MUTATION-DRIFT-FENCED' ),
		'ACTIVATION-PREFLIGHT-CODE-ENFORCEABLE-GATES-ONLY' => array( 'suite' => 'tests/archive/test-p3b3-activation-contracts.php', 'check_id' => 'ACTIVATION-PREFLIGHT-CODE-ENFORCEABLE-GATES-ONLY' ),
		'ACTIVATION-PREFLIGHT-HOST-EVIDENCE-OUTSIDE-MODULE' => array( 'suite' => 'tests/archive/test-p3b3-activation-contracts.php', 'check_id' => 'ACTIVATION-PREFLIGHT-CODE-ENFORCEABLE-GATES-ONLY' ),
		'ACTIVATION-RESET-FLAG-OFF' => array( 'suite' => 'tests/archive/test-p3b3-activation-gates.php', 'check_id' => 'P3B3-RESET-FLAG-ABSENT-OR-ZERO' ),
		'ACTIVATION-REVIEW-CALLER-FINGERPRINT-REJECTED' => array( 'suite' => 'tests/archive/test-p3b3-activation-persistence.php', 'check_id' => 'ACTIVATION-REVIEW-CALLER-FINGERPRINT-REJECTED' ),
		'ACTIVATION-REVIEW-FENCE-PRESERVED' => array( 'suite' => 'tests/archive/test-p3b3-activation-persistence.php', 'check_id' => 'ACTIVATION-REVIEW-FENCE-PRESERVED' ),
		'ACTIVATION-REVIEW-INTAKE-OWNS-READ-DIGEST-UOW-CHECKPOINTS' => array( 'suite' => 'tests/archive/test-p3b3-activation-persistence.php', 'check_id' => 'ACTIVATION-REVIEW-INTAKE-SERVER-FACTS-AND-CHECKPOINTS' ),
		'ACTIVATION-REVIEW-MODULE-CALLS-PRIVATE-COMPOSER' => array( 'suite' => 'tests/archive/test-p3b3-worker-runtime.php', 'check_id' => 'P3B3-REVIEW-CAPTURE-ONE-PRIVATE-COMPOSITION-RECIPE' ),
		'ACTIVATION-REVIEW-MODULE-OWNS-PRECOMPOSE-CHECKPOINT' => array( 'suite' => 'tests/archive/test-p3b3-activation-contracts.php', 'check_id' => 'ACTIVATION-REVIEW-MODULE-PRECOMPOSE-CHECKPOINT-ORDER' ),
		'ACTIVATION-REVIEW-NO-CACHE-SUBSTITUTION' => array( 'suite' => 'tests/archive/test-p3b3-activation-persistence.php', 'check_id' => 'ACTIVATION-PARITY-MUTATION-DRIFT-FENCED' ),
		'ACTIVATION-REVIEW-PRIVATE-COMPOSER-NOT-EXPOSED' => array( 'suite' => 'tests/archive/test-p3b3-worker-runtime.php', 'check_id' => 'P3B3-REVIEW-CAPTURE-ONE-PRIVATE-COMPOSITION-RECIPE' ),
		'ACTIVATION-REVIEW-USES-C11-COMPOSITION' => array( 'suite' => 'tests/archive/test-p3b3-activation-contracts.php', 'check_id' => 'ACTIVATION-REVIEW-USES-C11-COMPOSITION' ),
		'ACTIVATION-STDOUT-CANONICAL-NINE-FIELDS' => array( 'suite' => 'tests/archive/test-p3b3-activation-gates.php', 'check_id' => 'P3B3-CLI-OUTPUT-EXACT-BOUNDED-SHAPE' ),
		'ACTIVATION-STDOUT-EXACT-ONE-LINE' => array( 'suite' => 'tests/archive/test-p3b3-activation-gates.php', 'check_id' => 'P3B3-HOST-REJECTS-MULTIPLE-STDOUT-LINES' ),
		'ACTIVATION-STDOUT-WARNING-CONTAINED' => array( 'suite' => 'tests/archive/test-p3b3-activation-gates.php', 'check_id' => 'P3B3-WORKER-INJECTED-WARNING-DOES-NOT-CONTAMINATE-STDOUT' ),
		'ACTIVATION-STORAGE-THRESHOLD-ABOVE-ACCEPTED' => array( 'suite' => 'tests/archive/test-p3b3-activation-contracts.php', 'check_id' => 'ACTIVATION-STORAGE-THRESHOLD-ABOVE-ACCEPTED' ),
		'ACTIVATION-STORAGE-THRESHOLD-EQUALITY-ACCEPTED' => array( 'suite' => 'tests/archive/test-p3b3-activation-contracts.php', 'check_id' => 'ACTIVATION-STORAGE-THRESHOLD-EQUALITY-ACCEPTED' ),
		'ACTIVATION-STORAGE-THRESHOLD-ONE-BYTE-BELOW-REJECTED' => array( 'suite' => 'tests/archive/test-p3b3-activation-contracts.php', 'check_id' => 'ACTIVATION-STORAGE-THRESHOLD-ONE-BYTE-BELOW-REJECTED' ),
	),
	'RETAINED_PASS' => array(
		'ACTIVATION-CERTIFICATE-REFERENCE-B10-UNCHANGED' => array( 'suite' => 'tests/archive/test-p3b2b-evidence-source.php', 'check_id' => 'P3B2B-CERTIFICATE-REFERENCE-INDEPENDENT-GOLDEN' ),
		'ACTIVATION-D16-ORIGINAL-TASK-RECOVERY-PRESERVED' => array( 'suite' => 'tests/archive/test-p3b2a-evidence-persistence.php', 'check_id' => 'P3B2A-ATTEMPT5-DETERMINISTIC-RECOVERY' ),
		'ACTIVATION-DISABLE-COMMITTED-OBJECTS-UNCHANGED' => array( 'suite' => 'tests/archive/test-p3-storage.php', 'check_id' => 'ARTIFACT-COMMIT-NEVER-OVERWRITES' ),
		'ACTIVATION-DISABLE-ORPHAN-REPORT-ONLY' => array( 'suite' => 'tests/archive/test-p3-storage.php', 'check_id' => 'ORPHAN-REPORT-ONLY-NO-MUTATION' ),
		'ACTIVATION-DISABLE-RETAINED-ROWS-UNCHANGED' => array( 'suite' => 'tests/archive/test-p3b3-activation-gates.php', 'check_id' => 'P3B3-ROLLBACK-PRESERVES-RETAINED-DATA' ),
		'ACTIVATION-ORPHAN-REMAINS-REPORT-ONLY' => array( 'suite' => 'tests/archive/test-p3-storage.php', 'check_id' => 'ORPHAN-REPORT-ONLY-NO-MUTATION' ),
		'ACTIVATION-RETAINED-P3B3-SUITE-NAMES-EXACT' => array( 'suite' => 'tests/archive/test-p3b3-activation-gates.php', 'check_id' => 'P3B3-RUNNER-SUITES-EXACTLY-ONCE' ),
		'ACTIVATION-ROLLBACK-LEASE-FENCE-PRESERVED' => array( 'suite' => 'tests/archive/test-p3b3-worker-runtime.php', 'check_id' => 'P3B3-STALE-WORKER-CANNOT-OUTCOME-OR-COMPLETE' ),
		'ACTIVATION-ROLLBACK-NO-LIFECYCLE-INVENTION' => array( 'suite' => 'tests/archive/test-p3b2a-evidence-persistence.php', 'check_id' => 'P3B2A-OPERATIONAL-FAILURES-NO-LIFECYCLE' ),
		'ACTIVATION-ROLLBACK-RECEIPT-REPLAY' => array( 'suite' => 'tests/archive/test-p3b2a-evidence-persistence.php', 'check_id' => 'P3B2A-RESPONSE-LOSS-REPLAY' ),
		'ACTIVATION-SCHEDULER-DATABASE-LEASES-AUTHORITATIVE' => array( 'suite' => 'tests/archive/test-p3-worker.php', 'check_id' => 'TASK-TWO-CONNECTION-LEASE-RACE' ),
		'ACTIVATION-SOURCE-ARCHIVE-CONNECTIONS-DISTINCT' => array( 'suite' => 'tests/archive/test-p3b3-runtime-composition.php', 'check_id' => 'P3B3-EVIDENCE-CONNECTION-DISTINCT' ),
		'ACTIVATION-SOURCE-ARCHIVE-READS-DENIED' => array( 'suite' => 'tests/archive/test-p3b2b-evidence-source-persistence.php', 'check_id' => 'P3B2B-RESTRICTED-SOURCE-PRINCIPAL-CANNOT-WRITE-OR-READ-ARCHIVE' ),
		'ACTIVATION-SOURCE-CONNECTION-CLOSED' => array( 'suite' => 'tests/archive/test-p3b3-activation-contracts.php', 'check_id' => 'ACTIVATION-SOURCE-CONNECTION-CLOSED' ),
		'ACTIVATION-SOURCE-GRANTS-EXACT-EIGHT' => array( 'suite' => 'tests/archive/test-p3b2b-evidence-source.php', 'check_id' => 'P3B2B-EXACT-USAGE-AND-SEVEN-TABLE-SELECT-GRANTS-ONLY' ),
		'ACTIVATION-SOURCE-GRANT-SHAPES-REJECTED' => array( 'suite' => 'tests/archive/test-p3b2b-evidence-source.php', 'check_id' => 'P3B2B-EXACT-USAGE-AND-SEVEN-TABLE-SELECT-GRANTS-ONLY' ),
		'ACTIVATION-SOURCE-PREFLIGHT-NO-EVIDENCE-QUERY' => array( 'suite' => 'tests/archive/test-p3b3-activation-contracts.php', 'check_id' => 'ACTIVATION-SOURCE-PREFLIGHT-NO-EVIDENCE-QUERY' ),
		'ACTIVATION-SOURCE-WRITES-DENIED' => array( 'suite' => 'tests/archive/test-p3b2b-evidence-source-persistence.php', 'check_id' => 'P3B2B-RESTRICTED-SOURCE-PRINCIPAL-CANNOT-WRITE-OR-READ-ARCHIVE' ),
		'ACTIVATION-STORAGE-PRIVATE-PUBLIC-DISJOINT' => array( 'suite' => 'tests/archive/test-p3b3-worker-runtime.php', 'check_id' => 'P3B3-PRIVATE-ROOT-OUTSIDE-ALL-PUBLIC-ROOTS' ),
	),
	'DEFERRED_OPERATOR_EVIDENCE' => array(
		'ACTIVATION-ALLOWLIST-EXACT',
		'ACTIVATION-APPROVALS-THREE-SEPARATE',
		'ACTIVATION-ATTEST-FILE-DIGEST-EVIDENCE',
		'ACTIVATION-AUTHORIZATION-DIGEST-BINDS-HUMAN-APPROVAL',
		'ACTIVATION-AUTHORIZATION-REMOVED-ON-ROLLBACK',
		'ACTIVATION-BACKUP-RESTORE-DATABASE-OBJECT-CONSISTENCY',
		'ACTIVATION-CONTROLLED-25-REVISION-CEILING',
		'ACTIVATION-CONTROLLED-CERTIFICATE-CASES-EXCLUDED',
		'ACTIVATION-CONTROLLED-ONE-BLOG-ONLY',
		'ACTIVATION-CONTROLLED-ONE-WORKER',
		'ACTIVATION-CONTROLLED-STOP-CONDITIONS',
		'ACTIVATION-CONTROLLED-WINDOW-BOUND',
		'ACTIVATION-CURSOR-ROTATION-FLAGS-OFF',
		'ACTIVATION-DECISION-APPROVAL-NO-SITE-AUTHORITY',
		'ACTIVATION-EVIDENCE-CANONICAL-64K',
		'ACTIVATION-EVIDENCE-FIELD-ALLOWLIST',
		'ACTIVATION-EVIDENCE-PII-SECRET-PATH-ABSENT',
		'ACTIVATION-EVIDENCE-RAW-STDERR-ABSENT',
		'ACTIVATION-EVIDENCE-RETAINED-PAYLOAD-ABSENT',
		'ACTIVATION-FLAGS-ORDER-DISABLE',
		'ACTIVATION-FLAGS-ORDER-ENABLE',
		'ACTIVATION-MANUAL-PREFLIGHT-SCHEDULER-DISABLED',
		'ACTIVATION-MULTISITE-SEQUENTIAL-CANARY',
		'ACTIVATION-NO-SCHEMA-METADATA-NETWORK',
		'ACTIVATION-PACKET-GOLDEN-VECTOR-GATE',
		'ACTIVATION-PACKET-RENDERER-OWNER-GATE',
		'ACTIVATION-PACKET-SEALED-INPUTS-ONLY',
		'ACTIVATION-PRODUCTION-CERTIFICATE-PACKET-VERIFY-GATED',
		'ACTIVATION-PRODUCTION-CONTROLLED-EVIDENCE-INSUFFICIENT',
		'ACTIVATION-PRODUCTION-D16-GATED',
		'ACTIVATION-PRODUCTION-EVIDENCE-COMPLETE',
		'ACTIVATION-PRODUCTION-RPO-RTO-EVIDENCE',
		'ACTIVATION-ROLLBACK-REAUTHORIZATION-REQUIRED',
		'ACTIVATION-ROLLBACK-TRIGGER-CLOSED',
		'ACTIVATION-SCHEDULER-110-SECOND-TIMEOUT',
		'ACTIVATION-SCHEDULER-24-HOUR-SOAK',
		'ACTIVATION-SCHEDULER-BACKLOG-FIVE-RUNS',
		'ACTIVATION-SCHEDULER-CONTROLLED-ONE-ACTIVE',
		'ACTIVATION-SCHEDULER-EXACT-COMMAND',
		'ACTIVATION-SCHEDULER-MISSED-THREE-MINUTES',
		'ACTIVATION-SCHEDULER-ONE-MINUTE',
		'ACTIVATION-SCHEDULER-PRODUCTION-MAX-FIVE-ACTIVE',
		'ACTIVATION-SCHEDULER-URL-IS-BOOTSTRAP-SELECTOR',
		'ACTIVATION-STDERR-ORDINARY-LOG-EXCLUDED',
		'ACTIVATION-STORAGE-CONTROLLED-THRESHOLD-EXACT',
		'ACTIVATION-STORAGE-LOW-DISK-BLOCKED',
		'ACTIVATION-STORAGE-PERMISSIONS-CLOSED',
		'ACTIVATION-STORAGE-PRODUCTION-CAPACITY-GATED',
		'ACTIVATION-VERIFY-FINALIZE-ATOMIC',
		'ACTIVATION-VERIFY-RECEIPT-REPLAY',
		'ACTIVATION-VERIFY-SEALED-AUTHORITY-RELOAD',
	),
);
$runner_source = file_get_contents( __DIR__ . '/test-all.ps1' );
$mapping_error = p3b3a_evidence_manifest_error( $evidence_manifest, $approved_names, (string) $runner_source, dirname( __DIR__, 2 ) );
$first_requirement = array_key_first( $evidence_manifest['EXECUTED_PASS'] );
$first_evidence = $evidence_manifest['EXECUTED_PASS'][ $first_requirement ];

$probe = $evidence_manifest;
$probe['EXECUTED_PASS'][ $first_requirement ]['check_id'] = $first_requirement;
archive_check(
	'check_missing' === p3b3a_evidence_manifest_error( $probe, $approved_names, (string) $runner_source, dirname( __DIR__, 2 ) ),
	'ACTIVATION-EVIDENCE-MAPPING-MISSING-CHECK-REJECTED proves classification-array text is not assertion evidence'
);

$probe = $evidence_manifest;
$probe['EXECUTED_PASS'][ $first_requirement ]['suite'] = 'tests/archive/test-invented-suite.php';
archive_check(
	'suite_missing' === p3b3a_evidence_manifest_error( $probe, $approved_names, (string) $runner_source, dirname( __DIR__, 2 ) ),
	'ACTIVATION-EVIDENCE-MAPPING-MISSING-SUITE-REJECTED rejects a non-runner suite before accepting evidence'
);

archive_check(
	'suite_runner_count' === p3b3a_evidence_manifest_error(
		$evidence_manifest,
		$approved_names,
		(string) $runner_source . "\n" . $first_evidence['suite'],
		dirname( __DIR__, 2 )
	),
	'ACTIVATION-EVIDENCE-MAPPING-DUPLICATE-SUITE-REJECTED requires exactly one runner occurrence'
);

$probe = $evidence_manifest;
$probe['EXECUTED_PASS'][ $first_requirement ]['check_id'] = 'ACTIVATION-EVIDENCE-MAPPING-SELF-REFERENCE-REJECTED';
archive_check(
	'self_reference' === p3b3a_evidence_manifest_error( $probe, $approved_names, (string) $runner_source, dirname( __DIR__, 2 ) ),
	'ACTIVATION-EVIDENCE-MAPPING-SELF-REFERENCE-REJECTED forbids a meta-check from supporting the manifest'
);

$parser_fixture = $test_root . '\\assertion-parser-condition.php';
file_put_contents(
	$parser_fixture,
	"<?php\narchive_check( in_array( 'ACTIVATION-PARSER-CONDITION-LITERAL', array( 'ACTIVATION-PARSER-NESTED-LITERAL' ), true ), 'ACTIVATION-PARSER-SECOND-ARGUMENT is the real label' );\n"
);
$parser_ids = p3b3a_assertion_ids( $parser_fixture );
archive_check(
	! in_array( 'ACTIVATION-PARSER-CONDITION-LITERAL', $parser_ids, true )
		&& ! in_array( 'ACTIVATION-PARSER-NESTED-LITERAL', $parser_ids, true )
		&& in_array( 'ACTIVATION-PARSER-SECOND-ARGUMENT', $parser_ids, true ),
	'ACTIVATION-EVIDENCE-MAPPING-CONDITION-LITERAL-REJECTED accepts only the real second-argument label'
);

$probe = $evidence_manifest;
$deferred_requirement = $probe['DEFERRED_OPERATOR_EVIDENCE'][0];
$probe['DEFERRED_OPERATOR_EVIDENCE'][0] = $first_evidence;
archive_check(
	'deferred_mapping' === p3b3a_evidence_manifest_error( $probe, $approved_names, (string) $runner_source, dirname( __DIR__, 2 ) )
		&& 71 === count( $evidence_manifest['EXECUTED_PASS'] ) + count( $evidence_manifest['RETAINED_PASS'] )
		&& 51 === count( $evidence_manifest['DEFERRED_OPERATOR_EVIDENCE'] )
		&& is_string( $deferred_requirement ),
	'ACTIVATION-EVIDENCE-DEFERRED-NOT-COUNTED gives deferred requirements no executable mapping or pass total'
);

$probe = $evidence_manifest;
$probe['RETAINED_PASS'][ $first_requirement ] = $first_evidence;
archive_check(
	'duplicate_requirement' === p3b3a_evidence_manifest_error( $probe, $approved_names, (string) $runner_source, dirname( __DIR__, 2 ) ),
	'ACTIVATION-EVIDENCE-MAPPING-DUPLICATE-REQUIREMENT-REJECTED forbids one requirement in multiple classifications'
);

archive_check(
	null === $mapping_error
		&& 52 === count( $evidence_manifest['EXECUTED_PASS'] )
		&& 19 === count( $evidence_manifest['RETAINED_PASS'] )
		&& 51 === count( $evidence_manifest['DEFERRED_OPERATOR_EVIDENCE'] ),
	'ACTIVATION-EVIDENCE-MANIFEST-122-CLASSIFIED resolves every non-deferred requirement to one real runner assertion'
);
fwrite(
	STDOUT,
	'ACTIVATION_EVIDENCE_MANIFEST=EXECUTED_PASS:' . count( $evidence_manifest['EXECUTED_PASS'] )
		. ',RETAINED_PASS:' . count( $evidence_manifest['RETAINED_PASS'] )
		. ',DEFERRED_OPERATOR_EVIDENCE:' . count( $evidence_manifest['DEFERRED_OPERATOR_EVIDENCE'] ) . "\n"
);

@unlink( $parser_fixture ); @unlink( $authorization_path ); @rmdir( $private_root ); @rmdir( $test_root );
archive_finish();
