<?php
/**
 * Destructive disposable-database tests for the Slice 1B schema migration.
 */

$host            = getenv( 'GHCA_TEST_DB_HOST' );
$user            = getenv( 'GHCA_TEST_DB_USER' );
$pass            = getenv( 'GHCA_TEST_DB_PASSWORD' );
$name            = getenv( 'GHCA_TEST_DB_NAME' );
$opt_in          = getenv( 'GHCA_TEST_DESTRUCTIVE_OPT_IN' );
$restricted_user = getenv( 'GHCA_TEST_RESTRICTED_DB_USER' );
$restricted_pass = getenv( 'GHCA_TEST_RESTRICTED_DB_PASSWORD' );

$required = array(
	'GHCA_TEST_DB_HOST'                => $host,
	'GHCA_TEST_DB_USER'                => $user,
	'GHCA_TEST_DB_PASSWORD'            => $pass,
	'GHCA_TEST_DB_NAME'                => $name,
	'GHCA_TEST_DESTRUCTIVE_OPT_IN'      => $opt_in,
	'GHCA_TEST_RESTRICTED_DB_USER'      => $restricted_user,
	'GHCA_TEST_RESTRICTED_DB_PASSWORD'  => $restricted_pass,
);
foreach ( $required as $variable => $value ) {
	if ( $value === false || $value === '' ) {
		fwrite( STDERR, "FAIL: Missing required environment variable {$variable}\n" );
		exit( 1 );
	}
}
if ( ! preg_match( '/^ghca_acd_archive_test_[A-Za-z0-9_]+$/', $name ) ) {
	fwrite( STDERR, "FAIL: Database name must use the ghca_acd_archive_test_ safety prefix\n" );
	exit( 1 );
}
if ( ! preg_match( '/^127\.0\.0\.1:(33061|33062|33063)$/', $host ) ) {
	fwrite( STDERR, "FAIL: Database host must be a declared loopback disposable target\n" );
	exit( 1 );
}
if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $restricted_user ) ) {
	fwrite( STDERR, "FAIL: Restricted database username contains unsafe characters\n" );
	exit( 1 );
}
if ( ! in_array( strtolower( $opt_in ), array( '1', 'yes', 'true' ), true ) ) {
	fwrite( STDERR, "FAIL: GHCA_TEST_DESTRUCTIVE_OPT_IN must explicitly opt in\n" );
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__, 5 ) . '/' );
define( 'WP_INSTALLING', true );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_LANG_DIR', ABSPATH . 'wp-content/languages' );
define( 'SAVEQUERIES', true );

function is_multisite() { return false; }
function wp_is_fatal_error_handler_enabled() { return false; }
function get_option( $option, $default = false ) { return $default; }
function update_option() { return true; }
function delete_option() { return true; }
function is_admin() { return false; }
function get_site_option( $option, $default = false ) { return $default; }
function wp_suspend_cache_addition() {}
function is_network_admin() { return false; }
function is_user_admin() { return false; }
function wp_roles() {}
function wp_is_maintenance_mode() { return false; }
function wp_is_recovery_mode() { return false; }
function get_bloginfo() { return ''; }
function get_site_url() { return ''; }
function is_wp_error() { return false; }
function wp_guess_url() { return ''; }
function get_site_transient() { return false; }
function set_site_transient() { return false; }
function delete_site_transient() { return false; }
function absint( $maybeint ) { return abs( intval( $maybeint ) ); }
function get_locale() { return 'en_US'; }
function load_textdomain() { return true; }
function load_default_textdomain() { return true; }
function load_plugin_textdomain() { return true; }
function mbstring_binary_safe_encoding() { return false; }
function reset_mbstring_encoding() { return false; }
function wp_debug_backtrace_summary() { return ''; }
function is_customize_preview() { return false; }
function get_userdata() { return false; }
function wp_get_current_user() { return (object) array( 'ID' => 0 ); }
function is_wp_version_compatible() { return true; }
function is_php_version_compatible() { return true; }
function set_url_scheme( $url ) { return $url; }
function wp_die( $message = '', $title = '', $args = array() ) {
	throw new RuntimeException( 'wp_die: ' . print_r( $message, true ) );
}
function __( $text, $domain = 'default' ) { return $text; }
function esc_html__( $text ) { return $text; }

require_once ABSPATH . 'wp-includes/plugin.php';
require_once ABSPATH . 'wp-includes/formatting.php';
require_once ABSPATH . 'wp-includes/wp-db.php';

global $wpdb;
$wpdb = new wpdb( $user, $pass, $name, $host );
if ( ! empty( $wpdb->error ) ) {
	fwrite( STDERR, "FAIL: Could not connect to disposable database\n" );
	exit( 1 );
}
$wpdb->set_prefix( 'wp_' );
$wpdb->charset = 'utf8mb4';
$wpdb->collate = 'utf8mb4_unicode_ci';
$wpdb->set_charset( $wpdb->dbh, $wpdb->charset, $wpdb->collate );

