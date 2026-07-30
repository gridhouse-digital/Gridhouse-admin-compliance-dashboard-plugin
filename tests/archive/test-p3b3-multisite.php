<?php
require_once __DIR__ . '/persistence-bootstrap.php';

if ( isset( $argv[1] ) && 'tenant-race-child' === $argv[1] ) {
	$wpdb->suppress_errors( true );
	try {
		$tenant = ( new GHCA_ACD_WordPress_Archive_Runtime_Descriptor( $wpdb ) )->provision_tenant();
		fwrite( STDOUT, 'TENANT=' . $tenant . "\n" );
		exit( 0 );
	} catch ( Throwable $error ) {
		fwrite( STDERR, "TENANT_RACE_FAILED\n" );
		exit( 1 );
	}
}

$restricted_user = getenv( 'GHCA_TEST_RESTRICTED_DB_USER' );
$restricted_password = getenv( 'GHCA_TEST_RESTRICTED_DB_PASSWORD' );
if ( ! is_string( $restricted_user ) || 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,31}$/D', $restricted_user )
	|| ! is_string( $restricted_password ) || 32 !== strlen( $restricted_password ) ) {
	throw new RuntimeException( 'Disposable restricted-source credentials are invalid.' );
}

$private_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ghca-acd-p3b3-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $private_root, 0700 ) ) {
	throw new RuntimeException( 'Could not create isolated private test root.' );
}

define( 'GHCA_ACD_ARCHIVE_RUNTIME_MODE', 'controlled_testing' );
define( 'GHCA_ACD_ARCHIVE_SOURCE_DB_HOST', (string) getenv( 'GHCA_TEST_DB_HOST' ) );
define( 'GHCA_ACD_ARCHIVE_SOURCE_DB_NAME', (string) getenv( 'GHCA_TEST_DB_NAME' ) );
define( 'GHCA_ACD_ARCHIVE_SOURCE_DB_USER', $restricted_user );
define( 'GHCA_ACD_ARCHIVE_SOURCE_DB_PASSWORD', $restricted_password );
define( 'GHCA_ACD_ARCHIVE_SOURCE_DB_ACCOUNT', $restricted_user . '@%' );
define( 'GHCA_ACD_ARCHIVE_PRIVATE_DIR', $private_root );
define( 'GHCA_ACD_ARCHIVE_PUBLIC_DOCUMENT_ROOT', rtrim( ABSPATH, '/\\' ) );
define( 'GHCA_ACD_ARCHIVE_CURSOR_HMAC_KEY', str_repeat( 'a', 64 ) );

/** @param object $db */
function p3b3m_options_clear( $db ): void {
	$table = ghca_persist_quote_identifier( $db->prefix . 'options' );
	ghca_persist_query(
		$db,
		"DELETE FROM {$table} WHERE option_name IN (
			'ghca_acd_archive_schema_version',
			'ghca_acd_archive_enabled',
			'ghca_acd_archive_dual_layer',
			'ghca_acd_archive_reset_enabled',
			'ghca_acd_archive_tenant_id'
		)",
		'clear P3B3 disposable options'
	);
}

/** @param object $db */
function p3b3m_option( $db, string $name, string $value ): void {
	$result = $db->insert(
		$db->prefix . 'options',
		array( 'option_name' => $name, 'option_value' => $value, 'autoload' => 'no' ),
		array( '%s', '%s', '%s' )
	);
	if ( false === $result ) {
		throw new RuntimeException( 'Could not insert disposable P3B3 option.' );
	}
}

/** @param object $db */
function p3b3m_source_tables( $db ): array {
	$tables = array(
		$db->base_prefix . 'users',
		$db->base_prefix . 'usermeta',
		$db->prefix . 'options',
		$db->prefix . 'posts',
		$db->prefix . 'postmeta',
		$db->prefix . 'learndash_user_activity',
		$db->prefix . 'learndash_user_activity_meta',
	);
	foreach ( $tables as $table ) {
		if ( $db->prefix . 'options' === $table ) {
			continue;
		}
		$quoted = ghca_persist_quote_identifier( $table );
		ghca_persist_query( $db, "DROP TABLE IF EXISTS {$quoted}", 'drop isolated P3B3 source fixture' );
		ghca_persist_query(
			$db,
			"CREATE TABLE {$quoted} (id bigint unsigned NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB",
			'create isolated P3B3 source fixture'
		);
	}
	return $tables;
}

