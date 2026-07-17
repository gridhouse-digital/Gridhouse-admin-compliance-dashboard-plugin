<?php
/**
 * Shared bootstrap for destructive disposable-database persistence tests.
 * Never loads wp-config.php, wp-load.php, or current-site credentials.
 */

$host   = getenv( 'GHCA_TEST_DB_HOST' );
$user   = getenv( 'GHCA_TEST_DB_USER' );
$pass   = getenv( 'GHCA_TEST_DB_PASSWORD' );
$name   = getenv( 'GHCA_TEST_DB_NAME' );
$opt_in = getenv( 'GHCA_TEST_DESTRUCTIVE_OPT_IN' );

$required = array(
	'GHCA_TEST_DB_HOST'            => $host,
	'GHCA_TEST_DB_USER'            => $user,
	'GHCA_TEST_DB_PASSWORD'        => $pass,
	'GHCA_TEST_DB_NAME'            => $name,
	'GHCA_TEST_DESTRUCTIVE_OPT_IN' => $opt_in,
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

function ghca_persist_new_connection() {
	global $host, $user, $pass, $name;
	$connection = new wpdb( $user, $pass, $name, $host );
	if ( ! empty( $connection->error ) ) {
		fwrite( STDERR, "FAIL: Could not connect to disposable database\n" );
		exit( 1 );
	}
	$connection->set_prefix( 'wp_' );
	$connection->charset = 'utf8mb4';
	$connection->collate = 'utf8mb4_unicode_ci';
	$connection->set_charset( $connection->dbh, $connection->charset, $connection->collate );
	$connection->suppress_errors( true );
	return $connection;
}

global $wpdb;
$wpdb = ghca_persist_new_connection();

require_once ABSPATH . 'wp-includes/class-wp-walker.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once __DIR__ . '/../../includes/archive/class-archive-migrator.php';

// Kernel classes, fixtures, and the strict check/exception harness.
require_once __DIR__ . '/bootstrap.php';

// Slice 1B persistence layer under test.
$persistence_root = dirname( __DIR__, 2 ) . '/includes/archive';
require_once $persistence_root . '/infrastructure/class-archive-persistence-exception.php';
require_once $persistence_root . '/infrastructure/class-archive-db-format.php';
require_once $persistence_root . '/contracts/interface-archive-event-store.php';
require_once $persistence_root . '/infrastructure/class-wpdb-archive-event-store.php';
require_once $persistence_root . '/infrastructure/class-wpdb-archive-command-store.php';
require_once $persistence_root . '/infrastructure/class-wpdb-archive-task-store.php';
require_once $persistence_root . '/infrastructure/class-wpdb-archive-snapshot-store.php';
require_once $persistence_root . '/infrastructure/class-wpdb-archive-artifact-repository.php';
require_once $persistence_root . '/infrastructure/class-wpdb-archive-projection-repository.php';
require_once $persistence_root . '/infrastructure/class-archive-case-projector.php';
require_once $persistence_root . '/infrastructure/class-archive-revision-projector.php';
require_once $persistence_root . '/infrastructure/class-archive-reset-projector.php';
require_once $persistence_root . '/infrastructure/class-archive-projector.php';
require_once $persistence_root . '/application/class-archive-unit-of-work.php';

function ghca_persist_quote_identifier( $identifier ) {
	return '`' . str_replace( '`', '``', $identifier ) . '`';
}

function ghca_persist_query( $db, $sql, $context ) {
	$result = $db->query( $sql );
	if ( $result === false || $db->last_error ) {
		throw new RuntimeException( "{$context}: {$db->last_error}" );
	}
	return $result;
}

function ghca_persist_table_names( $db ) {
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

function ghca_persist_ensure_options_table( $db ) {
	$table = ghca_persist_quote_identifier( $db->prefix . 'options' );
	ghca_persist_query(
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

function ghca_persist_fresh_schema( $db ) {
	foreach ( ghca_persist_table_names( $db ) as $table ) {
		if ( strpos( $table, $db->prefix . 'ghca_acd_archive_' ) !== 0 ) {
			throw new RuntimeException( 'refusing to drop table outside archive namespace' );
		}
		ghca_persist_query( $db, 'DROP TABLE ' . ghca_persist_quote_identifier( $table ), 'drop disposable archive table' );
	}
	ghca_persist_ensure_options_table( $db );
	$options = ghca_persist_quote_identifier( $db->prefix . 'options' );
	ghca_persist_query(
		$db,
		"DELETE FROM {$options} WHERE option_name IN (
			'ghca_acd_archive_schema_version',
			'ghca_acd_archive_enabled',
			'ghca_acd_archive_dual_layer'
		)",
		'clear disposable archive options'
	);
	$migrator = new GHCA_ACD_Archive_Migrator( $db );
	if ( ! $migrator->migrate() ) {
		throw new RuntimeException( 'disposable schema migration failed: ' . (string) $migrator->get_last_error_code() );
	}
}

/** Deterministic fixed clock for the persistence layer under test. */
final class GHCA_Persist_Fixed_Clock implements GHCA_ACD_Archive_Clock {
	/** @var string */
	private $now;

	public function __construct( string $now ) {
		$this->now = $now;
	}

	public function now_gmt(): string {
		return $this->now;
	}

	public function set( string $now ): void {
		$this->now = $now;
	}
}

/** Deterministic, process-unique sequential identifier generator. */
final class GHCA_Persist_Sequential_Ids implements GHCA_ACD_Archive_Id_Generator {
	/** @var int */
	private static $counter = 0;
	/** @var string */
	private $namespace;

	public function __construct( string $namespace ) {
		$this->namespace = $namespace;
	}

	public function generate(): string {
		self::$counter++;
		return substr( hash( 'sha256', 'ghca-persist-id|' . $this->namespace . '|' . self::$counter ), 0, 32 );
	}
}

/**
 * Failure-injection and interleaving proxy around a real wpdb connection.
 * Hooks match a method plus an SQL/table substring; an action of 'fail'
 * injects a database error, while a callable runs before the real call
 * (used to interleave a second real connection mid-transaction).
 */
final class GHCA_Persist_DB_Proxy {
	/** @var string */
	public $prefix;
	/** @var string */
	public $last_error = '';
	/** @var wpdb */
	private $inner;
	/** @var array<int,array<string,mixed>> */
	private $hooks = array();

	public function __construct( $inner ) {
		$this->inner  = $inner;
		$this->prefix = $inner->prefix;
	}

	/** @param string|callable $action 'fail' or a pre-call callable. */
	public function add_hook( string $method, string $match, $action, int $times = 1 ): void {
		$this->hooks[] = array( 'method' => $method, 'match' => $match, 'action' => $action, 'remaining' => $times );
	}

	public function clear_hooks(): void {
		$this->hooks = array();
	}

	private function fire( string $method, string $subject ): bool {
		foreach ( $this->hooks as $index => $hook ) {
			if ( $hook['remaining'] < 1 || $hook['method'] !== $method || false === strpos( $subject, $hook['match'] ) ) {
				continue;
			}
			$this->hooks[ $index ]['remaining']--;
			if ( 'fail' === $hook['action'] ) {
				$this->last_error = 'injected persistence failure';
				return true;
			}
			call_user_func( $hook['action'] );
		}
		return false;
	}

	public function prepare( $query, ...$args ) {
		return $this->inner->prepare( $query, ...$args );
	}

	public function insert( $table, $data, $format = null ) {
		if ( $this->fire( 'insert', (string) $table ) ) {
			return false;
		}
		$result           = $this->inner->insert( $table, $data, $format );
		$this->last_error = $this->inner->last_error;
		return $result;
	}

	public function query( $sql ) {
		if ( $this->fire( 'query', (string) $sql ) ) {
			return false;
		}
		$result           = $this->inner->query( $sql );
		$this->last_error = $this->inner->last_error;
		return $result;
	}

	public function get_row( $sql, $output = OBJECT ) {
		if ( $this->fire( 'get_row', (string) $sql ) ) {
			return null;
		}
		$result           = $this->inner->get_row( $sql, $output );
		$this->last_error = $this->inner->last_error;
		return $result;
	}

	public function get_results( $sql, $output = OBJECT ) {
		if ( $this->fire( 'get_results', (string) $sql ) ) {
			return array();
		}
		$result           = $this->inner->get_results( $sql, $output );
		$this->last_error = $this->inner->last_error;
		return $result;
	}

	/** @param array<int,mixed> $arguments @return mixed */
	public function __call( string $method, array $arguments ) {
		$result           = call_user_func_array( array( $this->inner, $method ), $arguments );
		$this->last_error = $this->inner->last_error;
		return $result;
	}

	/** @return mixed */
	public function __get( string $property ) {
		return $this->inner->$property;
	}
}

/**
 * Build a complete persistence stack over one database handle.
 *
 * @param wpdb|GHCA_Persist_DB_Proxy $db
 * @return array<string,mixed>
 */
function ghca_persist_stack( $db, string $now = '2026-07-16T12:00:00Z', string $id_namespace = 'stack' ) {
	$event_store   = new GHCA_ACD_WPDB_Archive_Event_Store( $db );
	$command_store = new GHCA_ACD_WPDB_Archive_Command_Store( $db );
	$task_store    = new GHCA_ACD_WPDB_Archive_Task_Store( $db );
	$snapshot_store = new GHCA_ACD_WPDB_Archive_Snapshot_Store( $db );
	$artifact_repository = new GHCA_ACD_WPDB_Archive_Artifact_Repository( $db );
	$repository    = new GHCA_ACD_WPDB_Archive_Projection_Repository( $db );
	$projector     = new GHCA_ACD_Archive_Projector( $repository );
	$clock         = new GHCA_Persist_Fixed_Clock( $now );
	$ids           = new GHCA_Persist_Sequential_Ids( $id_namespace );
	$uow           = new GHCA_ACD_Archive_Unit_Of_Work( $db, $event_store, $command_store, $task_store, $snapshot_store, $artifact_repository, $projector, $clock, $ids );
	return array(
		'db'            => $db,
		'event_store'   => $event_store,
		'command_store' => $command_store,
		'task_store'    => $task_store,
		'snapshot_store' => $snapshot_store,
		'artifact_repository' => $artifact_repository,
		'repository'    => $repository,
		'projector'     => $projector,
		'clock'         => $clock,
		'ids'           => $ids,
		'uow'           => $uow,
	);
}

/**
 * Deterministic fingerprint of every archive table so tests can prove that a
 * failed command left zero residue anywhere.
 */
function ghca_persist_db_fingerprint( $db ): string {
	$dump = array();
	foreach ( ghca_persist_table_names( $db ) as $table ) {
		$rows = $db->get_results( 'SELECT * FROM ' . ghca_persist_quote_identifier( $table ), ARRAY_A );
		if ( $db->last_error ) {
			throw new RuntimeException( 'fingerprint read failed: ' . $db->last_error );
		}
		$normalized = array();
		foreach ( (array) $rows as $row ) {
			ksort( $row, SORT_STRING );
			$normalized[] = $row;
		}
		usort( $normalized, static function ( $a, $b ) {
			return strcmp( json_encode( $a ), json_encode( $b ) );
		} );
		$dump[ $table ] = $normalized;
	}
	return hash( 'sha256', json_encode( $dump ) );
}

/**
 * Assert an exact persistence/domain failure with zero database residue.
 *
 * @param callable $callback
 */
function persist_expect_failure( $db, callable $callback, string $expected_class, string $expected_reason, string $message ): void {
	$before = ghca_persist_db_fingerprint( $db );
	$caught = null;
	$result = null;
	try {
		$result = $callback();
	} catch ( Throwable $error ) {
		$caught = $error;
	}
	archive_check( null !== $caught, $message . ' throws' );
	archive_check( null === $result, $message . ' returns no successful response' );
	archive_check( null !== $caught && get_class( $caught ) === $expected_class, $message . ' uses exact class ' . $expected_class . ( null === $caught ? '' : ' [caught ' . get_class( $caught ) . ': ' . $caught->getMessage() . ']' ) );
	$reason = null;
	if ( $caught instanceof GHCA_ACD_Archive_Persistence_Exception || $caught instanceof GHCA_ACD_Archive_Transition_Exception ) {
		$reason = $caught->reason_code();
	}
	archive_check( $expected_reason === $reason, $message . ' exposes reason ' . $expected_reason . ' [got ' . var_export( $reason, true ) . ']' );
	archive_check( $before === ghca_persist_db_fingerprint( $db ), $message . ' leaves zero database residue' );
}