require_once ABSPATH . 'wp-includes/class-wp-walker.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once __DIR__ . '/../../includes/archive/class-archive-migrator.php';

set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		if ( ! ( error_reporting() & $severity ) ) {
			return false;
		}
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$checks_passed = 0;

function assert_check( $id, $condition, $message ) {
	global $checks_passed;
	if ( ! $condition ) {
		throw new RuntimeException( "[{$id}] {$message}" );
	}
	$checks_passed++;
	echo "[PASS] {$id} - {$message}\n";
}

function quote_identifier( $identifier ) {
	return '`' . str_replace( '`', '``', $identifier ) . '`';
}

function db_query( $db, $sql, $context ) {
	$result = $db->query( $sql );
	if ( $result === false || $db->last_error ) {
		throw new RuntimeException( "{$context}: {$db->last_error}" );
	}
	return $result;
}

function ensure_options_table( $db ) {
	$table = quote_identifier( $db->prefix . 'options' );
	db_query(
		$db,
		"CREATE TABLE IF NOT EXISTS {$table} (
			option_id bigint unsigned NOT NULL AUTO_INCREMENT,
			option_name varchar(191) NOT NULL DEFAULT '',
			option_value longtext NOT NULL,
			autoload varchar(20) NOT NULL DEFAULT 'yes',
			PRIMARY KEY (option_id),
			UNIQUE KEY option_name (option_name)
		) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
		'create options table'
	);
}

function archive_table_names( $db ) {
	$tables = $db->get_col(
		$db->prepare(
			"SELECT TABLE_NAME FROM information_schema.tables
			 WHERE table_schema = DATABASE() AND table_name LIKE %s
			 ORDER BY TABLE_NAME",
			$db->esc_like( $db->prefix . 'ghca_acd_archive_' ) . '%'
		)
	);
	if ( $db->last_error ) {
		throw new RuntimeException( 'list archive tables: ' . $db->last_error );
	}
	return $tables;
}

function clear_archive_state( $db ) {
	foreach ( archive_table_names( $db ) as $table ) {
		if ( strpos( $table, $db->prefix . 'ghca_acd_archive_' ) !== 0 ) {
			throw new RuntimeException( 'refusing to drop table outside archive namespace' );
		}
		db_query( $db, 'DROP TABLE ' . quote_identifier( $table ), 'drop disposable archive table' );
	}
	ensure_options_table( $db );
	$options = quote_identifier( $db->prefix . 'options' );
	db_query(
		$db,
		"DELETE FROM {$options} WHERE option_name IN (
			'ghca_acd_archive_schema_version',
			'ghca_acd_archive_enabled',
			'ghca_acd_archive_dual_layer'
		)",
		'clear disposable archive options'
	);
}

function expected_schema( $db ) {
	return GHCA_ACD_Archive_Schema::get_schema( $db->prefix, $db->get_charset_collate() );
}

function assert_rejected( $id, $migrator, $expected_code, $message ) {
	$result = $migrator->postflight_verify( expected_schema( $GLOBALS['wpdb'] ) );
	assert_check( $id, $result === false && $migrator->get_last_error_code() === $expected_code, $message );
}

function add_claimable_index( $db ) {
	$table = quote_identifier( $db->prefix . 'ghca_acd_archive_tasks' );
	db_query( $db, "ALTER TABLE {$table} ADD INDEX claimable (task_state,available_at_gmt,lease_until_gmt)", 'restore claimable index' );
}

function feature_flags_are_off( $db ) {
	$table = quote_identifier( $db->prefix . 'options' );
	$rows  = $db->get_results(
		"SELECT option_name, option_value FROM {$table}
		 WHERE option_name IN ('ghca_acd_archive_enabled','ghca_acd_archive_dual_layer')"
	);
	if ( $db->last_error ) {
		return false;
	}
	foreach ( $rows as $row ) {
		if ( $row->option_value !== '0' ) {
			return false;
		}
	}
	return true;
}

function new_connection( $username, $password ) {
	global $host, $name;
	$connection = new wpdb( $username, $password, $name, $host );
	$connection->set_prefix( 'wp_' );
	$connection->charset = 'utf8mb4';
	$connection->collate = 'utf8mb4_unicode_ci';
	$connection->set_charset( $connection->dbh, $connection->charset, $connection->collate );
	if ( ! empty( $connection->error ) ) {
		throw new RuntimeException( 'could not open independent disposable database connection' );
	}
	return $connection;
}

final class GHCA_ACD_Test_Version_Read_Failing_WPDB {
	public $prefix;
	public $last_error = '';
	private $inner;