/** @param object $db */
function p3b3m_stream( $db, string $tenant ): void {
	$db->insert(
		$db->prefix . 'ghca_acd_archive_streams',
		array(
			'stream_id' => str_repeat( '1', 32 ),
			'case_key_digest' => str_repeat( '2', 64 ),
			'case_key_format_version' => 1,
			'tenant_id' => $tenant,
			'site_id' => 1,
			'employee_user_id' => 42,
			'program_key' => 'annual_training',
			'cycle_key' => '2026',
			'cycle_key_digest' => str_repeat( '3', 64 ),
			'cycle_start_gmt' => '2026-01-01 00:00:00',
			'cycle_end_gmt' => '2027-01-01 00:00:00',
			'cycle_timezone' => 'UTC',
			'cycle_policy_key' => 'calendar_year',
			'head_sequence' => 0,
			'head_event_digest' => null,
			'created_at_gmt' => '2026-07-30 00:00:00',
			'updated_at_gmt' => '2026-07-30 00:00:00',
		)
	);
	if ( ! empty( $db->last_error ) ) {
		throw new RuntimeException( 'Could not insert disposable tenant-binding stream.' );
	}
}

/** @return array{process:resource,pipes:array<int,resource>} */
function p3b3m_start_tenant_child(): array {
	$pipes = array();
	$process = proc_open(
		array( PHP_BINARY, __FILE__, 'tenant-race-child' ),
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		__DIR__,
		null,
		array( 'bypass_shell' => true )
	);
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Could not start the disposable tenant-race child.' );
	}
	fclose( $pipes[0] );
	return array( 'process' => $process, 'pipes' => $pipes );
}

/** @param array{process:resource,pipes:array<int,resource>} $child */
function p3b3m_finish_tenant_child( array $child ): array {
	$stdout = stream_get_contents( $child['pipes'][1] );
	$stderr = stream_get_contents( $child['pipes'][2] );
	fclose( $child['pipes'][1] );
	fclose( $child['pipes'][2] );
	$exit = proc_close( $child['process'] );
	$tenant = is_string( $stdout ) && 1 === preg_match( '/^TENANT=([a-f0-9]{32})\r?\n$/D', $stdout, $match )
		? $match[1]
		: '';
	return array(
		'exit' => $exit,
		'stderr_empty' => '' === $stderr,
		'tenant' => $tenant,
	);
}

