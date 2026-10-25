<?php
/** Disposable MySQL integration check for the additive OLTL schema. */

$wp_root = dirname( __DIR__, 4 );
if ( ! is_file( $wp_root . '/wp-load.php' ) || ! extension_loaded( 'mysqli' ) ) {
	echo "SKIP: WordPress wpdb or mysqli is unavailable\n";
	exit( 0 );
}

define( 'WP_INSTALLING', true );
$_SERVER['REQUEST_SCHEME'] = 'http';
$_SERVER['HTTP_HOST']      = 'localhost';
require_once $wp_root . '/wp-load.php';

mysqli_report( MYSQLI_REPORT_OFF );
$server = @new mysqli( '127.0.0.1', 'root', '' );
if ( $server->connect_errno ) {
	echo "SKIP: disposable local MySQL is unavailable\n";
	exit( 0 );
}

$database = 'ghca_oltl_test_' . getmypid() . '_' . bin2hex( random_bytes( 3 ) );
if ( ! $server->query( "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" ) ) {
	echo "SKIP: disposable database could not be created\n";
	exit( 0 );
}

$wpdb = new wpdb( 'root', '', $database, '127.0.0.1' );
$wpdb->set_prefix( 'wp_' );
$wpdb->query( "CREATE TABLE {$wpdb->options} (option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,option_name varchar(191) NOT NULL,option_value longtext NOT NULL,autoload varchar(20) NOT NULL DEFAULT 'no',PRIMARY KEY(option_id),UNIQUE KEY option_name(option_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
wp_cache_flush();
require_once __DIR__ . '/../includes/oltl/class-oltl-schema.php';
require_once __DIR__ . '/../includes/oltl/class-oltl-readiness.php';

$fails = 0;
function oltl_schema_check( bool $condition, string $message ): void {
	global $fails;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$fails += $condition ? 0 : 1;
}

try {
	$reviews = GHCA_ACD_OLTL_Schema::reviews_table();
	$events  = GHCA_ACD_OLTL_Schema::events_table();
	oltl_schema_check( GHCA_ACD_OLTL_Schema::install(), 'OLTL schema installs through WordPress dbDelta' );
	oltl_schema_check( GHCA_ACD_OLTL_Schema::tables_exist(), 'both immutable-review and append-only event tables exist' );
	oltl_schema_check( 1 === (int) get_option( GHCA_ACD_OLTL_Schema::OPTION_VERSION, 0 ), 'schema version advances only after physical verification' );
	$engines = $wpdb->get_col( "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$database}' AND TABLE_NAME IN ('{$reviews}','{$events}')" );
	oltl_schema_check( 2 === count( $engines ) && array() === array_values( array_filter( $engines, static function ( $engine ) { return 'innodb' !== strtolower( (string) $engine ); } ) ), 'OLTL workflow tables use transactional InnoDB storage' );

	$wpdb->query( "ALTER TABLE {$reviews} DROP COLUMN rationale" );
	update_option( GHCA_ACD_OLTL_Schema::OPTION_VERSION, 0, false );
	wp_cache_flush();
	oltl_schema_check( GHCA_ACD_OLTL_Schema::install() && is_array( $wpdb->get_row( "SHOW COLUMNS FROM {$reviews} LIKE 'rationale'", ARRAY_A ) ), 'interrupted additive migration resumes and restores a required column' );
	oltl_schema_check( GHCA_ACD_OLTL_Schema::install(), 'OLTL schema reinstall remains idempotent' );

	$first = GHCA_ACD_OLTL_Readiness::record_manual_review( 15, 'satisfied', 'Participant-specific service training', '2026-09-01', 'Required by the service plan', 'protected-reference-1', null, 9 );
	$second = GHCA_ACD_OLTL_Readiness::record_manual_review( 15, 'not_applicable', 'Updated applicability review', '2026-09-02', 'Service no longer applies', 'protected-reference-2', null, 9 );
	$third = GHCA_ACD_OLTL_Readiness::revoke_manual_review( 15, 'Reviewer withdrew the determination.', 9 );
	$revisions = $wpdb->get_results( "SELECT id,revision_no,decision,supersedes_id FROM {$reviews} WHERE employee_user_id=15 ORDER BY revision_no", ARRAY_A );
	oltl_schema_check( is_int( $first ) && is_int( $second ) && is_int( $third ) && 3 === count( $revisions ) && array( 1, 2, 3 ) === array_map( 'intval', array_column( $revisions, 'revision_no' ) ), 'manual corrections and revocation append immutable revisions' );
	oltl_schema_check( (int) $revisions[1]['supersedes_id'] === (int) $revisions[0]['id'] && (int) $revisions[2]['supersedes_id'] === (int) $revisions[1]['id'] && 'revoked' === $revisions[2]['decision'], 'manual revision chain records exact supersession and revocation state' );
	oltl_schema_check( 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events} WHERE employee_user_id=15" ), 'every committed manual lifecycle revision has an append-only event' );

	$before_failure = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$reviews}" );
	$wpdb->query( "CREATE TRIGGER ghca_test_oltl_event_fail BEFORE INSERT ON {$events} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='controlled event failure'" );
	$previous_suppression = $wpdb->suppress_errors( true );
	$failed = GHCA_ACD_OLTL_Readiness::record_manual_review( 16, 'satisfied', 'Controlled failure', '2026-09-02', 'Test rollback', 'protected-reference-3', null, 9 );
	$wpdb->suppress_errors( $previous_suppression );
	oltl_schema_check( is_wp_error( $failed ) && $before_failure === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$reviews}" ), 'manual review rolls back when its mandatory event cannot be stored' );
	$wpdb->query( 'DROP TRIGGER ghca_test_oltl_event_fail' );
} finally {
	if ( isset( $wpdb ) && $wpdb instanceof wpdb ) {
		$wpdb->close();
	}
	$server->query( "DROP DATABASE IF EXISTS `{$database}`" );
	$server->close();
}

exit( $fails ? 1 : 0 );