	public function __construct( $inner ) {
		$this->inner  = $inner;
		$this->prefix = $inner->prefix;
	}

	public function get_row( $query ) {
		if ( strpos( $query, GHCA_ACD_Archive_Schema::SCHEMA_VERSION_OPTION ) !== false ) {
			$this->last_error = 'injected schema-version read failure';
			return null;
		}
		$result           = $this->inner->get_row( $query );
		$this->last_error = $this->inner->last_error;
		return $result;
	}

	public function __call( $method, $arguments ) {
		$result           = call_user_func_array( array( $this->inner, $method ), $arguments );
		$this->last_error = $this->inner->last_error;
		return $result;
	}
}

function mutating_queries_since( $db, $offset ) {
	$mutating = array();
	foreach ( array_slice( $db->queries, $offset ) as $entry ) {
		$sql = is_array( $entry ) ? $entry[0] : $entry;
		if ( preg_match( '/^\s*(CREATE|ALTER|DROP|RENAME|TRUNCATE|INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql ) ) {
			$mutating[] = $sql;
		}
	}
	return $mutating;
}

function production_has_destructive_uninstall_path() {
	$plugin_root = dirname( __DIR__, 2 );
	if ( is_file( $plugin_root . '/uninstall.php' ) ) {
		return true;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_root ) );
	foreach ( $iterator as $file ) {
		$path = str_replace( '\\', '/', $file->getPathname() );
		if ( ! $file->isFile() || substr( $path, -4 ) !== '.php' || strpos( $path, '/tests/' ) !== false ) {
			continue;
		}
		$contents = file_get_contents( $file->getPathname() );
		if ( stripos( $contents, 'register_uninstall_hook' ) !== false || preg_match( '/\bDROP\s+TABLE\b/i', $contents ) ) {
			return true;
		}
	}
	return false;
}