$source_tables = array();
$restricted = null;
$source_user_host = $wpdb->prepare( '%s@%s', $restricted_user, '%' );
try {
	ghca_persist_fresh_schema( $wpdb );
	p3b3m_options_clear( $wpdb );
	$wpdb->suppress_errors( true );
	$options_table = ghca_persist_quote_identifier( $wpdb->prefix . 'options' );
	$index_rejected = false;
	ghca_persist_query( $wpdb, "ALTER TABLE {$options_table} DROP INDEX option_name", 'remove disposable option-name index' );
	try {
		( new GHCA_ACD_WordPress_Archive_Runtime_Descriptor( $wpdb ) )->provision_tenant();
	} catch ( UnexpectedValueException $error ) {
		$index_rejected = 'archive_runtime_tenant_invalid' === $error->getMessage();
	} finally {
		ghca_persist_query(
			$wpdb,
			"ALTER TABLE {$options_table} ADD UNIQUE KEY option_name (option_name)",
			'restore disposable option-name index'
		);
	}
	archive_check(
		$index_rejected,
		'P3B3-TENANT-OPTION-NAME-UNIQUE-INDEX-REQUIRED rejects provisioning without the exact unique index'
	);

	$trigger = ghca_persist_quote_identifier( 'ghca_p3b3_tenant_insert_fail' );
	ghca_persist_query( $wpdb, "DROP TRIGGER IF EXISTS {$trigger}", 'drop prior disposable tenant trigger' );
	ghca_persist_query(
		$wpdb,
		"CREATE TRIGGER {$trigger} BEFORE INSERT ON {$options_table}
		FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced tenant insert failure'",
		'create disposable tenant insert failure'
	);
	$nonduplicate_rejected = false;
	try {
		( new GHCA_ACD_WordPress_Archive_Runtime_Descriptor( $wpdb ) )->provision_tenant();
	} catch ( UnexpectedValueException $error ) {
		$nonduplicate_rejected = 'archive_runtime_tenant_invalid' === $error->getMessage();
	} finally {
		ghca_persist_query( $wpdb, "DROP TRIGGER IF EXISTS {$trigger}", 'drop disposable tenant insert failure' );
	}
	archive_check(
		$nonduplicate_rejected,
		'P3B3-TENANT-NONDUPLICATE-INSERT-FAILS-CLOSED distinguishes an operational insert error from a duplicate race'
	);

	$children = array();
	$race_blocked = false;
	ghca_persist_query( $wpdb, "LOCK TABLES {$options_table} READ", 'hold disposable tenant-race writes' );
	try {
		$children[] = p3b3m_start_tenant_child();
		$children[] = p3b3m_start_tenant_child();
		$deadline = microtime( true ) + 10.0;
		do {
			$waiting = 0;
			$processes = $wpdb->get_results( 'SHOW FULL PROCESSLIST', ARRAY_A );
			foreach ( is_array( $processes ) ? $processes : array() as $process ) {
				$info = isset( $process['Info'] ) && is_string( $process['Info'] ) ? $process['Info'] : '';
				if ( false !== stripos( $info, 'INSERT INTO `' . $wpdb->prefix . 'options`' )
					&& false !== stripos( $info, 'ghca_acd_archive_tenant_id' ) ) {
					$waiting++;
				}
			}
			$race_blocked = 2 === $waiting;
			if ( ! $race_blocked ) {
				usleep( 10000 );
			}
		} while ( ! $race_blocked && microtime( true ) < $deadline );
	} finally {
		ghca_persist_query( $wpdb, 'UNLOCK TABLES', 'release disposable tenant-race writes' );
	}
	$first_race = p3b3m_finish_tenant_child( $children[0] );
	$second_race = p3b3m_finish_tenant_child( $children[1] );
	$tenant = $first_race['tenant'];
	$descriptor = new GHCA_ACD_WordPress_Archive_Runtime_Descriptor( $wpdb );

	archive_check(
		$race_blocked
			&& 0 === $first_race['exit'] && 0 === $second_race['exit']
			&& $first_race['stderr_empty'] && $second_race['stderr_empty']
			&& 1 === preg_match( '/^[a-f0-9]{32}$/D', $tenant )
			&& $tenant === $second_race['tenant']
			&& 1 === (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}options WHERE option_name = %s AND autoload = %s",
					'ghca_acd_archive_tenant_id',
					'no'
				)
			),
		'P3B3-TENANT-GENERATED-ONCE-NON-AUTOLOAD creates one immutable site value'
	);
	archive_check(
		$race_blocked && $tenant === $second_race['tenant'],
		'P3B3-TENANT-CONCURRENT-CREATION-ONE-IMMUTABLE-WINNER blocks two real processes at INSERT and converges after release'
	);

	p3b3m_option( $wpdb, 'ghca_acd_archive_schema_version', GHCA_ACD_Archive_Schema::CURRENT_VERSION );
	p3b3m_option( $wpdb, 'ghca_acd_archive_enabled', '1' );
	p3b3m_option( $wpdb, 'ghca_acd_archive_dual_layer', '1' );
	p3b3m_option( $wpdb, 'ghca_acd_archive_reset_enabled', '0' );
	p3b3m_stream( $wpdb, $tenant );

	$source_tables = p3b3m_source_tables( $wpdb );
	ghca_persist_query( $wpdb, "DROP USER IF EXISTS {$source_user_host}", 'drop prior isolated P3B3 source user' );
	ghca_persist_query(
		$wpdb,
		$wpdb->prepare( "CREATE USER {$source_user_host} IDENTIFIED BY %s", $restricted_password ),
		'create isolated P3B3 source user'
	);
	foreach ( $source_tables as $table ) {
		ghca_persist_query(
			$wpdb,
			'GRANT SELECT ON ' . ghca_persist_quote_identifier( getenv( 'GHCA_TEST_DB_NAME' ) ) . '.' . ghca_persist_quote_identifier( $table ) . " TO {$source_user_host}",
			'grant isolated P3B3 source table'
		);
	}

	$resolved = $descriptor->resolve();
	$evidence = $resolved['evidence_descriptor'];
	archive_check(
		1 === $evidence['blog_id'] && '1' === $evidence['site_id']
			&& 'wp_' === $evidence['base_prefix'] && 'wp_' === $evidence['blog_prefix']
			&& 'wp_options' === $evidence['options_table']
			&& $tenant === $evidence['tenant_id'],
		'P3B3-BLOG-ID-PREFIX-TABLE-DESCRIPTOR-EXACT derives one current-blog binding'
	);
	archive_check(
		array_keys( $evidence ) === array(
			'base_prefix', 'blog_id', 'blog_prefix', 'capabilities_meta_key',
			'learndash_user_activity_meta_table', 'learndash_user_activity_table',
			'options_table', 'postmeta_table', 'posts_table', 'site_id',
			'source_database', 'tenant_id', 'user_roles_option_name', 'usermeta_table', 'users_table',
		),
		'P3B3-TASK-CANNOT-SUPPLY-TENANT-TABLE-OR-PREFIX accepts no caller descriptor field'
	);

	$wpdb->update(
		$wpdb->prefix . 'options',
		array( 'option_value' => str_repeat( 'b', 32 ) ),
		array( 'option_name' => 'ghca_acd_archive_tenant_id' ),
		array( '%s' ),
		array( '%s' )
	);
	$replacement_rejected = false;
	try {
		$descriptor->resolve();
	} catch ( UnexpectedValueException $error ) {
		$replacement_rejected = 'archive_runtime_tenant_invalid' === $error->getMessage();
	}
	archive_check(
		$replacement_rejected,
		'P3B3-TENANT-REPLACEMENT-OR-STREAM-MISMATCH-REJECTED binds retained streams to the original tenant'
	);
	$wpdb->update(
		$wpdb->prefix . 'options',
		array( 'option_value' => 'INVALID' ),
		array( 'option_name' => 'ghca_acd_archive_tenant_id' ),
		array( '%s' ),
		array( '%s' )
	);
	$malformed_rejected = false;
	try {
		$descriptor->resolve();
	} catch ( UnexpectedValueException $error ) {
		$malformed_rejected = 'archive_runtime_tenant_invalid' === $error->getMessage();
	}
	archive_check(
		$malformed_rejected,
		'P3B3-TENANT-MALFORMED-OR-CHANGED-FAILS-CLOSED rejects invalid immutable authority'
	);
	$wpdb->update(
		$wpdb->prefix . 'options',
		array( 'option_value' => $tenant ),
		array( 'option_name' => 'ghca_acd_archive_tenant_id' ),
		array( '%s' ),
		array( '%s' )
	);

	$descriptor_source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/archive/infrastructure/class-wordpress-archive-runtime-descriptor.php' );
	archive_check(
		false !== strpos( $descriptor_source, '$prefix !== $derived' )
			&& false === strpos( $descriptor_source, 'switch_to_blog' ),
		'P3B3-MIXED-BLOG-OR-SWITCHED-CONTEXT-REJECTED permits one blog per process'
	);
	archive_check(
		strpos( $descriptor_source, 'assert_flag' ) < strpos( $descriptor_source, 'source_configuration' ),
		'P3B3-SOURCE-CREDENTIALS-REQUIRED-ONLY-AFTER-GATES delays secret reads'
	);
	archive_check(
		0 === preg_match( '/error_log|fwrite|echo|print_r|var_dump|update_option|INSERT[^\\n]+password/i', $descriptor_source ),
		'P3B3-SOURCE-CREDENTIALS-NEVER-LOGGED-PERSISTED-OR-RETAINED keeps secrets process-local'
	);
	archive_check(
		GHCA_ACD_ARCHIVE_SOURCE_DB_HOST === $resolved['archive_connection']['host']
			&& GHCA_ACD_ARCHIVE_SOURCE_DB_NAME === $resolved['archive_connection']['database'],
		'P3B3-SOURCE-ENDPOINT-AND-DATABASE-EXACT-ARCHIVE-MATCH binds both connections to one declared target'
	);

	$restricted = new wpdb(
		$restricted_user,
		$restricted_password,
		getenv( 'GHCA_TEST_DB_NAME' ),
		getenv( 'GHCA_TEST_DB_HOST' )
	);
	$restricted->suppress_errors( true );
	$identity = $restricted->get_row( 'SELECT CURRENT_USER() AS account', ARRAY_A );
	$grants = $restricted->get_results( 'SHOW GRANTS FOR CURRENT_USER()', ARRAY_A );
	archive_check(
		is_array( $identity ) && GHCA_ACD_ARCHIVE_SOURCE_DB_ACCOUNT === $identity['account']
			&& $identity['account'] !== $resolved['archive_connection']['current_user']
			&& 8 === count( $grants ),
		'P3B3-SOURCE-PRINCIPAL-DISTINCT-EXACT-SEVEN-TABLE-SELECT proves isolated least privilege'
	);
	archive_check(
		8 === count( $grants ),
		'P3B3-SOURCE-GRANTS-MYSQL80-MYSQL84-MARIADB106 accepts the exact portable grant set in this cell'
	);
	$grant_text = implode( "\n", array_map( static function ( array $row ): string {
		return (string) reset( $row );
	}, $grants ) );
	archive_check(
		0 === preg_match( '/GRANT SELECT ON [`\\w]+\\.\\*|GRANT SELECT ON \\*\\.\\*/i', $grant_text ),
		'P3B3-SOURCE-DATABASE-WIDE-OR-WILDCARD-GRANT-REJECTED grants only named tables'
	);
	$archive_grant_absent = true;
	foreach ( $resolved['archive_tables'] as $archive_table ) {
		$archive_grant_absent = $archive_grant_absent
			&& false === strpos( $grant_text, '.`' . $archive_table . '`' );
	}
	archive_check(
		$archive_grant_absent,
		'P3B3-SOURCE-ARCHIVE-TABLE-GRANT-REJECTED grants no retained archive object'
	);
	archive_check(
		0 === preg_match( '/WITH GRANT OPTION|GRANT .*\\b(?:ROLE|EXECUTE|PROCESS|FILE|SUPER)\\b/i', $grant_text ),
		'P3B3-SOURCE-ROLE-EXTRA-OR-UNEXPECTED-PRIVILEGE-REJECTED has no inherited or administrative authority'
	);

	$restricted->last_error = '';
	$write_denied = false === $restricted->query(
		'INSERT INTO ' . ghca_persist_quote_identifier( $source_tables[0] ) . ' (id) VALUES (1)'
	);
	archive_check(
		$write_denied,
		'P3B3-SOURCE-INSERT-UPDATE-DELETE-DDL-DENIED proves the source principal is read-only'
	);
	$archive_denied = true;
	foreach ( GHCA_ACD_WordPress_Archive_Runtime_Descriptor::ARCHIVE_TABLES as $suffix ) {
		$archive_denied = $archive_denied && false === $restricted->query(
			'SELECT 1 FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . $suffix ) . ' LIMIT 1'
		);
	}
	archive_check(
		$archive_denied,
		'P3B3-SOURCE-ARCHIVE-TABLE-SELECT-DENIED proves the source cannot read retained archive data'
	);
} finally {
	if ( $restricted instanceof wpdb ) {
		$restricted->close();
	}
	ghca_persist_query( $wpdb, "DROP USER IF EXISTS {$source_user_host}", 'drop isolated P3B3 source user' );
	foreach ( $source_tables as $table ) {
		if ( $wpdb->prefix . 'options' === $table ) {
			continue;
		}
		ghca_persist_query(
			$wpdb,
			'DROP TABLE IF EXISTS ' . ghca_persist_quote_identifier( $table ),
			'drop isolated P3B3 source fixture'
		);
	}
	if ( is_dir( $private_root ) ) {
		rmdir( $private_root );
	}
}

archive_finish();