function run_tests() {
	global $wpdb, $host, $user, $pass, $name, $restricted_user, $restricted_pass, $checks_passed;

	$database = $wpdb->get_row( 'SELECT VERSION() AS version, @@version_comment AS version_comment' );
	assert_check( 'SCHEMA-ENV-REAL-DBDELTA', strpos( str_replace( '\\', '/', ( new ReflectionFunction( 'dbDelta' ) )->getFileName() ), '/wp-admin/includes/upgrade.php' ) !== false, 'real WordPress dbDelta is loaded' );
	$expected_targets = array(
		'33061' => array( '8.0.', 'mysql' ),
		'33062' => array( '8.4.', 'mysql' ),
		'33063' => array( '10.6.', 'mariadb' ),
	);
	$port     = substr( strrchr( $host, ':' ), 1 );
	$target   = $expected_targets[ $port ];
	$is_target = strpos( strtolower( $database->version . ' ' . $database->version_comment ), $target[1] ) !== false;
	assert_check( 'SCHEMA-ENV-TARGET', strpos( $database->version, $target[0] ) === 0 && $is_target, "declared target {$host} matches {$database->version}" );

	clear_archive_state( $wpdb );
	$migrator = new GHCA_ACD_Archive_Migrator( $wpdb );
	$fresh_result = $migrator->migrate();
	assert_check( 'SCHEMA-FRESH-MIGRATE', $fresh_result, 'fresh standard wp_ migration succeeds; code=' . (string) $migrator->get_last_error_code() );
	assert_check( 'SCHEMA-FRESH-VERSION', $migrator->get_schema_version() === GHCA_ACD_Archive_Schema::CURRENT_VERSION, 'schema version advances after postflight' );
	assert_check( 'SCHEMA-FRESH-TABLES', count( archive_table_names( $wpdb ) ) === 13, 'exact 13-table namespace exists' );
	assert_check( 'SCHEMA-FRESH-POSTFLIGHT', $migrator->postflight_verify( expected_schema( $wpdb ) ), 'fresh schema passes fixed-manifest postflight' );
	$all_standard = true;
	foreach ( archive_table_names( $wpdb ) as $table ) {
		$all_standard = $all_standard && strpos( $table, 'wp_ghca_acd_archive_' ) === 0;
	}
	assert_check( 'SCHEMA-PREFIX-WP', $all_standard, 'standard wp_ prefix is applied to every archive table' );

	$query_offset = count( $wpdb->queries );
	assert_check( 'SCHEMA-NOOP-RESULT', $migrator->migrate(), 'repeat migration succeeds' );
	assert_check( 'SCHEMA-NOOP-NO-MUTATION', mutating_queries_since( $wpdb, $query_offset ) === array(), 'repeat migration performs no DDL or data mutation' );

	$wpdb->set_prefix( 'custom_wp_' );
	ensure_options_table( $wpdb );
	clear_archive_state( $wpdb );
	$custom = new GHCA_ACD_Archive_Migrator( $wpdb );
	assert_check( 'SCHEMA-PREFIX-CUSTOM', $custom->migrate() && count( archive_table_names( $wpdb ) ) === 13, 'arbitrary custom_wp_ prefix migrates independently' );
	clear_archive_state( $wpdb );
	db_query( $wpdb, 'DROP TABLE ' . quote_identifier( $wpdb->prefix . 'options' ), 'drop disposable custom options table' );
	$wpdb->set_prefix( 'wp_' );
	$migrator = new GHCA_ACD_Archive_Migrator( $wpdb );

	$tasks = quote_identifier( $wpdb->prefix . 'ghca_acd_archive_tasks' );
	db_query( $wpdb, "ALTER TABLE {$tasks} DROP INDEX claimable", 'drop claimable index' );
	assert_check( 'SCHEMA-DBDELTA-INDEX-REPAIR', $migrator->migrate() && $migrator->postflight_verify( expected_schema( $wpdb ) ), 'real dbDelta restores a missing additive index' );

	clear_archive_state( $wpdb );
	$partial = array_slice( expected_schema( $wpdb ), 0, 5, true );
	foreach ( $partial as $sql ) {
		$result = dbDelta( $sql );
		if ( ! is_array( $result ) || $wpdb->last_error ) {
			throw new RuntimeException( 'could not create interrupted partial schema' );
		}
	}
	assert_check( 'SCHEMA-PARTIAL-STATE', count( archive_table_names( $wpdb ) ) === 5 && ( new GHCA_ACD_Archive_Migrator( $wpdb ) )->get_schema_version() === null, 'interrupted partial installation has no advanced version' );
	$query_offset = count( $wpdb->queries );
	$migrator     = new GHCA_ACD_Archive_Migrator( $wpdb );
	assert_check( 'SCHEMA-PARTIAL-CONVERGENCE', $migrator->migrate() && count( archive_table_names( $wpdb ) ) === 13, 'partial installation converges additively on rerun' );
	$destructive = array_filter( mutating_queries_since( $wpdb, $query_offset ), static function ( $sql ) { return preg_match( '/^\s*(DROP|RENAME|TRUNCATE)\b/i', $sql ); } );
	assert_check( 'SCHEMA-PARTIAL-NONDESTRUCTIVE', $destructive === array(), 'partial convergence performs no destructive repair' );

	clear_archive_state( $wpdb );
	$options = quote_identifier( $wpdb->prefix . 'options' );
	db_query( $wpdb, "INSERT INTO {$options} (option_name,option_value,autoload) VALUES ('ghca_acd_archive_enabled','1','yes'),('ghca_acd_archive_dual_layer','1','yes')", 'pre-enable disposable flags' );
	$checkpoints = quote_identifier( $wpdb->prefix . 'ghca_acd_archive_checkpoints' );
	db_query( $wpdb, "CREATE TABLE {$checkpoints} (id int) ENGINE=MyISAM", 'create malformed partial table' );
	$migrator = new GHCA_ACD_Archive_Migrator( $wpdb );
	$failed   = $migrator->migrate();
	assert_check( 'SCHEMA-FAIL-PARTIAL', $failed === false, 'malformed partial DDL fails closed' );
	assert_check( 'SCHEMA-FAIL-VERSION', $migrator->get_schema_version() === null, 'failed migration leaves schema version unadvanced' );
	assert_check( 'SCHEMA-FAIL-FLAGS', feature_flags_are_off( $wpdb ), 'failed migration persists both feature flags off' );
	clear_archive_state( $wpdb );
	$migrator = new GHCA_ACD_Archive_Migrator( $wpdb );
	assert_check( 'SCHEMA-RECOVER-CLEAN', $migrator->migrate(), 'clean disposable state migrates after failure test cleanup' );

	$events = quote_identifier( $wpdb->prefix . 'ghca_acd_archive_events' );
	$extra  = quote_identifier( $wpdb->prefix . 'ghca_acd_archive_extra' );
	db_query( $wpdb, "CREATE TABLE {$extra} (id int) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", 'create extra archive table' );
	assert_rejected( 'SCHEMA-POST-EXTRA-TABLE', $migrator, 'schema_table_set_mismatch', 'extra archive table is rejected' );
	db_query( $wpdb, "DROP TABLE {$extra}", 'remove extra archive table' );

	db_query( $wpdb, "ALTER TABLE {$events} DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci", 'alter table collation' );
	assert_rejected( 'SCHEMA-POST-TABLE-COLLATION', $migrator, 'schema_table_collation_mismatch', 'wrong table collation is rejected' );
	db_query( $wpdb, "ALTER TABLE {$events} DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", 'restore table collation' );
	db_query( $wpdb, "ALTER TABLE {$events} MODIFY event_id char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL", 'alter machine-key collation' );
	assert_rejected( 'SCHEMA-POST-MACHINE-COLLATION', $migrator, 'schema_column_collation_mismatch', 'wrong machine-key collation is rejected' );
	db_query( $wpdb, "ALTER TABLE {$events} MODIFY event_id char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL", 'restore machine-key collation' );
	db_query( $wpdb, "ALTER TABLE {$events} MODIFY reason_text text CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL", 'alter human-text collation' );
	assert_rejected( 'SCHEMA-POST-HUMAN-COLLATION', $migrator, 'schema_column_collation_mismatch', 'wrong human-text collation is rejected' );
	db_query( $wpdb, "ALTER TABLE {$events} MODIFY reason_text text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL", 'restore human-text collation' );
	db_query( $wpdb, "ALTER TABLE {$events} MODIFY reason_code varchar(63) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL", 'alter varchar length' );
	assert_rejected( 'SCHEMA-POST-LENGTH', $migrator, 'schema_column_type_mismatch', 'column length mismatch is rejected' );
	db_query( $wpdb, "ALTER TABLE {$events} MODIFY reason_code varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL", 'restore varchar length' );

	db_query( $wpdb, "ALTER TABLE {$tasks} DROP COLUMN last_error_code", 'drop required column' );
	assert_rejected( 'SCHEMA-POST-MISSING-COLUMN', $migrator, 'schema_column_count_mismatch', 'missing column is rejected' );
	db_query( $wpdb, "ALTER TABLE {$tasks} ADD COLUMN last_error_code varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER lease_until_gmt", 'restore required column' );
	db_query( $wpdb, "ALTER TABLE {$tasks} ADD COLUMN extra_col int", 'add extra column' );
	assert_rejected( 'SCHEMA-POST-EXTRA-COLUMN', $migrator, 'schema_column_count_mismatch', 'extra column is rejected' );
	db_query( $wpdb, "ALTER TABLE {$tasks} DROP COLUMN extra_col", 'remove extra column' );
	db_query( $wpdb, "ALTER TABLE {$tasks} MODIFY last_error_code varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL FIRST", 'move column' );
	assert_rejected( 'SCHEMA-POST-COLUMN-ORDER', $migrator, 'schema_column_order_mismatch', 'column order mismatch is rejected' );
	db_query( $wpdb, "ALTER TABLE {$tasks} MODIFY last_error_code varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER lease_until_gmt", 'restore column order' );
	db_query( $wpdb, "ALTER TABLE {$tasks} MODIFY last_error_code varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' AFTER lease_until_gmt", 'alter nullability' );
	assert_rejected( 'SCHEMA-POST-NULLABILITY', $migrator, 'schema_column_nullability_mismatch', 'column nullability mismatch is rejected' );
	db_query( $wpdb, "ALTER TABLE {$tasks} MODIFY last_error_code varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER lease_until_gmt", 'restore nullability' );
	db_query( $wpdb, "ALTER TABLE {$tasks} MODIFY last_error_code varchar(64) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS ('generated') STORED", 'make generated column' );
	assert_rejected( 'SCHEMA-POST-GENERATED', $migrator, 'schema_generated_column_prohibited', 'generated column is rejected' );
	db_query( $wpdb, "ALTER TABLE {$tasks} MODIFY last_error_code varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER lease_until_gmt", 'restore non-generated column' );

	$ledger = quote_identifier( $wpdb->prefix . 'ghca_acd_archive_ledger_items' );
	db_query( $wpdb, "ALTER TABLE {$ledger} MODIFY ledger_item_id bigint NOT NULL AUTO_INCREMENT", 'drop unsigned modifier' );
	assert_rejected( 'SCHEMA-POST-SIGNEDNESS', $migrator, 'schema_column_type_mismatch', 'signedness mismatch is rejected' );
	db_query( $wpdb, "ALTER TABLE {$ledger} MODIFY ledger_item_id bigint unsigned NOT NULL AUTO_INCREMENT", 'restore unsigned modifier' );
	db_query( $wpdb, "ALTER TABLE {$events} MODIFY event_row_id bigint unsigned NOT NULL", 'drop auto increment' );
	assert_rejected( 'SCHEMA-POST-AUTO-INCREMENT', $migrator, 'schema_column_auto_increment_mismatch', 'missing AUTO_INCREMENT is rejected' );
	db_query( $wpdb, "ALTER TABLE {$events} MODIFY event_row_id bigint unsigned NOT NULL AUTO_INCREMENT", 'restore auto increment' );
	$streams = quote_identifier( $wpdb->prefix . 'ghca_acd_archive_streams' );
	db_query( $wpdb, "ALTER TABLE {$streams} ALTER COLUMN head_sequence SET DEFAULT 1", 'alter counter default' );
	assert_rejected( 'SCHEMA-POST-DEFAULT', $migrator, 'schema_column_default_mismatch', 'wrong column default is rejected' );
	db_query( $wpdb, "ALTER TABLE {$streams} ALTER COLUMN head_sequence SET DEFAULT 0", 'restore counter default' );

	db_query( $wpdb, "ALTER TABLE {$events} ENGINE=MyISAM", 'alter storage engine' );
	assert_rejected( 'SCHEMA-POST-ENGINE', $migrator, 'schema_engine_mismatch', 'wrong storage engine is rejected' );
	db_query( $wpdb, "ALTER TABLE {$events} ENGINE=InnoDB", 'restore storage engine' );

	db_query( $wpdb, "ALTER TABLE {$tasks} DROP INDEX claimable", 'drop required index' );
	assert_rejected( 'SCHEMA-POST-MISSING-INDEX', $migrator, 'schema_index_set_mismatch', 'missing index is rejected' );
	add_claimable_index( $wpdb );
	db_query( $wpdb, "ALTER TABLE {$tasks} DROP INDEX claimable, ADD UNIQUE INDEX claimable (task_state,available_at_gmt,lease_until_gmt)", 'make index unique' );
	assert_rejected( 'SCHEMA-POST-INDEX-UNIQUE', $migrator, 'schema_index_uniqueness_mismatch', 'index uniqueness mismatch is rejected' );
	db_query( $wpdb, "ALTER TABLE {$tasks} DROP INDEX claimable", 'drop malformed unique index' );
	add_claimable_index( $wpdb );
	db_query( $wpdb, "ALTER TABLE {$tasks} DROP INDEX claimable, ADD INDEX claimable (available_at_gmt,task_state,lease_until_gmt)", 'reorder index columns' );
	assert_rejected( 'SCHEMA-POST-INDEX-ORDER', $migrator, 'schema_index_order_mismatch', 'index column order mismatch is rejected' );
	db_query( $wpdb, "ALTER TABLE {$tasks} DROP INDEX claimable", 'drop reordered index' );
	add_claimable_index( $wpdb );
	db_query( $wpdb, "ALTER TABLE {$tasks} DROP INDEX claimable, ADD INDEX claimable (task_state(4),available_at_gmt,lease_until_gmt)", 'prefix index column' );
	assert_rejected( 'SCHEMA-POST-INDEX-PREFIX', $migrator, 'schema_index_prefix_mismatch', 'index prefix mismatch is rejected' );
	db_query( $wpdb, "ALTER TABLE {$tasks} DROP INDEX claimable", 'drop prefixed index' );
	add_claimable_index( $wpdb );
	if ( stripos( $database->version . ' ' . $database->version_comment, 'mariadb' ) !== false ) {
		db_query( $wpdb, "ALTER TABLE {$tasks} ALTER INDEX claimable IGNORED", 'ignore required index' );
		assert_rejected( 'SCHEMA-POST-INDEX-USABLE', $migrator, 'schema_index_not_usable', 'ignored required index is rejected' );
		db_query( $wpdb, "ALTER TABLE {$tasks} ALTER INDEX claimable NOT IGNORED", 'restore required index visibility' );
	} else {
		db_query( $wpdb, "ALTER TABLE {$tasks} ALTER INDEX claimable INVISIBLE", 'hide required index' );
		assert_rejected( 'SCHEMA-POST-INDEX-USABLE', $migrator, 'schema_index_not_usable', 'invisible required index is rejected' );
		db_query( $wpdb, "ALTER TABLE {$tasks} ALTER INDEX claimable VISIBLE", 'restore required index visibility' );
	}
	db_query( $wpdb, "ALTER TABLE {$tasks} ADD INDEX extra_index (task_state)", 'add extra index' );
	assert_rejected( 'SCHEMA-POST-EXTRA-INDEX', $migrator, 'schema_index_set_mismatch', 'extra index is rejected' );
	db_query( $wpdb, "ALTER TABLE {$tasks} DROP INDEX extra_index", 'remove extra index' );
	db_query( $wpdb, "ALTER TABLE {$events} ADD CONSTRAINT ghca_acd_test_fk FOREIGN KEY (stream_id) REFERENCES {$streams} (stream_id)", 'add prohibited foreign key' );
	assert_rejected( 'SCHEMA-POST-FOREIGN-KEY', $migrator, 'schema_constraint_prohibited', 'foreign key is rejected' );
	db_query( $wpdb, "ALTER TABLE {$events} DROP FOREIGN KEY ghca_acd_test_fk", 'remove prohibited foreign key' );
	db_query( $wpdb, "ALTER TABLE {$streams} ADD CONSTRAINT ghca_acd_test_check CHECK (head_sequence >= 0)", 'add prohibited check constraint' );
	assert_rejected( 'SCHEMA-POST-CHECK', $migrator, 'schema_constraint_prohibited', 'CHECK constraint is rejected' );
	if ( stripos( $database->version . ' ' . $database->version_comment, 'mariadb' ) !== false ) {
		db_query( $wpdb, "ALTER TABLE {$streams} DROP CONSTRAINT ghca_acd_test_check", 'remove prohibited check constraint' );
	} else {
		db_query( $wpdb, "ALTER TABLE {$streams} DROP CHECK ghca_acd_test_check", 'remove prohibited check constraint' );
	}
	$trigger = quote_identifier( $wpdb->prefix . 'ghca_acd_archive_test_trigger' );
	db_query( $wpdb, "DROP TRIGGER IF EXISTS {$trigger}", 'clear prior test trigger' );
	db_query( $wpdb, "CREATE TRIGGER {$trigger} BEFORE INSERT ON {$tasks} FOR EACH ROW SET NEW.task_state = NEW.task_state", 'add prohibited trigger' );
	assert_rejected( 'SCHEMA-POST-TRIGGER', $migrator, 'schema_trigger_prohibited', 'trigger is rejected' );
	db_query( $wpdb, "DROP TRIGGER {$trigger}", 'remove prohibited trigger' );
	assert_check( 'SCHEMA-POST-RESTORED', $migrator->postflight_verify( expected_schema( $wpdb ) ), 'all structural mutations restore to the exact manifest' );
	$query_offset = count( $wpdb->queries );
	$read_failure = new GHCA_ACD_Archive_Migrator( new GHCA_ACD_Test_Version_Read_Failing_WPDB( $wpdb ) );
	assert_check( 'SCHEMA-VERSION-READ-FAIL-CLOSED', $read_failure->migrate() === false && $read_failure->get_last_error_code() === 'schema_version_read_failed' && mutating_queries_since( $wpdb, $query_offset ) === array(), 'schema-version read failure aborts before DDL' );

	$database_name = $wpdb->get_var( 'SELECT DATABASE()' );
	$lock_name     = 'ghca_acd_archive_' . substr( hash( 'sha256', $database_name . '|wp_' ), 0, 32 );
	$held          = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
	$second        = new_connection( $user, $pass );
	$root          = $wpdb;
	$wpdb          = $second;
	$contender     = new GHCA_ACD_Archive_Migrator( $second );
	$contended     = $contender->migrate();
	$wpdb          = $root;
	$released      = $root->get_var( $root->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
	$second->close();
	assert_check( 'SCHEMA-CONCURRENCY-LOCK', (string) $held === '1' && $contended === false && $contender->get_last_error_code() === 'schema_lock_unavailable' && (string) $released === '1', 'second independent connection cannot enter a held migration lock' );

	$method = new ReflectionMethod( 'GHCA_ACD_Archive_Migrator', 'database_is_supported' );
	if ( PHP_VERSION_ID < 80100 ) {
		$method->setAccessible( true );
	}
	assert_check( 'SCHEMA-VENDOR-MYSQL', $method->invoke( $migrator, '8.0.36-0ubuntu0.22.04.1', 'MySQL Community Server - GPL' ), 'decorated supported MySQL version is parsed correctly' );
	assert_check( 'SCHEMA-VENDOR-MARIADB', $method->invoke( $migrator, '10.6.27-MariaDB-ubu2204', 'mariadb.org binary distribution' ), 'decorated supported MariaDB version is parsed correctly' );
	assert_check( 'SCHEMA-VENDOR-MYSQL-OLD', ! $method->invoke( $migrator, '5.7.44-log', 'MySQL Community Server - GPL' ), 'unsupported MySQL version is rejected' );
	assert_check( 'SCHEMA-VENDOR-MARIADB-OLD', ! $method->invoke( $migrator, '10.5.29-MariaDB', 'mariadb.org binary distribution' ), 'unsupported MariaDB version is rejected' );
	assert_check( 'SCHEMA-VENDOR-UNKNOWN', ! $method->invoke( $migrator, '8.4.1-compatible', 'Unknown Database' ), 'unknown vendor is rejected' );

	db_query( $wpdb, "ALTER TABLE {$tasks} DROP INDEX claimable", 'prepare restricted-user DDL failure' );
	$user_literal = $wpdb->prepare( '%s', $restricted_user );
	$pass_literal = $wpdb->prepare( '%s', $restricted_pass );
	db_query( $wpdb, "DROP USER IF EXISTS {$user_literal}@'%'", 'drop prior restricted test user' );
	db_query( $wpdb, "CREATE USER {$user_literal}@'%' IDENTIFIED BY {$pass_literal}", 'create restricted test user' );
	db_query( $wpdb, 'GRANT SELECT ON ' . quote_identifier( $name ) . ".* TO {$user_literal}@'%'", 'grant restricted test privileges' );
	$restricted = new_connection( $restricted_user, $restricted_pass );
	$root       = $wpdb;
	$wpdb       = $restricted;
	$restricted_migrator = new GHCA_ACD_Archive_Migrator( $restricted );
	$restricted_result   = $restricted_migrator->migrate();
	$restricted_code     = $restricted_migrator->get_last_error_code();
	$wpdb = $root;
	$restricted->close();
	db_query( $wpdb, "DROP USER IF EXISTS {$user_literal}@'%'", 'drop restricted test user' );
	assert_check( 'SCHEMA-PRIVILEGE-REAL', $restricted_result === false && $restricted_code === 'schema_ddl_failed', 'real SELECT-only user cannot perform additive DDL' );
	assert_check( 'SCHEMA-PRIVILEGE-SAFE-STATE', $migrator->get_schema_version() === GHCA_ACD_Archive_Schema::CURRENT_VERSION && feature_flags_are_off( $wpdb ), 'privilege failure preserves version and disabled flags' );
	add_claimable_index( $wpdb );

	$tables_before = archive_table_names( $wpdb );
	$destructive_uninstall = production_has_destructive_uninstall_path();
	$tables_after = archive_table_names( $wpdb );
	assert_check( 'SCHEMA-UNINSTALL-RETAINED', ! $destructive_uninstall && $tables_before === $tables_after && count( $tables_after ) === 13, 'production contains no archive-table uninstall deletion path' );

	clear_archive_state( $wpdb );
	$usermeta = quote_identifier( $wpdb->prefix . 'usermeta' );
	db_query(
		$wpdb,
		"CREATE TABLE IF NOT EXISTS {$usermeta} (
			umeta_id bigint unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint unsigned NOT NULL DEFAULT 0,
			meta_key varchar(255) DEFAULT NULL,
			meta_value longtext,
			PRIMARY KEY (umeta_id),
			KEY user_id (user_id),
			KEY meta_key (meta_key(191))
		) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
		'create sanitized legacy usermeta fixture'
	);
	db_query( $wpdb, "DELETE FROM {$usermeta} WHERE user_id = 987654321", 'clear prior legacy fixture rows' );
	if ( $wpdb->insert( $wpdb->prefix . 'usermeta', array( 'user_id' => 987654321, 'meta_key' => 'course_completed_101', 'meta_value' => '1710000000' ) ) === false ) {
		throw new RuntimeException( 'insert legacy fixture row one failed' );
	}
	if ( $wpdb->insert( $wpdb->prefix . 'usermeta', array( 'user_id' => 987654321, 'meta_key' => 'learndash_course_progress', 'meta_value' => 'sanitized-fixture' ) ) === false ) {
		throw new RuntimeException( 'insert legacy fixture row two failed' );
	}
	$before = json_encode( $wpdb->get_results( "SELECT user_id,meta_key,meta_value FROM {$usermeta} WHERE user_id=987654321 ORDER BY umeta_id" ) );
	$query_offset = count( $wpdb->queries );
	$migrator = new GHCA_ACD_Archive_Migrator( $wpdb );
	$legacy_migrated = $migrator->migrate();
	$migration_queries = array_slice( $wpdb->queries, $query_offset );
	$after = json_encode( $wpdb->get_results( "SELECT user_id,meta_key,meta_value FROM {$usermeta} WHERE user_id=987654321 ORDER BY umeta_id" ) );
	$legacy_queries = array_filter( $migration_queries, static function ( $entry ) { $sql = is_array( $entry ) ? $entry[0] : $entry; return stripos( $sql, 'usermeta' ) !== false; } );
	assert_check( 'SCHEMA-LEGACY-UNCHANGED', $legacy_migrated && $before === $after && $legacy_queries === array(), 'sanitized legacy user metadata is untouched' );
	$event_count   = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . quote_identifier( $wpdb->prefix . 'ghca_acd_archive_events' ) );
	$command_count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . quote_identifier( $wpdb->prefix . 'ghca_acd_archive_commands' ) );
	assert_check( 'SCHEMA-LEGACY-NO-FABRICATION', (string) $event_count === '0' && (string) $command_count === '0', 'legacy migration fabricates no events or command receipts' );
	db_query( $wpdb, "DELETE FROM {$usermeta} WHERE user_id = 987654321", 'remove sanitized legacy fixture rows' );
	assert_check( 'SCHEMA-FINAL-POSTFLIGHT', $migrator->postflight_verify( expected_schema( $wpdb ) ), 'final database state passes exact postflight' );

	echo "DB_TARGET={$database->version}|{$database->version_comment}\n";
	echo "CHECKS_PASSED={$checks_passed}\n";
}

try {
	run_tests();
	exit( 0 );
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
